<?php
/**
 * Special:QQConnectLogin — the OAuth callback and flow-control special page.
 *
 * Responsibilities:
 *  1. In test mode: show a notice page (the button must be visible for QQ
 *     review, but no real OAuth runs).
 *  2. Otherwise, redirect the browser to QQ's authorize endpoint.
 *  3. Receive the QQ callback (?code=...&state=...), verify state, exchange
 *     the code for a token, fetch identity (openid + unionid) + userinfo.
 *  4. If the QQ is already bound to a MediaWiki user: stash the username and
 *     redirect back into the AuthManager login flow (the primary provider's
 *     continuePrimaryAuthentication returns newPass).
 *  5. If unbound: show a "choose" page (create new account / link existing).
 *     - "Create": stash pending QQ identity in the session, redirect to
 *       Special:CreateAccount with the username pre-filled. The
 *       LocalUserCreated hook completes the binding after normal account
 *       creation (so ConfirmEdit/AntiSpoof/TitleBlacklist all run).
 *     - "Link": show a username+password form; verify via AuthManager (so
 *       OATHAuth etc. apply), then write the binding and log the user in.
 *
 * The page must be reachable by anonymous users (login flow), so requiresLogin
 * returns false. If the wiki uses $wgWhitelistRead, the admin should add
 * Special:QQConnectLogin to it.
 */

namespace MediaWiki\Extension\QQConnect\Special;

use HTMLForm;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Extension\QQConnect\Auth\QQPrimaryAuthenticationProvider as P;
use MediaWiki\Extension\QQConnect\QQClient;
use MediaWiki\Extension\QQConnect\QQConnectConfig;
use MediaWiki\Extension\QQConnect\QQConnectException;
use MediaWiki\Extension\QQConnect\QQStore;
use MediaWiki\Extension\QQConnect\Util\UsernameCleaner;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Psr\Log\LoggerInterface;
use StatusValue;

class SpecialQQConnectLogin extends SpecialPage {

	/** @var QQConnectConfig */
	private $config;

	/** @var QQClient */
	private $client;

	/** @var QQStore */
	private $store;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		QQConnectConfig $config,
		QQClient $client,
		QQStore $store
	) {
		parent::__construct( 'QQConnectLogin' );
		$this->config = $config;
		$this->client = $client;
		$this->store = $store;
		$this->logger = LoggerFactory::getInstance( 'qqconnect' );
	}

	protected function getGroupName() {
		return 'login';
	}

	/**
	 * This page must be reachable by anonymous users for the login flow.
	 *
	 * @return bool
	 */
	public function requiresLogin() {
		return false;
	}

	/**
	 * Main entry point. Dispatches based on the subpage / query parameters.
	 *
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkReadOnly();
		$this->getOutput()->addModuleStyles( 'ext.QQConnect.styles' );

		$request = $this->getRequest();
		$authManager = MediaWikiServices::getInstance()->getAuthManager();

		// If a link-existing-account flow just completed successfully, the
		// HTMLForm post-submit redirect lands here (without ?action=link).
		// Show the success page rather than falling through to startFlow().
		$linkSuccess = $authManager->getAuthenticationSessionData( 'QQConnect:linkSuccess', null );
		if ( $linkSuccess !== null ) {
			$authManager->removeAuthenticationSessionData( 'QQConnect:linkSuccess' );
			$out = $this->getOutput();
			$out->setPageTitleMsg( $this->msg( 'qqconnect-special-manage-title' ) );
			$out->addWikiMsg( 'qqconnect-manage-bind-success' );
			$out->addReturnTo( SpecialPage::getTitleFor( 'QQConnect' ) );
			return;
		}

		// If there is a ?code= parameter, this is the OAuth callback.
		if ( $request->getRawVal( 'code' ) !== null ) {
			$this->handleCallback();
			return;
		}

		// -----------------------------------------------------------------
		//  Form-submission routing via hidden marker fields.
		//  HTMLForm's action URL is the bare page title (no query params),
		//  so we cannot rely on ?action=… for POST requests.  Hidden fields
		//  survive the POST and let execute() route correctly.
		// -----------------------------------------------------------------
		$qqFlow = $request->getRawVal( '__qqconnect_flow' );

		if ( $qqFlow === 'link' ) {
			$this->handleLinkForm();
			return;
		}

		if ( $qqFlow === 'verify-bind' ) {
			$this->handleVerifyBind();
			return;
		}

		// An explicit ?action=test shows the test notice.
		if ( $subPage === 'test' || $request->getRawVal( 'action' ) === 'test' ) {
			$this->showTestMode();
			return;
		}

		// ?action=link shows the link-existing-account form.
		if ( $request->getRawVal( 'action' ) === 'link' ) {
			$this->handleLinkForm();
			return;
		}

		// ?action=create redirects to Special:CreateAccount with prefilled name.
		if ( $request->getRawVal( 'action' ) === 'create' ) {
			$this->handleCreateRedirect();
			return;
		}

		// ?action=verify-bind shows the 2FA verification form for the
		// authenticated-user bind flow (Flow 2).
		if ( $request->getRawVal( 'action' ) === 'verify-bind' ) {
			$this->handleVerifyBind();
			return;
		}

		// If the link-existing-account flow is in progress (step 2: waiting
		// for 2FA input), HTMLForm's post-submit redirect may have landed
		// here without ?action=link. Redirect back to the link form so the
		// 2FA step is rendered instead of falling through to startFlow().
		$linkAuthState = $authManager->getAuthenticationSessionData(
			'QQConnect:linkAuthState', null
		);
		if ( $linkAuthState !== null ) {
			$this->getOutput()->redirect(
				$this->getPageTitle()->getLocalURL( [ 'action' => 'link' ] )
			);
			return;
		}

		// Same guard for the authenticated-user bind flow (Flow 2): when
		// the 2FA form is re-rendered after a failed TOTP attempt,
		// HTMLForm redirects here without ?action=verify-bind.
		$pendingBind = $authManager->getAuthenticationSessionData(
			'QQConnect:pendingBind', null
		);
		if ( $pendingBind !== null ) {
			$this->getOutput()->redirect(
				$this->getPageTitle()->getLocalURL( [ 'action' => 'verify-bind' ] )
			);
			return;
		}

		// Default: start the OAuth flow (or show test mode).
		$this->startFlow();
	}

	/**
	 * Begin the flow.
	 *
	 * There are two legitimate entry points:
	 *  1. The AuthManager login flow: the user clicked the "Login with QQ"
	 *     button on Special:Userlogin, the primary provider returned
	 *     newRedirect to THIS page, and AuthManager stored AUTHN_STATE +
	 *     SESSION_KEY_RETURNTO. In this case we initiate the OAuth redirect.
	 *  2. A logged-in user binding/rebinding from Special:QQConnect: the
	 *     bindMode flag is set, and we initiate the OAuth redirect.
	 *
	 * If neither applies (e.g. an anon navigated here directly from the
	 * personal menu), redirect to Special:Userlogin so the QQ button on the
	 * login form properly starts the AuthManager flow.
	 */
	private function startFlow() {
		if ( $this->config->isTestMode() || !$this->config->isConfigured() ) {
			$this->showTestMode();
			return;
		}

		// Defensive check: if the user is already logged in (which can
		// happen when a link-bind succeeded and HTMLForm's redirect landed
		// here), do NOT start a new QQ OAuth flow.  Instead, redirect to
		// the QQ management page so the user sees their newly-bound QQ.
		if ( $this->getUser()->isRegistered() ) {
			$this->getOutput()->redirect(
				SpecialPage::getTitleFor( 'QQConnect' )->getFullURL()
			);
			return;
		}

		$authManager = MediaWikiServices::getInstance()->getAuthManager();
		$bindMode = $authManager->getAuthenticationSessionData( 'QQConnect:bindMode', null );

		// If there is no AuthManager login state and no bind mode, check for
		// a ?returnto= URL parameter (used by AjaxLogin). If present, stash
		// it so we behave as if the flow was started from Special:Userlogin.
		$hasAuthState = $authManager->getAuthenticationSessionData( P::SESSION_KEY_RETURNTO, '' ) !== '';
		if ( !$hasAuthState && $bindMode === null ) {
			$returntoUrl = $this->getRequest()->getVal( 'returnto' );
			if ( $returntoUrl ) {
				$authManager->setAuthenticationSessionData(
					P::SESSION_KEY_RETURNTO, $returntoUrl
				);
			} else {
				$this->getOutput()->redirect(
					SpecialPage::getTitleFor( 'Userlogin' )->getFullURL()
				);
				return;
			}
		}

		$state = $this->client->generateState();
		$redirectUri = $this->getRedirectUri();

		// Stash state for verification on callback.
		$authManager->setAuthenticationSessionData( P::SESSION_KEY_STATE, $state );
		$authManager->setAuthenticationSessionData( P::SESSION_KEY_REDIRECT, $redirectUri );

		// Force the session to be persisted before the browser is redirected
		// to QQ. Without this, some session backends may not flush the state
		// in time for the OAuth callback, causing "invalid state" errors.
		// @see https://phabricator.wikimedia.org/T147161 (session race with redirect)
		$session = $this->getRequest()->getSession();
		if ( method_exists( $session, 'save' ) ) {
			$session->save();
		}

		$authorizeUrl = $this->client->getAuthorizeUrl( $redirectUri, $state );
		$this->logger->info( 'Redirecting user to QQ authorize endpoint', [
			'sessionId' => $session->getId(),
		] );
		$this->getOutput()->redirect( $authorizeUrl );
	}

	/**
	 * Handle the OAuth callback (?code=...&state=...).
	 */
	private function handleCallback() {
		$request = $this->getRequest();
		$authManager = MediaWikiServices::getInstance()->getAuthManager();
		$session = $request->getSession();

		// Verify state to prevent CSRF.
		$expectedState = $authManager->getAuthenticationSessionData( P::SESSION_KEY_STATE, '' );
		$gotState = $request->getRawVal( 'state', '' );

		if ( $expectedState === '' || !hash_equals( (string)$expectedState, (string)$gotState ) ) {
			// Log diagnostic info to help debug session-loss issues.
			$this->logger->warning( 'QQ callback state mismatch', [
				'expectedEmpty' => ( $expectedState === '' ),
				'gotEmpty' => ( $gotState === '' ),
				'stateLenExpected' => strlen( (string)$expectedState ),
				'stateLenGot' => strlen( (string)$gotState ),
				'sessionId' => $session->getId(),
				'sessionPersist' => $session->isPersistent(),
				'hasReturnto' => $authManager->getAuthenticationSessionData( P::SESSION_KEY_RETURNTO, '' ) !== '',
			] );
			$debugInfo = null;
			if ( $this->config->isDebugMode() ) {
				$debugInfo = "state validation failed\n"
					. "expected state present: " . ( $expectedState === '' ? 'no' : 'yes (len=' . strlen( (string)$expectedState ) . ')' ) . "\n"
					. "state from QQ present: " . ( $gotState === '' ? 'no' : 'yes (len=' . strlen( (string)$gotState ) . ')' ) . "\n"
					. "session ID: " . $session->getId() . "\n"
					. "session persist: " . ( $session->isPersistent() ? 'yes' : 'no' ) . "\n"
					. "has returnTo URL: " . ( $authManager->getAuthenticationSessionData( P::SESSION_KEY_RETURNTO, '' ) !== '' ? 'yes' : 'no' );
			}
			$this->showError( 'qqconnect-error-invalid-state', $debugInfo );
			return;
		}
		// Consume the state only after successful verification.
		$authManager->removeAuthenticationSessionData( P::SESSION_KEY_STATE );

		$redirectUri = $authManager->getAuthenticationSessionData( P::SESSION_KEY_REDIRECT, '' );
		if ( $redirectUri === '' ) {
			$redirectUri = $this->getRedirectUri();
		}

		$code = $request->getRawVal( 'code' );
		if ( $code === null || $code === '' ) {
			$debugInfo = null;
			if ( $this->config->isDebugMode() ) {
				$debugInfo = "no authorization code in callback URL\n"
					. "this usually means QQ denied authorization or had an internal error";
			}
			$this->showError( 'qqconnect-error-no-code', $debugInfo );
			return;
		}

		// Perform the OAuth round-trip.
		try {
			$tokenData = $this->client->exchangeCodeForToken( $code, $redirectUri );
			$accessToken = $tokenData['access_token'];
			$identity = $this->client->fetchIdentity( $accessToken );
			$unionid = $identity['unionid'];
			$openidForApi = $identity['openid'];
			$userInfo = $this->client->getUserInfo( $accessToken, $openidForApi );
		} catch ( QQConnectException $e ) {
			$this->logger->error( 'QQ OAuth failed at {stage}: {msg}', [
				'stage' => $e->getStage(),
				'msg' => $e->getMessage(),
			] );
			$debugInfo = null;
			if ( $this->config->isDebugMode() ) {
				$debugInfo = $e->getDebugMessage();
			}
			$this->showError( $e->getErrorMessageKey(), $debugInfo );
			return;
		}

		$nickname = (string)( $userInfo['nickname'] ?? '' );
		$avatar = QQClient::pickAvatar( $userInfo );
		$appid = $this->config->getAppId();

		// The OAuth round-trip succeeded — the browser has returned from QQ.
		// SESSION_KEY_RETURNTO was set by beginPrimaryAuthentication to
		// remember the AuthManager continuation URL.  It is no longer
		// needed and MUST be cleared now; otherwise a subsequent
		// accidental hit of startFlow() (e.g. after a successful link-bind
		// whose HTMLForm redirect lands here) would re-trigger a new QQ
		// redirect because $hasAuthState would still be true.
		$authManager->removeAuthenticationSessionData( P::SESSION_KEY_RETURNTO );

		// Check for a "bind mode" flag (user is logged in and initiating a
		// bind/rebind from Special:QQConnect).
		$bindMode = $authManager->getAuthenticationSessionData( 'QQConnect:bindMode', null );

		// Is this QQ already bound to a MediaWiki user?
		$binding = $this->store->findBindingByUnionid( $unionid );

		if ( $bindMode !== null ) {
			// Bind/rebind flow initiated by a logged-in user.
			$this->handleBindModeCallback( $bindMode, $unionid, $appid, $nickname, $avatar, $binding );
			return;
		}

		if ( $binding ) {
			// Already bound: log the user in via the AuthManager login flow.
			$userId = (int)$binding['qqc_user'];
			$user = MediaWikiServices::getInstance()->getUserFactory()->newFromId( $userId );
			$username = $user->getName();
			$authManager->setAuthenticationSessionData( P::SESSION_KEY_RESULT, [
				'username' => $username,
			] );
			$this->resumeLoginFlow();
			return;
		}

		// Unbound QQ: stash pending identity and show the choose page.
		$authManager->setAuthenticationSessionData( P::SESSION_KEY_PENDING, [
			'unionid' => $unionid,
			'appid' => $appid,
			'nickname' => $nickname,
			'avatar' => $avatar,
		] );
		$this->showChoosePage( $nickname );
	}

	/**
	 * Handle the callback when a logged-in user is binding/rebinding.
	 *
	 * If the user has a secondary authentication provider enabled (e.g.
	 * OATHAuth), we redirect to ?action=verify-bind to prompt for TOTP
	 * before completing the binding. Otherwise the bind is done directly.
	 *
	 * @param string $bindMode 'bind' or 'rebind'
	 * @param string $unionid
	 * @param string $appid
	 * @param string $nickname
	 * @param string $avatar
	 * @param array|null $existingBinding
	 */
	private function handleBindModeCallback(
		string $bindMode,
		string $unionid,
		string $appid,
		string $nickname,
		string $avatar,
		?array $existingBinding
	) {
		$authManager = MediaWikiServices::getInstance()->getAuthManager();
		$authManager->removeAuthenticationSessionData( 'QQConnect:bindMode' );
		$user = $this->getUser();

		if ( !$user->isRegistered() ) {
			$this->showError( 'qqconnect-error-invalid-state' );
			return;
		}

		// The QQ must not be bound to a *different* user.
		if ( $existingBinding && (int)$existingBinding['qqc_user'] !== $user->getId() ) {
			$this->showError( 'qqconnect-error-openid-bound-other' );
			return;
		}

		// Determine whether the user has a secondary provider that must be
		// satisfied (e.g. OATHAuth).  We use AuthManager's ACTION_LINK
		// because that action runs secondary providers for a logged-in user
		// without demanding password re-entry.
		$linkReqs = $authManager->getAuthenticationRequests(
			AuthManager::ACTION_LINK, $user
		);

		// Keep only requests that have actual form fields (TOTP, etc.).
		$secondaryReqs = array_filter( $linkReqs, static function ( $req ) {
			return $req->getFieldInfo() !== [];
		} );

		if ( $secondaryReqs ) {
			// 2FA is active — stash bind data and redirect to the
			// verification page.
			$authManager->setAuthenticationSessionData( 'QQConnect:pendingBind', [
				'bindMode' => $bindMode,
				'unionid' => $unionid,
				'appid' => $appid,
				'nickname' => $nickname,
				'avatar' => $avatar,
				'existingBinding' => $existingBinding,
			] );
			$authManager->setAuthenticationSessionData(
				'QQConnect:bindSecondaryReqs', $secondaryReqs
			);
			$this->getOutput()->redirect(
				$this->getPageTitle()->getLocalURL( [ 'action' => 'verify-bind' ] )
			);
			return;
		}

		// No secondary provider → bind directly.
		$this->executeBind(
			$bindMode, $user, $unionid, $appid, $nickname, $avatar, $existingBinding
		);
	}

	/**
	 * Show the "create new account / link existing account" choice page.
	 *
	 * @param string $nickname
	 */
	private function showChoosePage( string $nickname ) {
		$out = $this->getOutput();
		$out->setPageTitleMsg( $this->msg( 'qqconnect-choose-title' ) );

		$pending = $this->getPending();
		if ( $pending === null ) {
			$this->showError( 'qqconnect-error-invalid-state' );
			return;
		}

		$out->addWikiMsg( 'qqconnect-choose-intro', $nickname );

		// Two separate links styled as buttons (simpler and more robust than
		// a multi-button HTMLForm).
		$createUrl = $this->getPageTitle()->getLocalURL( [ 'action' => 'create' ] );
		$linkUrl = $this->getPageTitle()->getLocalURL( [ 'action' => 'link' ] );

		$html = '<div class="qqconnect-choose">';
		$html .= '<p class="qqconnect-choose-nickname">'
			. $this->msg( 'qqconnect-choose-nickname' )->params( htmlspecialchars( $nickname ) )->escaped()
			. '</p>';
		$html .= '<div class="qqconnect-choose-option">';
		$html .= '<a class="mw-ui-button mw-ui-progressive qqconnect-btn qqconnect-btn-create" href="'
			. htmlspecialchars( $createUrl ) . '">'
			. $this->msg( 'qqconnect-choose-create' )->escaped() . '</a>';
		$html .= '<p class="qqconnect-choose-desc">'
			. $this->msg( 'qqconnect-choose-create-desc' )->escaped() . '</p>';
		$html .= '</div>';
		$html .= '<div class="qqconnect-choose-option">';
		$html .= '<a class="mw-ui-button qqconnect-btn qqconnect-btn-link" href="'
			. htmlspecialchars( $linkUrl ) . '">'
			. $this->msg( 'qqconnect-choose-link' )->escaped() . '</a>';
		$html .= '<p class="qqconnect-choose-desc">'
			. $this->msg( 'qqconnect-choose-link-desc' )->escaped() . '</p>';
		$html .= '</div>';
		$html .= '</div>';

		$out->addHTML( $html );
	}

	/**
	 * Redirect to Special:CreateAccount with the username prefilled. The
	 * pending QQ identity stays in the session and is consumed by the
	 * LocalUserCreated hook after successful registration.
	 */
	private function handleCreateRedirect() {
		$pending = $this->getPending();
		if ( $pending === null ) {
			$this->showError( 'qqconnect-error-invalid-state' );
			return;
		}
		// Pre-fill a username derived from the QQ nickname.
		$cleaned = UsernameCleaner::clean( $pending['nickname'] ?? '' );
		if ( $cleaned === '' ) {
			$cleaned = UsernameCleaner::generateFromUnionid( $pending['unionid'] );
		}
		$title = SpecialPage::getTitleFor( 'CreateAccount' );
		$url = $title->getFullURL( [
			'wpName' => $cleaned,
			'from' => 'qqconnect',
		] );
		$this->getOutput()->redirect( $url );
	}

	/**
	 * Show the "link to existing account" form.
	 *
	 * Multi-step: step 1 = username + password; step 2 (if 2FA) = OATHAuth
	 * fields obtained from AuthManager's continue-flow.
	 */
	private function handleLinkForm() {
		$pending = $this->getPending();
		if ( $pending === null ) {
			$this->showError( 'qqconnect-error-invalid-state' );
			return;
		}

		$authManager = MediaWikiServices::getInstance()->getAuthManager();
		$linkAuthState = $authManager->getAuthenticationSessionData( 'QQConnect:linkAuthState', null );

		$out = $this->getOutput();

		if ( $linkAuthState !== null ) {
			// Step 2: secondary authentication (e.g. OATHAuth TOTP).
			$out->setPageTitleMsg( $this->msg( 'qqconnect-link-form-title' ) );
			$out->addWikiMsg( 'qqconnect-link-form-2fa-intro',
				$pending['nickname'] ?? $pending['unionid'] );

			$formDescriptor = $linkAuthState['neededFields'];
			$formDescriptor['linkstep'] = [
				'type' => 'hidden',
				'name' => 'linkstep',
				'default' => 'continue',
			];
			$formDescriptor['__qqconnect_flow'] = [
				'type' => 'hidden',
				'name' => '__qqconnect_flow',
				'default' => 'link',
			];
		} else {
			// Step 1: username + password.
			$out->setPageTitleMsg( $this->msg( 'qqconnect-link-form-title' ) );
			$out->addWikiMsg( 'qqconnect-link-form-intro',
				$pending['nickname'] ?? $pending['unionid'] );

			$formDescriptor = [
				'username' => [
					'type' => 'text',
					'name' => 'username',
					'label-message' => 'qqconnect-link-form-username',
					'required' => true,
				],
				'password' => [
					'type' => 'password',
					'name' => 'password',
					'label-message' => 'qqconnect-link-form-password',
					'required' => true,
				],
				'linkstep' => [
					'type' => 'hidden',
					'name' => 'linkstep',
					'default' => 'initial',
				],
				'__qqconnect_flow' => [
					'type' => 'hidden',
					'name' => '__qqconnect_flow',
					'default' => 'link',
				],
			];
		}

		$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$form->setSubmitTextMsg( 'qqconnect-link-form-submit' );
		$form->setSubmitCallback( [ $this, 'onLinkSubmit' ] );
		$form->show();
	}

	/**
	 * Handle link-form submission (both step 1 and step 2).
	 *
	 * @param array $data
	 * @return StatusValue
	 */
	public function onLinkSubmit( array $data ) {
		$pending = $this->getPending();
		if ( $pending === null ) {
			return StatusValue::newFatal( 'qqconnect-error-invalid-state' );
		}

		$authManager = MediaWikiServices::getInstance()->getAuthManager();
		$step = $data['linkstep'] ?? 'initial';

		// -----------------------------------------------------------------
		//  Step 2: continue after secondary (2FA) input
		// -----------------------------------------------------------------
		if ( $step === 'continue' ) {
			$linkAuthState = $authManager->getAuthenticationSessionData(
				'QQConnect:linkAuthState', null
			);
			if ( $linkAuthState === null ) {
				return StatusValue::newFatal( 'qqconnect-error-invalid-state' );
			}

			$reqs = $authManager->getAuthenticationRequests( AuthManager::ACTION_LOGIN );
			$loadedReqs = AuthenticationRequest::loadRequestsFromSubmission( $reqs, $data );
			$response = $authManager->continueAuthentication( $loadedReqs );

			if ( $response->status === AuthenticationResponse::PASS ) {
				$authManager->removeAuthenticationSessionData( 'QQConnect:linkAuthState' );
				return $this->completeLinkBind(
					$authManager, $pending, $response->username
				);
			}

			if ( $response->status === AuthenticationResponse::UI ) {
				// Still need more input (e.g. backup recovery code after
				// failed TOTP). Update the stored field descriptors and
				// re-render the 2FA form.
				$neededFields = [];
				foreach ( $response->neededRequests as $req ) {
					foreach ( $req->getFieldInfo() as $fieldName => $fieldInfo ) {
						$neededFields[$fieldName] = $fieldInfo;
					}
				}
				$authManager->setAuthenticationSessionData( 'QQConnect:linkAuthState', [
					'neededFields' => $neededFields,
				] );
				$this->getRequest()->getSession()->save();
				return StatusValue::newGood();
			}

			$authManager->removeAuthenticationSessionData( 'QQConnect:linkAuthState' );
			return StatusValue::newFatal( 'qqconnect-error-auth-failed' );
		}

		// -----------------------------------------------------------------
		//  Step 1: verify username + password via beginAuthentication
		// -----------------------------------------------------------------
		$username = trim( $data['username'] ?? '' );
		$password = $data['password'] ?? '';
		if ( $username === '' || $password === '' ) {
			return StatusValue::newFatal( 'qqconnect-error-auth-failed' );
		}

		$reqs = $authManager->getAuthenticationRequests( AuthManager::ACTION_LOGIN );
		$loadedReqs = AuthenticationRequest::loadRequestsFromSubmission( $reqs, [
			'username' => $username,
			'password' => $password,
		] );

		$response = $authManager->beginAuthentication(
			$loadedReqs,
			$this->getFullTitle()->getFullURL()
		);

		if ( $response->status === AuthenticationResponse::PASS ) {
			return $this->completeLinkBind(
				$authManager, $pending, $response->username
			);
		}

		if ( $response->status === AuthenticationResponse::UI ) {
			// Credentials are correct but a secondary provider (e.g.
			// OATHAuth) requires additional input. Extract the needed
			// form fields and stash them so handleLinkForm renders the
			// 2FA step.
			$neededFields = [];
			foreach ( $response->neededRequests as $req ) {
				foreach ( $req->getFieldInfo() as $fieldName => $fieldInfo ) {
					$neededFields[$fieldName] = $fieldInfo;
				}
			}
			$authManager->setAuthenticationSessionData( 'QQConnect:linkAuthState', [
				'neededFields' => $neededFields,
			] );
			// Force session persist before HTMLForm redirect.
			$this->getRequest()->getSession()->save();
			// Return good so HTMLForm redirects to the same page; on the
			// next render handleLinkForm detects linkAuthState and shows
			// the 2FA form.
			return StatusValue::newGood();
		}

		// REDIRECT or FAIL.
		return StatusValue::newFatal( 'qqconnect-error-auth-failed' );
	}

	/**
	 * Complete the bind after AuthManager has authenticated the user (either
	 * in step 1 with no 2FA, or step 2 after 2FA).
	 *
	 * @param AuthManager $authManager
	 * @param array $pending
	 * @param string $username Authenticated username.
	 * @return StatusValue
	 */
	private function completeLinkBind( $authManager, array $pending, string $username ): StatusValue {
		$boundUser = User::newFromName( $username );
		if ( !$boundUser || !$boundUser->getId() ) {
			return StatusValue::newFatal( 'qqconnect-error-auth-failed' );
		}
		if ( $this->store->unionidIsBound( $pending['unionid'] ) ) {
			return StatusValue::newFatal( 'qqconnect-error-openid-bound-other' );
		}
		if ( $this->store->userIsBound( $boundUser->getId() ) ) {
			return StatusValue::newFatal( 'qqconnect-error-user-bound-other' );
		}
		$ok = $this->store->bind(
			$boundUser->getId(),
			$pending['unionid'],
			$pending['appid'],
			$pending['nickname'] ?? '',
			$pending['avatar'] ?? ''
		);
		if ( !$ok ) {
			return StatusValue::newFatal( 'qqconnect-error-bind-failed' );
		}
		$authManager->removeAuthenticationSessionData( P::SESSION_KEY_PENDING );
		$authManager->setAuthenticationSessionData(
			'QQConnect:linkSuccess', $boundUser->getName()
		);
		$this->getRequest()->getSession()->save();
		return StatusValue::newGood();
	}

	/**
	 * Show the test-mode notice page.
	 */
	private function showTestMode() {
		$out = $this->getOutput();
		$out->setPageTitleMsg( $this->msg( 'qqconnect-test-mode-title' ) );
		$out->addWikiMsg( 'qqconnect-test-mode-body' );
	}

	/**
	 * Show an error message.
	 *
	 * @param string $messageKey
	 * @param string|null $debugInfo Safe debug details shown only when
	 *   $wgQQConnectDebug is true. Must NOT contain tokens or secrets.
	 */
	private function showError( string $messageKey, ?string $debugInfo = null ) {
		$out = $this->getOutput();
		$out->setPageTitleMsg( $this->msg( 'qqconnect-special-login-title' ) );
		$out->addWikiMsg( $messageKey );
		if ( $debugInfo !== null && $this->config->isDebugMode() ) {
			$out->addHTML(
				'<div class="qqconnect-debug-info" style="margin-top:1.5em;padding:0.8em;'
				. 'background:#fef6e7;border:1px solid #fc3;font-size:0.875em;">'
				. '<strong>' . $this->msg( 'qqconnect-debug-title' )->escaped() . '</strong>'
				. '<pre style="margin:0.5em 0 0 0;white-space:pre-wrap;word-break:break-all;">'
				. htmlspecialchars( $debugInfo )
				. '</pre></div>'
			);
		}
		$out->addReturnTo( Title::newMainPage() );
	}

	/**
	 * Return into the AuthManager login flow after a bound-user callback.
	 *
	 * The primary provider issued a newRedirect to this page during
	 * beginPrimaryAuthentication, and stored the AuthManager-provided
	 * returnToUrl (Special:Userlogin/return?authAction=login&wpLoginToken=...)
	 * in the session. Redirecting back to that exact URL causes the login
	 * form's handleReturnBeforeExecute to re-POST the stashed data, which
	 * triggers AuthManager::continueAuthentication, which calls our
	 * continuePrimaryAuthentication to read the stashed result and newPass.
	 *
	 * This mirrors the PluggableAuth pattern: redirect to the returnToUrl
	 * verbatim rather than constructing a bare Special:Userlogin URL.
	 */
	private function resumeLoginFlow() {
		$authManager = MediaWikiServices::getInstance()->getAuthManager();
		$returnToUrl = $authManager->getAuthenticationSessionData( P::SESSION_KEY_RETURNTO, '' );
		// Consume the stashed URL — it must not survive into a later
		// startFlow() call (which would re-trigger a QQ redirect).
		$authManager->removeAuthenticationSessionData( P::SESSION_KEY_RETURNTO );
		if ( $returnToUrl === '' ) {
			// Session lost; fall back to the login page with an error.
			$returnToUrl = SpecialPage::getTitleFor( 'Userlogin' )->getFullURL();
		}
		$this->getOutput()->redirect( $returnToUrl );
	}

	/**
	 * Read the pending QQ identity from the session.
	 *
	 * @return array|null
	 */
	private function getPending(): ?array {
		return MediaWikiServices::getInstance()->getAuthManager()
			->getAuthenticationSessionData( P::SESSION_KEY_PENDING, null );
	}

	/**
	 * Perform the actual bind/re-bind database write and show success.
	 * Shared by the direct-bind path and the post-2FA-verification path.
	 *
	 * @param string $bindMode 'bind' or 'rebind'
	 * @param \MediaWiki\User\User $user
	 * @param string $unionid
	 * @param string $appid
	 * @param string $nickname
	 * @param string $avatar
	 * @param array|null $existingBinding
	 */
	private function executeBind(
		string $bindMode,
		User $user,
		string $unionid,
		string $appid,
		string $nickname,
		string $avatar,
		?array $existingBinding
	) {
		if ( $bindMode === 'rebind' ) {
			$ok = $this->store->rebind( $user->getId(), $unionid, $appid, $nickname, $avatar );
		} else {
			if ( $this->store->userIsBound( $user->getId() ) ) {
				$this->showError( 'qqconnect-error-user-bound-other' );
				return;
			}
			$ok = $this->store->bind( $user->getId(), $unionid, $appid, $nickname, $avatar );
		}

		if ( !$ok ) {
			$this->showError( 'qqconnect-error-bind-failed' );
			return;
		}

		$out = $this->getOutput();
		$out->setPageTitleMsg( $this->msg( 'qqconnect-special-manage-title' ) );
		if ( $bindMode === 'rebind' ) {
			$out->addWikiMsg( 'qqconnect-manage-rebind-success' );
		} else {
			$out->addWikiMsg( 'qqconnect-manage-bind-success' );
		}
		$out->addReturnTo( SpecialPage::getTitleFor( 'QQConnect' ) );
	}

	/**
	 * Flow 2 step 2: show the 2FA verification form before completing a
	 * bind initiated by an already-logged-in user.
	 *
	 * Uses AuthManager's ACTION_LINK flow so that only secondary providers
	 * (e.g. OATHAuth) are prompted; the user does not re-enter a password.
	 */
	private function handleVerifyBind() {
		$authManager = MediaWikiServices::getInstance()->getAuthManager();
		$pendingBind = $authManager->getAuthenticationSessionData(
			'QQConnect:pendingBind', null
		);
		if ( $pendingBind === null ) {
			$this->showError( 'qqconnect-error-invalid-state' );
			return;
		}

		$user = $this->getUser();
		if ( !$user->isRegistered() ) {
			$this->showError( 'qqconnect-error-invalid-state' );
			return;
		}

		// Use ACTION_LINK to trigger only secondary providers.
		$linkReqs = $authManager->getAuthenticationRequests(
			AuthManager::ACTION_LINK, $user
		);
		$loadedReqs = AuthenticationRequest::loadRequestsFromSubmission(
			$linkReqs, []
		);
		$response = $authManager->beginAccountLink(
			$user, $loadedReqs,
			$this->getPageTitle()->getLocalURL( [ 'action' => 'verify-bind' ] )
		);

		if ( $response->status === AuthenticationResponse::PASS ) {
			// No secondary challenge needed (should have been caught earlier,
			// but handle gracefully).
			$authManager->removeAuthenticationSessionData( 'QQConnect:pendingBind' );
			$authManager->removeAuthenticationSessionData( 'QQConnect:bindSecondaryReqs' );
			$this->executeBind(
				$pendingBind['bindMode'], $user,
				$pendingBind['unionid'], $pendingBind['appid'],
				$pendingBind['nickname'], $pendingBind['avatar'],
				$pendingBind['existingBinding']
			);
			return;
		}

		if ( $response->status === AuthenticationResponse::UI ) {
			// Build form descriptor from the needed (secondary) requests.
			$formDescriptor = [];
			foreach ( $response->neededRequests as $req ) {
				foreach ( $req->getFieldInfo() as $fieldName => $fieldInfo ) {
					$formDescriptor[$fieldName] = $fieldInfo;
				}
			}
			$formDescriptor['__qqconnect_flow'] = [
				'type' => 'hidden',
				'name' => '__qqconnect_flow',
				'default' => 'verify-bind',
			];

			$out = $this->getOutput();
			$out->setPageTitleMsg( $this->msg( 'qqconnect-verify-bind-title' ) );
			$out->addWikiMsg( 'qqconnect-verify-bind-intro' );

			$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
			$form->setSubmitTextMsg( 'qqconnect-link-form-submit' );
			$form->setSubmitCallback( [ $this, 'onVerifyBindSubmit' ] );
			$form->show();
			return;
		}

		// FAIL or REDIRECT.
		$authManager->removeAuthenticationSessionData( 'QQConnect:pendingBind' );
		$authManager->removeAuthenticationSessionData( 'QQConnect:bindSecondaryReqs' );
		$this->showError( 'qqconnect-error-auth-failed' );
	}

	/**
	 * Handle submission of the Flow 2 2FA form.
	 *
	 * @param array $data
	 * @return StatusValue
	 */
	public function onVerifyBindSubmit( array $data ) {
		$authManager = MediaWikiServices::getInstance()->getAuthManager();
		$pendingBind = $authManager->getAuthenticationSessionData(
			'QQConnect:pendingBind', null
		);
		if ( $pendingBind === null ) {
			return StatusValue::newFatal( 'qqconnect-error-invalid-state' );
		}

		$user = $this->getUser();
		if ( !$user->isRegistered() ) {
			return StatusValue::newFatal( 'qqconnect-error-invalid-state' );
		}

		$linkReqs = $authManager->getAuthenticationRequests(
			AuthManager::ACTION_LINK, $user
		);
		$loadedReqs = AuthenticationRequest::loadRequestsFromSubmission(
			$linkReqs, $data
		);
		$response = $authManager->continueAccountLink( $loadedReqs );

		if ( $response->status === AuthenticationResponse::PASS ) {
			$authManager->removeAuthenticationSessionData( 'QQConnect:pendingBind' );
			$authManager->removeAuthenticationSessionData( 'QQConnect:bindSecondaryReqs' );
			$this->executeBind(
				$pendingBind['bindMode'], $user,
				$pendingBind['unionid'], $pendingBind['appid'],
				$pendingBind['nickname'], $pendingBind['avatar'],
				$pendingBind['existingBinding']
			);
			// Stash a flag so execute() renders the success message instead
			// of falling through to startFlow().
			$authManager->setAuthenticationSessionData(
				'QQConnect:linkSuccess', $user->getName()
			);
			$this->getRequest()->getSession()->save();
			return StatusValue::newGood();
		}

		if ( $response->status === AuthenticationResponse::UI ) {
			// Still need more input; HTMLForm will re-render with the
			// (possibly updated) needed requests on the next hit.
			$this->getRequest()->getSession()->save();
			return StatusValue::newGood();
		}

		$authManager->removeAuthenticationSessionData( 'QQConnect:pendingBind' );
		$authManager->removeAuthenticationSessionData( 'QQConnect:bindSecondaryReqs' );
		return StatusValue::newFatal( 'qqconnect-error-auth-failed' );
	}

	/**
	 * Determine the redirect URI.
	 *
	 * @return string
	 */
	private function getRedirectUri(): string {
		$configured = $this->config->getRedirectUri();
		if ( $configured ) {
			return $configured;
		}
		return $this->getPageTitle()->getFullURL();
	}
}
