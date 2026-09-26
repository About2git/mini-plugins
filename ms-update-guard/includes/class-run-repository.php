<?php
/**
 * Persistence of runs with optimistic locking and leases.
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Every write checks row_version, so two workers or a duplicate callback cannot
 * both move a run (F08). A lease marks the worker currently driving a run.
 */
class Run_Repository {

	/**
	 * Create a run. Fails when the site already has a run holding its lock (F01).
	 *
	 * @param int    $site_id    Site id.
	 * @param array  $components Components: type, slug, name.
	 * @param string $source     manual|schedule|cli.
	 * @param array  $data       Initial data.
	 * @return array|\WP_Error Run.
	 */
	public static function create( $site_id, array $components, $source = 'manual', array $data = array() ) {
		global $wpdb;

		$existing = self::active_for_site( $site_id );
		if ( $existing ) {
			return new \WP_Error( 'msug_site_busy', sprintf( 'Site %d already has run %s in state %s.', $site_id, $existing['run_id'], $existing['state'] ), $existing['run_id'] );
		}

		$run_id = Util::uuid();
		$now    = Util::now();
		$ok     = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
			Schema::table( 'runs' ),
			array(
				'run_id'        => $run_id,
				'site_id'       => (int) $site_id,
				'state'         => States::QUEUED,
				'active_lock'   => (int) $site_id,
				'components'    => Util::json( array_values( $components ) ),
				'data'          => Util::json( $data ),
				'source'        => substr( (string) $source, 0, 32 ),
				'created_by'    => get_current_user_id(),
				'next_check_at' => $now,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			// The UNIQUE key on active_lock lost a race against a parallel create.
			return new \WP_Error( 'msug_site_busy', sprintf( 'Site %d is locked by another run.', $site_id ) );
		}
		Journal::add(
			$run_id,
			$site_id,
			'RUN_CREATED',
			array(
				'components' => $components,
				'source'     => $source,
			),
			array( 'state_to' => States::QUEUED )
		);
		return self::get( $run_id );
	}

	/**
	 * Load one run.
	 *
	 * @param string $run_id Run id.
	 * @return array|null
	 */
	public static function get( $run_id ) {
		global $wpdb;
		$table = Schema::table( 'runs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, state must be read fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %s', $table, $run_id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * The run currently holding a site's lock.
	 *
	 * @param int $site_id Site id.
	 * @return array|null
	 */
	public static function active_for_site( $site_id ) {
		global $wpdb;
		$table = Schema::table( 'runs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE active_lock = %d', $table, $site_id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Runs whose next check is due and that no worker currently leases.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function due( $limit = 20 ) {
		global $wpdb;
		$table        = Schema::table( 'runs' );
		$now          = Util::now();
		$states       = array_merge( array( States::QUEUED ), States::active() );
		$placeholders = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		$args         = array_merge( array( $table ), $states, array( $now, $now, (int) $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a list of %s built above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE state IN ({$placeholders}) AND ( next_check_at IS NULL OR next_check_at <= %s ) AND ( lease_until IS NULL OR lease_until < %s ) ORDER BY next_check_at ASC, id ASC LIMIT %d", $args ), ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Number of runs currently working on a child site (parallelism limit, spec 7).
	 *
	 * @return int
	 */
	public static function count_active() {
		global $wpdb;
		$table        = Schema::table( 'runs' );
		$states       = States::active();
		$placeholders = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a list of %s built above.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE state IN ({$placeholders})", array_merge( array( $table ), $states ) ) );
	}

	/**
	 * Recent runs for the admin list.
	 *
	 * @param array $args site_id, state, limit, offset.
	 * @return array
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table  = Schema::table( 'runs' );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['site_id'] ) ) {
			$where[]  = 'site_id = %d';
			$params[] = (int) $args['site_id'];
		}
		if ( ! empty( $args['state'] ) ) {
			$where[]  = 'state = %s';
			$params[] = (string) $args['state'];
		}
		if ( ! empty( $args['open'] ) ) {
			$where[] = 'active_lock IS NOT NULL';
		}
		$params[] = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
		$params[] = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
		array_unshift( $params, $table );
		$sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- $sql only holds fixed fragments and placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Take the lease on a run. Returns the fresh run or null when someone else holds it.
	 *
	 * @param array  $run     Run as loaded.
	 * @param string $owner   Worker id.
	 * @param int    $seconds Lease length.
	 * @return array|null
	 */
	public static function claim( array $run, $owner, $seconds ) {
		global $wpdb;
		$table = Schema::table( 'runs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-set on own table.
		$affected = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET lease_owner = %s, lease_until = %s, row_version = row_version + 1 WHERE id = %d AND row_version = %d AND ( lease_until IS NULL OR lease_until < %s )', $table, $owner, Util::now( $seconds ), $run['id'], $run['row_version'], Util::now() ) );
		return 1 === (int) $affected ? self::get( $run['run_id'] ) : null;
	}

	/**
	 * Drop the lease.
	 *
	 * @param string $run_id Run id.
	 * @param string $owner  Worker id.
	 * @return void
	 */
	public static function release( $run_id, $owner ) {
		global $wpdb;
		$table = Schema::table( 'runs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET lease_owner = NULL, lease_until = NULL WHERE run_id = %s AND lease_owner = %s', $table, $run_id, $owner ) );
	}

	/**
	 * Persist field changes without changing the state.
	 *
	 * @param array $run     Run (row_version must be current).
	 * @param array $changes data, next_check_at, deadline_at, attempt, reason, severity.
	 * @return array|\WP_Error Fresh run.
	 */
	public static function save( array $run, array $changes ) {
		return self::write( $run, $changes, null );
	}

	/**
	 * Move a run to another state and journal it.
	 *
	 * @param array  $run      Run.
	 * @param string $to       Target state.
	 * @param array  $changes  Other field changes.
	 * @param string $event    Journal event name.
	 * @param array  $evidence Journal data.
	 * @return array|\WP_Error Fresh run.
	 */
	public static function transition( array $run, $to, array $changes, $event, array $evidence = array() ) {
		if ( ! States::can( $run['state'], $to ) ) {
			return new \WP_Error( 'msug_bad_transition', sprintf( 'Transition %s -> %s is not allowed.', $run['state'], $to ) );
		}
		$fresh = self::write( $run, $changes, $to );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}
		Journal::add(
			$run['run_id'],
			$run['site_id'],
			$event,
			$evidence,
			array(
				'state_from' => $run['state'],
				'state_to'   => $to,
				'severity'   => isset( $changes['severity'] ) ? $changes['severity'] : null,
			)
		);
		return $fresh;
	}

	/**
	 * Manually resolve a FAIL/UNKNOWN run and release the site lock.
	 *
	 * @param string $run_id Run id.
	 * @param string $note   Operator note.
	 * @return true|\WP_Error
	 */
	public static function resolve( $run_id, $note ) {
		global $wpdb;
		$run = self::get( $run_id );
		if ( ! $run ) {
			return new \WP_Error( 'msug_not_found', 'Run not found.' );
		}
		if ( ! States::is_terminal( $run['state'] ) ) {
			return new \WP_Error( 'msug_not_terminal', 'Only finished runs can be resolved. Wait for the run or let it time out.' );
		}
		$table = Schema::table( 'runs' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET active_lock = NULL, resolved_at = %s, resolved_by = %d, row_version = row_version + 1 WHERE run_id = %s', $table, Util::now(), get_current_user_id(), $run_id ) );
		Journal::add( $run_id, $run['site_id'], 'MANUAL_RESOLVED', array( 'note' => Util::short( $note, 500 ) ), array( 'state_from' => $run['state'] ) );
		Alerts::resolve_run( $run_id );
		return true;
	}

	/**
	 * Shared UPDATE with compare-and-set on row_version.
	 *
	 * @param array       $run     Run.
	 * @param array       $changes Changes.
	 * @param string|null $state   New state or null.
	 * @return array|\WP_Error
	 */
	private static function write( array $run, array $changes, $state ) {
		global $wpdb;
		$fields = array(
			'updated_at'  => Util::now(),
			'row_version' => (int) $run['row_version'] + 1,
		);
		foreach ( array( 'next_check_at', 'deadline_at', 'reason', 'severity' ) as $key ) {
			if ( array_key_exists( $key, $changes ) ) {
				$fields[ $key ] = $changes[ $key ];
			}
		}
		if ( array_key_exists( 'attempt', $changes ) ) {
			$fields['attempt'] = (int) $changes['attempt'];
		}
		if ( array_key_exists( 'data', $changes ) ) {
			$fields['data'] = Util::json( $changes['data'] );
		}
		if ( array_key_exists( 'components', $changes ) ) {
			$fields['components'] = Util::json( array_values( $changes['components'] ) );
		}
		if ( null !== $state ) {
			$fields['state'] = $state;
			if ( States::is_terminal( $state ) ) {
				$fields['finished_at']   = Util::now();
				$fields['next_check_at'] = null;
				$fields['lease_until']   = null;
				$fields['lease_owner']   = null;
				if ( ! States::holds_site_lock( $state ) ) {
					$fields['active_lock'] = null;
				}
			}
		}
		$where    = array(
			'id'          => (int) $run['id'],
			'row_version' => (int) $run['row_version'],
		);
		$affected = $wpdb->update( Schema::table( 'runs' ), $fields, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-set.
		if ( 1 !== (int) $affected ) {
			return new \WP_Error( 'msug_conflict', 'Run changed concurrently; reload and retry.' );
		}
		return self::get( $run['run_id'] );
	}

	/**
	 * Decode JSON columns.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	private static function hydrate( array $row ) {
		$row['id']          = (int) $row['id'];
		$row['site_id']     = (int) $row['site_id'];
		$row['row_version'] = (int) $row['row_version'];
		$row['attempt']     = (int) $row['attempt'];
		$row['components']  = Util::unjson( $row['components'] );
		$row['data']        = Util::unjson( $row['data'] );
		return $row;
	}
}
