<?php
/**
 * Database schema and migrations.
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin tables with dbDelta().
 */
class Schema {

	/**
	 * Table name helper.
	 *
	 * @param string $name Short name: runs, events, receipts, alerts.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->base_prefix . 'msug_' . $name;
	}

	/**
	 * Activation hook and upgrade routine.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$runs    = self::table( 'runs' );
		$events  = self::table( 'events' );
		$recv    = self::table( 'receipts' );
		$alerts  = self::table( 'alerts' );

		// active_lock holds the site id while a run blocks further Guard runs on that site.
		// The UNIQUE key makes "one active run per site" a database guarantee (F01).
		dbDelta(
			"CREATE TABLE {$runs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id char(36) NOT NULL,
				site_id bigint(20) unsigned NOT NULL,
				state varchar(32) NOT NULL,
				severity varchar(16) DEFAULT NULL,
				reason varchar(191) DEFAULT NULL,
				active_lock bigint(20) unsigned DEFAULT NULL,
				components longtext NOT NULL,
				data longtext NOT NULL,
				source varchar(32) NOT NULL DEFAULT 'manual',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				attempt int(10) unsigned NOT NULL DEFAULT 0,
				next_check_at datetime DEFAULT NULL,
				deadline_at datetime DEFAULT NULL,
				lease_until datetime DEFAULT NULL,
				lease_owner varchar(64) DEFAULT NULL,
				row_version int(10) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				finished_at datetime DEFAULT NULL,
				resolved_at datetime DEFAULT NULL,
				resolved_by bigint(20) unsigned DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_id (run_id),
				UNIQUE KEY active_lock (active_lock),
				KEY state_next (state,next_check_at),
				KEY site_id (site_id)
			) {$charset};"
		);

		// Append-only journal. No code path updates or deletes rows except retention cleanup.
		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id varchar(36) NOT NULL DEFAULT '',
				site_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event varchar(64) NOT NULL,
				state_from varchar(32) DEFAULT NULL,
				state_to varchar(32) DEFAULT NULL,
				severity varchar(16) DEFAULT NULL,
				actor varchar(64) NOT NULL DEFAULT 'system',
				data longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY run_id (run_id,id),
				KEY site_event (site_id,event),
				KEY created_at (created_at)
			) {$charset};"
		);

		// One row per accepted signed message; the primary key rejects replays.
		dbDelta(
			"CREATE TABLE {$recv} (
				event_id varchar(64) NOT NULL,
				run_id varchar(36) NOT NULL DEFAULT '',
				channel varchar(32) NOT NULL,
				received_at datetime NOT NULL,
				PRIMARY KEY  (event_id),
				KEY received_at (received_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$alerts} (
				dedupe_key varchar(191) NOT NULL,
				run_id varchar(36) NOT NULL DEFAULT '',
				severity varchar(16) NOT NULL,
				first_sent_at datetime NOT NULL,
				last_sent_at datetime NOT NULL,
				send_count int(10) unsigned NOT NULL DEFAULT 1,
				resolved tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (dedupe_key),
				KEY run_id (run_id)
			) {$charset};"
		);

		update_option( 'msug_db_version', MSUG_DB_VERSION, false );
	}

	/**
	 * Run install when the stored schema version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'msug_db_version' ) !== MSUG_DB_VERSION ) {
			self::install();
		}
	}
}
