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
	 * @param string $stage One of: 'http', 'token', 'openid', 'unionid', 'userinfo'.
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
			case 'unionid':
				return 'qqconnect-error-openid';
			case 'userinfo':
				return 'qqconnect-error-userinfo';
			default:
				return 'qqconnect-error-disabled';
		}
	}

	/**
	 * Returns a short, safe-for-frontend label for the OAuth stage.
	 *
	 * @return string
	 */
	public function getStageLabel(): string {
		switch ( $this->stage ) {
			case 'token':
				return 'token (exchange code → access_token)';
			case 'openid':
				return 'openid (get QQ OpenID)';
			case 'unionid':
				return 'unionid (get QQ UnionID)';
			case 'userinfo':
				return 'userinfo (get QQ profile)';
			case 'http':
				return 'http (network request)';
			default:
				return $this->stage;
		}
	}

	/**
	 * Returns a debug summary safe to show in the browser.
	 *
	 * NEVER includes access tokens, client secrets, or raw API response
	 * bodies. Only stage + a sanitized reason string.
	 *
	 * @return string
	 */
	public function getDebugMessage(): string {
		$raw = $this->getMessage();

		// Strip potential token-like substrings (hex strings ≥ 32 chars,
		// bare "access_token=..." fragments from urlencoded responses).
		$clean = preg_replace( '/\baccess_token=[^&\s]{20,}/i', 'access_token=***', $raw );
		$clean = preg_replace( '/\brefresh_token=[^&\s]{20,}/i', 'refresh_token=***', $clean );

		// If the message looks like a raw response dump (contains callback
		// wrapper or JSON), replace it with a safe placeholder.
		if ( preg_match( '/^\s*callback\s*\(/', $clean ) || preg_match( '/^\s*\{/', $clean ) ) {
			return $this->stage . ': ' . '(raw API response omitted for security)';
		}

		return $this->stage . ': ' . $clean;
	}
}
