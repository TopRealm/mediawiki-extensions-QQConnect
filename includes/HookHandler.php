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
		// Position the QQ login button next to the standard "Log in" button.
		// IMPORTANT: only set qqconnect-login-button in cssclass. The Codex
		// classes (cdx-button, cdx-button--action-progressive,
		// cdx-button--weight-primary) are added automatically by
		// CodexHTMLForm to the <button> element. Putting them in cssclass
		// would ALSO add them to the wrapper <div> and break the layout.
		$key = QQLoginAuthenticationRequest::BUTTON_NAME;
		if ( isset( $formDescriptor[$key] ) ) {
			$formDescriptor[$key]['weight'] = 101;
			$formDescriptor[$key]['cssclass'] = 'qqconnect-login-button';
		}
	}

	/**
	 * Load the extension stylesheet on pages where the QQ login button or
	 * management UI may appear (login form, special pages).
	 *
	 * This hook replaces the removed PersonalUrls hook in MW 1.43. We use it
	 * only to ensure the CSS module is loaded; no personal-menu entries are
	 * added so as not to clutter the user dropdown.
	 *
	 * @param SkinTemplate $sktemplate
	 * @param array &$links
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		// Load styles when the QQ button might render (login form) or when
		// on our own special pages (those pages also load styles themselves,
		// but this covers the login page).
		$sktemplate->getOutput()->addModuleStyles( 'ext.QQConnect.styles' );
	}

	/**
	 * Add QQ Connect binding status to the "User profile" preferences tab.
	 *
	 * Uses the sub-section key 'personal/qqconnect' so the binding status and
	 * management link appear as a compact group INSIDE the existing "User
	 * profile" tab rather than creating a separate top-level tab.
	 *
	 * The sub-section heading is the i18n message prefs-qqconnect.
	 *
	 * @param User $user
	 * @param array &$preferences
	 */
	public function onGetPreferences( $user, &$preferences ) {
		$binding = $this->store->findBindingByUser( $user->getId() );

		if ( $binding ) {
			$nickname = $binding['qqc_nickname'] ?? $binding['qqc_openid'];
			// Use array format for label-message to pass $nickname as $1.
			$preferences['qqconnect-bound'] = [
				'type' => 'info',
				'label-message' => [ 'qqconnect-prefs-bound', $nickname ],
				'default' => '',
				'section' => 'personal/qqconnect',
			];
		} else {
			$preferences['qqconnect-bound'] = [
				'type' => 'info',
				'label-message' => 'qqconnect-prefs-not-bound',
				'default' => '',
				'section' => 'personal/qqconnect',
			];
		}

		// A link to the management page styled as a Codex progressive button.
		// cdx-button--fake-button--enabled is required on <a> elements because
		// :enabled only matches <button>/<input>, not links.
		$manageUrl = SpecialPage::getTitleFor( 'QQConnect' )->getLocalURL();
		$preferences['qqconnect-manage-link'] = [
			'type' => 'info',
			'raw' => true,
			'label-message' => 'qqconnect-prefs-section-desc',
			'default' => '<a class="cdx-button cdx-button--action-progressive cdx-button--weight-primary cdx-button--fake-button--enabled" href="'
				. htmlspecialchars( $manageUrl ) . '">'
				. $this->msg( 'qqconnect-prefs-manage' )->escaped() . '</a>',
			'section' => 'personal/qqconnect',
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
		$authManager = MediaWikiServices::getInstance()->getAuthManager();
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
