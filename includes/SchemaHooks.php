<?php
/**
 * Schema update handler for the QQConnect extension.
 *
 * Registers the qqconnect_users table with the MediaWiki DatabaseUpdater via
 * the LoadExtensionSchemaUpdates hook.
 *
 * NOTE: This handler deliberately does NOT use any DI services. Per the
 * LoadExtensionSchemaUpdatesHook documentation, the hook runs in a context
 * where the global service locator is not initialized, so the handler class
 * must be constructed with only plain (no-services) ObjectFactory specs.
 */

namespace MediaWiki\Extension\QQConnect;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param \DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dbType = $updater->getDB()->getType();
		$dir = dirname( __DIR__ ) . '/schema';
		$sqlFile = "$dir/$dbType/tables-generated.sql";
		if ( file_exists( $sqlFile ) ) {
			$updater->addExtensionTable( 'qqconnect_users', $sqlFile );
		}
	}
}
