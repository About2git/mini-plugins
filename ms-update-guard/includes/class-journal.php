<?php
/**
 * Append-only event journal (F07).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Writes immutable events. Every state change and every external signal lands here.
 */
class Journal {

	/**
	 * Append one event.
	 *
	 * @param string $run_id  Run id or '' for site-level events.
	 * @param int    $site_id Site id.
	 * @param string $event   Event name, e.g. BACKUP_READY.
	 * @param array  $data    Evidence (never secrets or personal data).
	 * @param array  $meta    state_from, state_to, severity, actor.
	 * @return int Insert id, 0 on failure.
	 */
	public static function add( $run_id, $site_id, $event, array $data = array(), array $meta = array() ) {
		global $wpdb;
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table, append-only.
			Schema::table( 'events' ),
			array(
				'run_id'     => (string) $run_id,
				'site_id'    => (int) $site_id,
				'event'      => substr( (string) $event, 0, 64 ),
				'state_from' => isset( $meta['state_from'] ) ? $meta['state_from'] : null,
				'state_to'   => isset( $meta['state_to'] ) ? $meta['state_to'] : null,
				'severity'   => isset( $meta['severity'] ) ? $meta['severity'] : null,
				'actor'      => isset( $meta['actor'] ) ? substr( (string) $meta['actor'], 0, 64 ) : self::actor(),
				'data'       => Util::json( $data ),
				'created_at' => Util::now(),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Events of one run in order.
	 *
	 * @param string $run_id Run id.
	 * @return array
	 */
	public static function for_run( $run_id ) {
		global $wpdb;
		$table = Schema::table( 'events' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table; live journal must not be cached.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %s ORDER BY id ASC', $table, $run_id ), ARRAY_A );
	}

	/**
	 * Latest site-level events (updates outside the Guard, config drift).
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function site_events( $limit = 50 ) {
		global $wpdb;
		$table = Schema::table( 'events' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE run_id = '' ORDER BY id DESC LIMIT %d", $table, $limit ), ARRAY_A );
	}

	/**
	 * Who acts: WP-CLI, a user, or the system.
	 *
	 * @return string
	 */
	public static function actor() {
		$user = get_current_user_id();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $user ? 'cli:user:' . $user : 'cli';
		}
		return $user ? 'user:' . $user : 'system';
	}

	/**
	 * Delete journal rows of finished runs older than the retention period.
	 *
	 * @param int $days Retention in days.
	 * @return void
	 */
	public static function cleanup( $days ) {
		global $wpdb;
		$cutoff = Util::now( -DAY_IN_SECONDS * max( 30, (int) $days ) );
		$events = Schema::table( 'events' );
		$recv   = Schema::table( 'receipts' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Retention cleanup of own tables.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $events, $cutoff ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE received_at < %s', $recv, Util::now( -DAY_IN_SECONDS * 30 ) ) );
		// phpcs:enable
	}
}
