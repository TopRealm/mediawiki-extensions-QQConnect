<?php
/**
 * Special:QQConnectLogin — the OAuth callback and flow-control special page.
 *
 * Responsibilities:
 *  1. In test mode: show a notice page (the button must be visible for QQ
 *     review, but no real OAuth runs).
 *  2. Otherwise, redirect the browser to QQ's authorize endpoint.
 *  3. Receive the QQ callback (?code=...&state=...), verify state, exchange
 *     the code for a token, fetch openid + userinfo.
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

		// If there is a ?code= parameter, this is the OAuth callback.
		if ( $request->getRawVal( 'code' ) !== null ) {
			$this->handleCallback();
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

		$authManager = AuthManager::singleton();
		$bindMode = $authManager->getAuthenticationSessionData( 'QQConnect:bindMode', null );

		// If there is no AuthManager login state and no bind mode, send the
		// user to the login form so they click the QQ button there.
		$hasAuthState = $authManager->getAuthenticationSessionData( P::SESSION_KEY_RETURNTO, '' ) !== '';
		if ( !$hasAuthState && $bindMode === null ) {
			$this->getOutput()->redirect(
				SpecialPage::getTitleFor( 'Userlogin' )->getFullURL()
			);
			return;
		}

		$state = $this->client->generateState();
		$redirectUri = $this->getRedirectUri();

		// Stash state for verification on callback.
		$authManager->setAuthenticationSessionData( P::SESSION_KEY_STATE, $state );
		$authManager->setAuthenticationSessionData( P::SESSION_KEY_REDIRECT, $redirectUri );

		$authorizeUrl = $this->client->getAuthorizeUrl( $redirectUri, $state );
		$this->logger->info( 'Redirecting user to QQ authorize endpoint' );
		$this->getOutput()->redirect( $authorizeUrl );
	}

	/**
	 * Handle the OAuth callback (?code=...&state=...).
	 */
	private function handleCallback() {
		$request = $this->getRequest();
		$authManager = AuthManager::singleton();

		// Verify state to prevent CSRF.
		$expectedState = $authManager->getAuthenticationSessionData( P::SESSION_KEY_STATE, '' );
		$gotState = $request->getRawVal( 'state', '' );
		if ( $expectedState === '' || !hash_equals( (string)$expectedState, (string)$gotState ) ) {
			$this->showError( 'qqconnect-error-invalid-state' );
			return;
		}
		// Consume the state.
		$authManager->removeAuthenticationSessionData( P::SESSION_KEY_STATE );

		$redirectUri = $authManager->getAuthenticationSessionData( P::SESSION_KEY_REDIRECT, '' );
		if ( $redirectUri === '' ) {
			$redirectUri = $this->getRedirectUri();
		}

		$code = $request->getRawVal( 'code' );
		if ( $code === null || $code === '' ) {
			$this->showError( 'qqconnect-error-no-code' );
			return;
		}

		// Perform the OAuth round-trip.
		try {
			$tokenData = $this->client->exchangeCodeForToken( $code, $redirectUri );
			$accessToken = $tokenData['access_token'];
			$openid = $this->client->getOpenid( $accessToken );
			$userInfo = $this->client->getUserInfo( $accessToken, $openid );
		} catch ( QQConnectException $e ) {
			$this->logger->error( 'QQ OAuth failed at {stage}: {msg}', [
				'stage' => $e->getStage(),
				'msg' => $e->getMessage(),
			] );
			$this->showError( $e->getErrorMessageKey() );
			return;
		}

		$nickname = (string)( $userInfo['nickname'] ?? '' );
		$avatar = QQClient::pickAvatar( $userInfo );
		$appid = $this->config->getAppId();

		// Check for a "bind mode" flag (user is logged in and initiating a
		// bind/rebind from Special:QQConnect).
		$bindMode = $authManager->getAuthenticationSessionData( 'QQConnect:bindMode', null );

		// Is this QQ already bound to a MediaWiki user?
		$binding = $this->store->findBindingByOpenid( $openid, $appid );

		if ( $bindMode !== null ) {
			// Bind/rebind flow initiated by a logged-in user.
			$this->handleBindModeCallback( $bindMode, $openid, $appid, $nickname, $avatar, $binding );
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
			'openid' => $openid,
			'appid' => $appid,
			'nickname' => $nickname,
			'avatar' => $avatar,
		] );
		$this->showChoosePage( $nickname );
	}

	/**
	 * Handle the callback when a logged-in user is binding/rebinding.
	 *
	 * @param string $bindMode 'bind' or 'rebind'
	 * @param string $openid
	 * @param string $appid
	 * @param string $nickname
	 * @param string $avatar
	 * @param array|null $existingBinding
	 */
	private function handleBindModeCallback(
		string $bindMode,
		string $openid,
		string $appid,
		string $nickname,
		string $avatar,
		?array $existingBinding
	) {
		$authManager = AuthManager::singleton();
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

		if ( $bindMode === 'rebind' ) {
			// Replace the existing binding.
			$ok = $this->store->rebind( $user->getId(), $openid, $appid, $nickname, $avatar );
		} else {
			// Fresh bind; user should not already be bound.
			if ( $this->store->userIsBound( $user->getId() ) ) {
				$this->showError( 'qqconnect-error-user-bound-other' );
				return;
			}
			$ok = $this->store->bind( $user->getId(), $openid, $appid, $nickname, $avatar );
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
			$cleaned = UsernameCleaner::generateFromOpenid( $pending['openid'] );
		}
		$title = SpecialPage::getTitleFor( 'CreateAccount' );
		$url = $title->getFullURL( [
			'wpName' => $cleaned,
			'from' => 'qqconnect',
		] );
		$this->getOutput()->redirect( $url );
	}

	/**
	 * Show the "link to existing account" form (username + password).
	 */
	private function handleLinkForm() {
		$pending = $this->getPending();
		if ( $pending === null ) {
			$this->showError( 'qqconnect-error-invalid-state' );
			return;
		}

		$out = $this->getOutput();
		$out->setPageTitleMsg( $this->msg( 'qqconnect-link-form-title' ) );
		$out->addWikiMsg( 'qqconnect-link-form-intro', $pending['nickname'] ?? $pending['openid'] );

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
		];

		$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$form->setSubmitTextMsg( 'qqconnect-link-form-submit' );
		$form->setSubmitCallback( [ $this, 'onLinkSubmit' ] );
		$form->show();
	}

	/**
	 * Verify the supplied credentials via AuthManager (so OATHAuth etc. run),
	 * then bind the QQ and log the user in.
	 *
	 * We build a PasswordAuthenticationRequest submission and pass it to
	 * AuthManager::beginAuthentication. This runs pre-auth, primary (password),
	 * and secondary (OATHAuth 2FA) providers. On PASS, we bind and log in.
	 *
	 * @param array $data
	 * @return StatusValue
	 */
	public function onLinkSubmit( array $data ) {
		$pending = $this->getPending();
		if ( $pending === null ) {
			return StatusValue::newFatal( 'qqconnect-error-invalid-state' );
		}

		$username = trim( $data['username'] ?? '' );
		$password = $data['password'] ?? '';
		if ( $username === '' || $password === '' ) {
			return StatusValue::newFatal( 'qqconnect-error-auth-failed' );
		}

		$authManager = AuthManager::singleton();

		// Obtain the login AuthenticationRequests and populate a password
		// request with the submitted credentials.
		$reqs = $authManager->getAuthenticationRequests( AuthManager::ACTION_LOGIN );
		$loadedReqs = AuthenticationRequest::loadRequestsFromSubmission( $reqs, [
			'username' => $username,
			'password' => $password,
		] );

		// Verify credentials through AuthManager (runs OATHAuth etc.).
		$response = $authManager->beginAuthentication(
			$loadedReqs,
			$this->getFullTitle()->getFullURL()
		);

		if ( $response->status === AuthenticationResponse::PASS ) {
			$boundUser = User::newFromName( $response->username );
			if ( !$boundUser || !$boundUser->getId() ) {
				return StatusValue::newFatal( 'qqconnect-error-auth-failed' );
			}
			// The QQ must not be bound to someone else.
			if ( $this->store->openidIsBound( $pending['openid'], $pending['appid'] ) ) {
				return StatusValue::newFatal( 'qqconnect-error-openid-bound-other' );
			}
			// The MediaWiki user must not already have a different QQ bound.
			if ( $this->store->userIsBound( $boundUser->getId() ) ) {
				return StatusValue::newFatal( 'qqconnect-error-user-bound-other' );
			}
			$ok = $this->store->bind(
				$boundUser->getId(),
				$pending['openid'],
				$pending['appid'],
				$pending['nickname'] ?? '',
				$pending['avatar'] ?? ''
			);
			if ( !$ok ) {
				return StatusValue::newFatal( 'qqconnect-error-bind-failed' );
			}
			// Clear pending.
			$authManager->removeAuthenticationSessionData( P::SESSION_KEY_PENDING );

			// AuthManager::beginAuthentication already logged the user in on
			// PASS. Show a success message.
			$this->getOutput()->addWikiMsg( 'qqconnect-manage-bind-success' );
			$this->getOutput()->addReturnTo( SpecialPage::getTitleFor( 'QQConnect' ) );
			return StatusValue::newGood();
		}

		// UI (e.g. OATHAuth 2FA challenge) or REDIRECT or FAIL.
		// For a 2FA challenge during linking, surface a message directing the
		// user to complete 2FA via the normal login flow first, then bind from
		// the management page (which uses the bindMode flow).
		if ( $response->status === AuthenticationResponse::UI
			|| $response->status === AuthenticationResponse::REDIRECT
		) {
			return StatusValue::newFatal( 'qqconnect-error-auth-failed' );
		}

		// FAIL.
		return StatusValue::newFatal( 'qqconnect-error-auth-failed' );
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
	 */
	private function showError( string $messageKey ) {
		$out = $this->getOutput();
		$out->setPageTitleMsg( $this->msg( 'qqconnect-special-login-title' ) );
		$out->addWikiMsg( $messageKey );
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
		$authManager = AuthManager::singleton();
		$returnToUrl = $authManager->getAuthenticationSessionData( P::SESSION_KEY_RETURNTO, '' );
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
		return AuthManager::singleton()->getAuthenticationSessionData( P::SESSION_KEY_PENDING, null );
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
