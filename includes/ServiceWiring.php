<?php
/**
 * Service wiring for the QQConnect extension.
 *
 * Declares three services used across the extension:
 *  - QQConnect.Config: typed wrapper over extension config.
 *  - QQConnect.Store: database access for the qqconnect_users mapping table.
 *  - QQConnect.Client: QQ Connect OAuth2 HTTP client.
 *
 * Services are resolved by MediaWiki's ServiceWiring mechanism.
 */

use MediaWiki\Extension\QQConnect\QQClient;
use MediaWiki\Extension\QQConnect\QQConnectConfig;
use MediaWiki\Extension\QQConnect\QQStore;
use MediaWiki\MediaWikiServices;

return [
	'QQConnect.Config' => static function ( MediaWikiServices $services ): QQConnectConfig {
		// Manifest v2 with config_prefix 'wg' merges our settings (e.g.
		// QQConnectAppId) into MainConfig, so we read them from the main
		// config object. (ConfigRepository::get() is deprecated and does not
		// return a Config object; see PluggableAuth's ServiceWiring for the
		// canonical pattern.)
		return new QQConnectConfig( $services->getMainConfig() );
	},

	'QQConnect.Store' => static function ( MediaWikiServices $services ): QQStore {
		return new QQStore(
			$services->getDBLoadBalancer(),
			$services->getUserFactory()
		);
	},

	'QQConnect.Client' => static function ( MediaWikiServices $services ): QQClient {
		return new QQClient(
			$services->getService( 'QQConnect.Config' )
		);
	},
];
