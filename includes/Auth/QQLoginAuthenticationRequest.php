<?php
/**
 * The "Login with QQ" button AuthenticationRequest.
 *
 * Subclasses ButtonAuthenticationRequest so that core's AuthManager renders a
 * submit button labeled "Log in with QQ" on the login form. When the user
 * clicks it, this request is submitted, and QQPrimaryAuthenticationProvider::
 * beginPrimaryAuthentication detects it (via getRequestByClass) and starts the
 * OAuth redirect flow.
 *
 * Pattern follows PluggableAuth's BeginAuthenticationRequest.
 */

namespace MediaWiki\Extension\QQConnect\Auth;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\ButtonAuthenticationRequest;
use Message;

class QQLoginAuthenticationRequest extends ButtonAuthenticationRequest {

	/** Button name used as the field key and in getUniqueId(). */
	public const BUTTON_NAME = 'qqconnectlogin';

	/**
	 * @param bool $enabled Whether the button should be shown (false when the
	 *    extension is in test mode but still wants a visible button, or when
	 *    not configured; the provider decides). The button is always
	 *    REQUIRED so core renders it.
	 */
	public function __construct( bool $enabled = true ) {
		parent::__construct(
			self::BUTTON_NAME,
			new Message( 'qqconnect-login-button' ),
			new Message( 'qqconnect-loginbutton-help' ),
			true
		);
	}

	/**
	 * Only show the button on the LOGIN action.
	 *
	 * @return array
	 */
	public function getFieldInfo(): array {
		if ( $this->action !== AuthManager::ACTION_LOGIN ) {
			return [];
		}
		return parent::getFieldInfo();
	}
}
