<?php
/**
 * Hook handler for the QQConnect extension.
 *
 * Registered in extension.json under HookHandlers.main and wired to hooks:
 *  - AuthChangeFormFields       : position the "Login with QQ" button.
 *  - SkinTemplateNavigation::Universal : inject QQ login / management links
 *    into the personal menu (works for Citizen, Vector, Timeless).
 *  - GetPreferences             : add a "QQ Connect" preferences tab entry.
 *  - LocalUserCreated           : complete binding for accounts created via
 *    the "create new account" path of the QQ login flow.
 *  - getUserPermissionsErrors   : enforce $wgQQConnectRequireBind.
 *
 * Also exposes ::onExtensionFunction (registered via ExtensionFunctions) for
 * one-time setup that needs the fully-initialized service container.
 */

namespace MediaWiki\Extension\QQConnect;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Extension\QQConnect\Auth\QQLoginAuthenticationRequest;
use MediaWiki\Extension\QQConnect\Auth\QQPrimaryAuthenticationProvider as P;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Psr\Log\LoggerInterface;
use SkinTemplate;

class HookHandler {

	/** @var QQConnectConfig */
	private $config;

	/** @var QQStore */
	private $store;

	/** @var UserFactory */
	private $userFactory;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		QQConnectConfig $config,
		QQStore $store,
		UserFactory $userFactory
	) {
		$this->config = $config;
		$this->store = $store;
		$this->userFactory = $userFactory;
		$this->logger = LoggerFactory::getInstance( 'qqconnect' );
	}

	/**
	 * ExtensionFunctions callback: register the 'qqconnect-manage-self'
	 * security-sensitive operation so that Special:QQConnect's
	 * checkLoginSecurityLevel can trigger re-auth.
	 */
	public static function onExtensionFunction() {
		// Security-sensitive operations are registered via a static config
		// ($wgRateLimits / AuthManager), but for the re-auth prompt we rely on
		// the default SEC_REAUTH behavior, which is automatic. No explicit
		// registration is required in 1.43.
	}

	/**
	 * Position the QQ login button on the login form.
	 *
	 * @param array $requests
	 * @param array $fieldInfo
	 * @param array &$formDescriptor
	 * @param string $action
	 */
	public function onAuthChangeFormFields(
		$requests,
		$fieldInfo,
		&$formDescriptor,
		$action
	) {
		// The button field key is the QQLoginAuthenticationRequest button name.
		$key = QQLoginAuthenticationRequest::BUTTON_NAME;
		if ( isset( $formDescriptor[$key] ) ) {
			// Place the QQ button below the standard fields, with a marker
			// class so the CSS module can style it (QQ-blue).
			$formDescriptor[$key]['weight'] = 101;
			$formDescriptor[$key]['cssclass'] = 'qqconnect-login-button mw-ui-button mw-ui-progressive';
			$formDescriptor[$key]['buttonlabel-message'] = 'qqconnect-login-button';
		}
	}

	/**
	 * Inject QQ login / management links into the personal menu.
	 *
	 * This hook replaces the removed PersonalUrls hook in MW 1.43 and works for
	 * Citizen, Vector, and Timeless, which all render the standard
	 * data-user-menu portlet.
	 *
	 * @param SkinTemplate $sktemplate
	 * @param array &$links
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		$user = $sktemplate->getUser();
		$out = $sktemplate->getOutput();

		// Always load the styling so the QQ button/link looks right.
		$out->addModuleStyles( 'ext.QQConnect.styles' );

		if ( $user->isRegistered() ) {
			// Logged in: add a "QQ" management link to the user menu.
			$binding = $this->store->findBindingByUser( $user->getId() );
			if ( $binding ) {
				$text = $sktemplate->msg( 'qqconnect-personal-manage' )->text();
			} else {
				$text = $sktemplate->msg( 'qqconnect-personal-bind' )->text();
			}
			$links['user-menu']['qqconnect'] = [
				'text' => $text,
				'href' => SpecialPage::getTitleFor( 'QQConnect' )->getLocalURL(),
				'active' => false,
				'icon' => 'userAvatar',
			];
		} else {
			// Anonymous: add a "QQ Login" link so the button is visible in the
			// personal area too (helps QQ review, complements the login-form
			// button). In test mode this leads to the test notice.
			$loginUrl = SpecialPage::getTitleFor( 'QQConnectLogin' )->getLocalURL();
			$links['user-menu']['qqconnect-login'] = [
				'text' => $sktemplate->msg( 'qqconnect-personal-qqlogin' )->text(),
				'href' => $loginUrl,
				'active' => false,
				'icon' => 'logIn',
			];
		}
	}

	/**
	 * Add a "QQ Connect" section to user preferences.
	 *
	 * @param User $user
	 * @param array &$preferences
	 */
	public function onGetPreferences( $user, &$preferences ) {
		$binding = $this->store->findBindingByUser( $user->getId() );

		// Section tab.
		$preferences['qqconnect-section'] = [
			'type' => 'info',
			'raw' => true,
			'default' => '',
			'label-message' => 'qqconnect-prefs-section',
			'section' => 'qqconnect/info',
		];

		if ( $binding ) {
			$nickname = $binding['qqc_nickname'] ?? $binding['qqc_openid'];
			$preferences['qqconnect-bound'] = [
				'type' => 'info',
				'raw' => false,
				'default' => $this->formatBound( $nickname ),
				'label-message' => 'qqconnect-prefs-bound',
				'section' => 'qqconnect/info',
			];
		} else {
			$preferences['qqconnect-bound'] = [
				'type' => 'info',
				'raw' => false,
				'default' => $this->msg( 'qqconnect-prefs-not-bound' )->text(),
				'label-message' => 'qqconnect-prefs-bound',
				'section' => 'qqconnect/info',
			];
		}

		// A link to the management page.
		$manageUrl = SpecialPage::getTitleFor( 'QQConnect' )->getLocalURL();
		$preferences['qqconnect-manage-link'] = [
			'type' => 'info',
			'raw' => true,
			'default' => '<a href="' . htmlspecialchars( $manageUrl ) . '">'
				. $this->msg( 'qqconnect-prefs-manage' )->escaped() . '</a>',
			'label-message' => 'qqconnect-prefs-section-desc',
			'section' => 'qqconnect/info',
		];
	}

	/**
	 * Complete the binding for accounts created via the QQ "create new
	 * account" path. When a user finishes Special:CreateAccount after having
	 * chosen "create" on the QQ choose page, the pending QQ identity is in the
	 * session; we bind it to the freshly-created user.
	 *
	 * This runs for BOTH manual and auto-created accounts, but we only act
	 * when there is a pending QQ identity in the session.
	 *
	 * @param User $user
	 * @param bool $autocreated
	 */
	public function onLocalUserCreated( $user, $autocreated ) {
		$authManager = AuthManager::singleton();
		$pending = $authManager->getAuthenticationSessionData( P::SESSION_KEY_PENDING, null );
		if ( !$pending ) {
			return;
		}
		// Only bind if not already bound (avoid double-binding if the hook
		// fires twice or the user somehow already has a binding).
		if ( $this->store->userIsBound( $user->getId() ) ) {
			$authManager->removeAuthenticationSessionData( P::SESSION_KEY_PENDING );
			return;
		}
		// Verify the QQ isn't bound to another user (shouldn't happen since we
		// just checked at callback time, but be safe).
		if ( $this->store->openidIsBound( $pending['openid'], $pending['appid'] ) ) {
			$this->logger->warning(
				'Cannot bind pending QQ {openid} to new user {user}: openid already bound',
				[ 'openid' => $pending['openid'], 'user' => $user->getName() ]
			);
			$authManager->removeAuthenticationSessionData( P::SESSION_KEY_PENDING );
			return;
		}
		$ok = $this->store->bind(
			$user->getId(),
			$pending['openid'],
			$pending['appid'],
			$pending['nickname'] ?? '',
			$pending['avatar'] ?? ''
		);
		if ( $ok ) {
			$this->logger->info(
				'Bound pending QQ {openid} to newly created user {user}',
				[ 'openid' => $pending['openid'], 'user' => $user->getName() ]
			);
		}
		$authManager->removeAuthenticationSessionData( P::SESSION_KEY_PENDING );
	}

	/**
	 * Enforce $wgQQConnectRequireBind: block editing by users without a bound
	 * QQ account.
	 *
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param array|string|false &$result
	 * @return bool|void
	 */
	public function onGetUserPermissionsErrors( $title, $user, $action, &$result ) {
		if ( !$this->config->isRequireBind() ) {
			return true;
		}
		// Only gate edit actions.
		$editActions = [ 'edit', 'create', 'move', 'delete', 'upload', 'createpage', 'createtalk' ];
		if ( !in_array( $action, $editActions, true ) ) {
			return true;
		}
		// Exempt users with the manage right (admins) and anons (anons can't
		// edit most wikis anyway, and we don't want to block them from
		// anything they could normally do).
		if ( !$user->isRegistered() ) {
			return true;
		}
		if ( MediaWikiServices::getInstance()->getPermissionManager()
			->userHasRight( $user, 'qqconnect-manage' ) ) {
			return true;
		}
		// Allow if the user has a bound QQ.
		if ( $this->store->userIsBound( $user->getId() ) ) {
			return true;
		}
		// Block: set the error message and abort.
		$result = [ 'qqconnect-error-require-bind' ];
		return false;
	}

	/**
	 * Helper: produce a localized "bound to $1" string for preferences.
	 *
	 * @param string $nickname
	 * @return string
	 */
	private function formatBound( string $nickname ): string {
		return htmlspecialchars( $nickname );
	}

	/**
	 * Shorthand for $this->getContext()->msg() — but hook handlers don't have a
	 * context, so use a request-context-independent Message.
	 *
	 * @param string $key
	 * @return \Message
	 */
	private function msg( string $key ) {
		return wfMessage( $key );
	}
}
