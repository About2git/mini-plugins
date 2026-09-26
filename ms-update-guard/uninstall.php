<?php
/**
 * Uninstall. The journal is audit evidence, so tables are only dropped when
 * MSUG_DELETE_DATA_ON_UNINSTALL is defined as true in wp-config.php.
 *
 * @package MSUpdateGuard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'MSUG_DELETE_DATA_ON_UNINSTALL' ) || true !== MSUG_DELETE_DATA_ON_UNINSTALL ) {
	return;
}

global $wpdb;
foreach ( array( 'runs', 'events', 'receipts', 'alerts' ) as $msug_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->base_prefix . 'msug_' . $msug_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Fixed table names.
}
foreach ( array( 'msug_settings', 'msug_sites', 'msug_secrets', 'msug_db_version', 'msug_heartbeat', 'msug_last_schedule', 'msug_tick_lock' ) as $msug_option ) {
	delete_option( $msug_option );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'msug\\_versions\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cleanup.
