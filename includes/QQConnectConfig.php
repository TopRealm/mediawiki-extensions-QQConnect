<?php
/**
 * Configuration accessor for the QQConnect extension.
 *
 * Wraps the extension's config values (read from the global Config registry,
 * keyed under the 'wg' prefix as declared in extension.json) in a service so
 * that other classes can be injected with a single typed dependency.
 */

namespace MediaWiki\Extension\QQConnect;

use Config;
use ConfigException;

class QQConnectConfig {

	/** @var Config */
	private $config;

	public const CONFIG_PREFIX = 'QQConnect';

	/**
	 * @param Config $config The 'QQConnect' extension config object.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * @return string The QQ Connect APPID.
	 */
	public function getAppId(): string {
		return (string)$this->get( 'QQConnectAppId', '' );
	}

	/**
	 * @return string The QQ Connect APP KEY (secret).
	 */
	public function getAppKey(): string {
		return (string)$this->get( 'QQConnectAppKey', '' );
	}

	/**
	 * @return string|null The configured redirect URI, or null to auto-generate.
	 */
	public function getRedirectUri(): ?string {
		$val = $this->get( 'QQConnectRedirectUri', null );
		return $val === null ? null : (string)$val;
	}

	/**
	 * @return bool Whether test/placeholder mode is enabled.
	 */
	public function isTestMode(): bool {
		return (bool)$this->get( 'QQConnectTestMode', true );
	}

	/**
	 * @return bool Whether editing requires a bound QQ account.
	 */
	public function isRequireBind(): bool {
		return (bool)$this->get( 'QQConnectRequireBind', false );
	}

	/**
	 * @return string The comma-separated QQ Connect scopes to request.
	 */
	public function getScopes(): string {
		return (string)$this->get( 'QQConnectScopes', 'get_user_info' );
	}

	/**
	 * Returns true when the extension has enough configuration to actually
	 * perform a real OAuth flow (APPID and APP KEY both non-empty). Test mode
	 * can be active without these being set.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool {
		return $this->getAppId() !== '' && $this->getAppKey() !== '';
	}

	/**
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	private function get( string $key, $default = null ) {
		try {
			return $this->config->has( $key ) ? $this->config->get( $key ) : $default;
		} catch ( ConfigException $e ) {
			return $default;
		}
	}
}
