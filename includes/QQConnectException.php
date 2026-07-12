<?php
/**
 * Exception type thrown by the QQ Connect OAuth2 client.
 *
 * Carries a "stage" identifier so callers can map failures to localized
 * messages (e.g. stage 'token' -> qqconnect-error-token).
 */

namespace MediaWiki\Extension\QQConnect;

use Exception;

class QQConnectException extends Exception {

	/** @var string */
	private $stage;

	/**
	 * @param string $stage One of: 'http', 'token', 'openid', 'userinfo'.
	 * @param string $message
	 * @param int $code
	 * @param Exception|null $previous
	 */
	public function __construct( string $stage, string $message, int $code = 0, Exception $previous = null ) {
		$this->stage = $stage;
		parent::__construct( $message, $code, $previous );
	}

	/**
	 * @return string
	 */
	public function getStage(): string {
		return $this->stage;
	}

	/**
	 * Returns the i18n message key for this stage's generic error.
	 *
	 * @return string
	 */
	public function getErrorMessageKey(): string {
		switch ( $this->stage ) {
			case 'token':
				return 'qqconnect-error-token';
			case 'openid':
				return 'qqconnect-error-openid';
			case 'userinfo':
				return 'qqconnect-error-userinfo';
			default:
				return 'qqconnect-error-disabled';
		}
	}
}
