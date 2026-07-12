<?php
/**
 * QQ Connect primary authentication provider.
 *
 * Implements the server-side OAuth2 login flow against QQ Connect (QQ互联).
 *
 * DESIGN GOAL: never bypass MediaWiki's security checks.
 * -------------------------------------------------------------------
 * MediaWiki offers two account-creation paths:
 *  (A) Normal creation via beginAccountCreation / Special:CreateAccount —
 *      runs all PreAuthenticationProvider checks (ConfirmEdit captcha,
 *      AntiSpoof, TitleBlacklist) and secondary providers (OATHAuth etc.).
 *  (B) Auto-creation via AuthManager::autoCreateUser — triggered when a
 *      primary provider returns newPass() for a non-existent user. This
 *      path BYPASSES ConfirmEdit captcha and AntiSpoof's conflict check.
 *
 * To honor OATHAuth / AntiSpoof / ConfirmEdit / TitleBlacklist, this provider
 * NEVER returns newPass() for a user that does not already exist locally.
 * Instead:
 *  - For an already-bound QQ user: continuePrimaryAuthentication returns
 *    newPass( $existingUsername ). AuthManager logs them in, and OATHAuth's
 *    secondary challenge runs automatically (2FA is NOT bypassed).
 *  - For an unbound QQ user: the OAuth result (openid + userinfo) is stashed
 *    in the session, and the user is sent to Special:QQConnectLogin to choose
 *    "create new account" (which routes through normal Special:CreateAccount,
 *    running all PreAuth checks; the binding is completed in the
 *    LocalUserCreated hook) or "link existing account" (which verifies the
 *    account via AuthManager, so OATHAuth etc. apply).
 *
 * Account creation type is TYPE_NONE: this provider does not itself create
 * accounts through the creation form, so it never participates in
 * beginPrimaryAccountCreation (returns newAbstain).
 */

namespace MediaWiki\Extension\QQConnect\Auth;

use MediaWiki\Auth\AbstractPrimaryAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Extension\QQConnect\QQConnectConfig;
use MediaWiki\Extension\QQConnect\QQStore;
use MediaWiki\Extension\QQConnect\Util\UsernameCleaner;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use RawMessage;

class QQPrimaryAuthenticationProvider extends AbstractPrimaryAuthenticationProvider {

	/** Session key: the OAuth state token (CSRF). */
	public const SESSION_KEY_STATE = 'QQConnect:state';
	/** Session key: the redirect URI used for the authorize request. */
	public const SESSION_KEY_REDIRECT = 'QQConnect:redirect';
	/** Session key: the returnToUrl supplied to beginAuthentication. */
	public const SESSION_KEY_RETURNTO = 'QQConnect:returnto';
	/** Session key: pending QQ identity after a successful OAuth round-trip. */
	public const SESSION_KEY_PENDING = 'QQConnect:pending';
	/** Session key: result written by the special page for continue. */
	public const SESSION_KEY_RESULT = 'QQConnect:result';

	/** @var QQConnectConfig */
	private $config;

	/** @var QQStore */
	private $store;

	public function __construct(
		QQConnectConfig $config,
		QQStore $store
	) {
		$this->config = $config;
		$this->store = $store;
		$this->logger = LoggerFactory::getInstance( 'qqconnect' );
	}

	public function postInitSetup() {
		// Logger is obtained lazily since the parent's init() injects a logger;
		// we keep our own for the 'qqconnect' channel for readability.
		$this->logger = LoggerFactory::getInstance( 'qqconnect' );
	}

	/**
	 * Provide the login button on the login form.
	 *
	 * @param string $action
	 * @param array $options
	 * @return AuthenticationRequest[]
	 */
	public function getAuthenticationRequests( $action, array $options ) {
		switch ( $action ) {
			case AuthManager::ACTION_LOGIN:
				// Show the button whenever the extension is enabled. In test
				// mode the button is still shown (it must be visible for QQ
				// review); clicking it shows a test notice instead of OAuth.
				return [ new QQLoginAuthenticationRequest( true ) ];
			default:
				return [];
		}
	}

	/**
	 * Begin the QQ login: stash state + redirect, return a REDIRECT to
	 * Special:QQConnectLogin which performs the OAuth round-trip.
	 *
	 * @param AuthenticationRequest[] $reqs
	 * @return AuthenticationResponse
	 */
	public function beginPrimaryAuthentication( array $reqs ) {
		$buttonReq = AuthenticationRequest::getRequestByClass( $reqs, QQLoginAuthenticationRequest::class );
		if ( !$buttonReq ) {
			// Not our button; let other providers handle this attempt.
			return AuthenticationResponse::newAbstain();
		}

		// Build the OAuth state and redirect URI, stash them in the session.
		$state = bin2hex( random_bytes( 16 ) );
		$redirectUri = $this->getRedirectUri();

		$this->manager->setAuthenticationSessionData( self::SESSION_KEY_STATE, $state );
		$this->manager->setAuthenticationSessionData( self::SESSION_KEY_REDIRECT, $redirectUri );
		$this->manager->setAuthenticationSessionData(
			self::SESSION_KEY_RETURNTO,
			$buttonReq->returnToUrl ?? ''
		);

		// Redirect the browser to Special:QQConnectLogin which will either show
		// the test-mode notice or redirect to QQ's authorize endpoint.
		$target = SpecialPage::getTitleFor( 'QQConnectLogin' )->getFullURL();
		$this->logger->info( 'Beginning QQ login; redirecting to {target}', [ 'target' => $target ] );

		return AuthenticationResponse::newRedirect(
			[ new QQContinueAuthenticationRequest() ],
			$target
		);
	}

	/**
	 * Resume the flow after the special page has finished the OAuth round-trip.
	 * Read the outcome from the session and either PASS (bound user),
	 * or FAIL (error / user must use the special page flow for unbound).
	 *
	 * @param AuthenticationRequest[] $reqs
	 * @return AuthenticationResponse
	 */
	public function continuePrimaryAuthentication( array $reqs ) {
		$contReq = AuthenticationRequest::getRequestByClass( $reqs, QQContinueAuthenticationRequest::class );
		if ( !$contReq ) {
			return AuthenticationResponse::newFail( new RawMessage( 'qqconnect-error-invalid-state' ) );
		}

		// The special page stored the outcome under SESSION_KEY_RESULT.
		$result = $this->manager->getAuthenticationSessionData( self::SESSION_KEY_RESULT, null );
		$this->manager->removeAuthenticationSessionData( self::SESSION_KEY_RESULT );

		if ( $result === null ) {
			return AuthenticationResponse::newFail( new RawMessage( 'qqconnect-error-invalid-state' ) );
		}

		// Error from the special page.
		if ( isset( $result['error'] ) ) {
			$this->logger->info( 'QQ login failed: {error}', [ 'error' => $result['error'] ] );
			return AuthenticationResponse::newFail( new RawMessage( $result['error'] ) );
		}

		// The special page handles the "unbound" case itself (it routes to the
		// choose/bind UI) rather than returning here. If we got here, it
		// should be a "bound user, log them in" outcome with a username.
		if ( !isset( $result['username'] ) ) {
			return AuthenticationResponse::newFail( new RawMessage( 'qqconnect-error-invalid-state' ) );
		}

		$username = $result['username'];
		$this->logger->info( 'QQ login PASS for {user}', [ 'user' => $username ] );
		return AuthenticationResponse::newPass( $username );
	}

	/**
	 * This provider does not create accounts through the creation form.
	 *
	 * @return string
	 */
	public function accountCreationType() {
		return self::TYPE_NONE;
	}

	/**
	 * @param AuthenticationRequest[] $reqs
	 * @return AuthenticationResponse
	 */
	public function beginPrimaryAccountCreation( $user, $creator, array $reqs ) {
		return AuthenticationResponse::newAbstain();
	}

	/**
	 * Test whether a local user exists. Used by core for provider selection.
	 *
	 * @param string $username
	 * @param int $flags
	 * @return bool
	 */
	public function testUserExists( $username, $flags = 0 ) {
		// This provider authenticates via QQ; it does not own any password
		// credentials, so it cannot meaningfully answer this. Return false to
		// avoid interfering with the LocalPassword provider.
		return false;
	}

	/**
	 * @param string $username
	 * @return bool
	 */
	public function testUserCanAuthenticate( $username ) {
		// A user can authenticate via QQ iff they have a binding.
		$u = User::newFromName( $username );
		if ( !$u ) {
			return false;
		}
		return $this->store->userIsBound( $u->getId() );
	}

	/**
	 * @param array $reqs
	 * @return AuthenticationResponse
	 */
	public function continuePrimaryAccountCreation( $user, $creator, array $reqs ) {
		return AuthenticationResponse::newAbstain();
	}

	/**
	 * Determine the redirect URI. Uses the configured value if set, otherwise
	 * builds it from Special:QQConnectLogin/callback.
	 *
	 * @return string
	 */
	private function getRedirectUri(): string {
		$configured = $this->config->getRedirectUri();
		if ( $configured ) {
			return $configured;
		}
		return SpecialPage::getTitleFor( 'QQConnectLogin' )->getFullURL();
	}

	/**
	 * Called by core when an admin revokes credentials for a user. We remove
	 * their QQ binding so they can no longer log in via QQ.
	 *
	 * @param string $username
	 */
	public function providerRevokeAccessForUser( $username ) {
		$u = User::newFromName( $username );
		if ( $u && $u->getId() ) {
			$this->store->unbind( $u->getId() );
		}
	}

	/**
	 * @param string $property
	 * @return bool
	 */
	public function providerAllowsPropertyChange( $property ) {
		return true;
	}
}
