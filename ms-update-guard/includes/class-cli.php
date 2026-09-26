<?php
/**
 * WP-CLI commands. System cron drives the Guard through `wp msug tick`.
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Operate MS Update Guard.
 */
class CLI {

	/**
	 * Advance all due runs, enqueue scheduled runs, send reminders, ping the heartbeat.
	 *
	 * Run every minute from system cron:
	 *   * * * * * cd /path/to/wp && wp msug tick --quiet
	 *
	 * [--budget=<seconds>]
	 * : Time budget. Default 50.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 * @return void
	 */
	public function tick( $args, $assoc ) {
		$scheduled          = Plugin::schedule_all();
		$stats              = Plugin::engine()->tick( isset( $assoc['budget'] ) ? (int) $assoc['budget'] : 50 );
		$stats['scheduled'] = count( $scheduled );
		\WP_CLI::log( Util::json( $stats ) );
	}

	/**
	 * Create a run for one site.
	 *
	 * <site_id>
	 * : MainWP site id.
	 *
	 * [--plugins=<slugs>]
	 * : Comma separated plugin slugs (e.g. akismet/akismet.php).
	 *
	 * [--themes=<slugs>]
	 * : Comma separated theme slugs.
	 *
	 * [--core]
	 * : Include the WordPress core update.
	 *
	 * [--types=<types>]
	 * : Instead of explicit slugs: all pending updates of these types (core,plugin,theme).
	 *
	 * [--accept-preexisting-defect]
	 * : Continue although the pre-update test fails (documented in the journal).
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 * @return void
	 */
	public function enqueue( $args, $assoc ) {
		$site_id = (int) $args[0];
		if ( ! empty( $assoc['types'] ) ) {
			$selection = array( 'types' => array_map( 'trim', explode( ',', $assoc['types'] ) ) );
		} else {
			$selection = array();
			foreach ( array(
				'plugins' => 'plugin',
				'themes'  => 'theme',
			) as $key => $type ) {
				if ( ! empty( $assoc[ $key ] ) ) {
					foreach ( array_filter( array_map( 'trim', explode( ',', $assoc[ $key ] ) ) ) as $slug ) {
						$selection[] = array(
							'type' => $type,
							'slug' => $slug,
						);
					}
				}
			}
			if ( ! empty( $assoc['core'] ) ) {
				$selection[] = array(
					'type' => 'core',
					'slug' => 'wordpress',
				);
			}
		}
		$run = Plugin::engine()->enqueue( $site_id, $selection, 'cli', array( 'accept_preexisting_defect' => ! empty( $assoc['accept-preexisting-defect'] ) ) );
		if ( is_wp_error( $run ) ) {
			\WP_CLI::error( $run->get_error_message() );
		}
		\WP_CLI::success( 'Queued run ' . $run['run_id'] );
	}

	/**
	 * Show runs or one run with its journal.
	 *
	 * [<run_id>]
	 * : Run id.
	 *
	 * [--open]
	 * : Only runs that hold a site lock.
	 *
	 * [--format=<format>]
	 * : table|json. Default table.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 * @return void
	 */
	public function status( $args, $assoc ) {
		$format = isset( $assoc['format'] ) ? $assoc['format'] : 'table';
		if ( ! empty( $args[0] ) ) {
			$run = Run_Repository::get( $args[0] );
			if ( ! $run ) {
				\WP_CLI::error( 'Run not found.' );
			}
			if ( 'json' === $format ) {
				$run['events'] = Journal::for_run( $run['run_id'] );
				\WP_CLI::log( Util::json( $run ) );
				return;
			}
			\WP_CLI::log( sprintf( '%s  site %d  %s  %s', $run['run_id'], $run['site_id'], $run['state'], (string) $run['reason'] ) );
			$rows = array();
			foreach ( Journal::for_run( $run['run_id'] ) as $e ) {
				$rows[] = array(
					'time'  => $e['created_at'],
					'event' => $e['event'],
					'from'  => $e['state_from'],
					'to'    => $e['state_to'],
					'data'  => substr( $e['data'], 0, 160 ),
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'time', 'event', 'from', 'to', 'data' ) );
			return;
		}
		$rows = array();
		foreach ( Run_Repository::query(
			array(
				'open'  => ! empty( $assoc['open'] ),
				'limit' => 50,
			)
		) as $run ) {
			$rows[] = array(
				'run_id'  => $run['run_id'],
				'site'    => $run['site_id'],
				'state'   => $run['state'],
				'reason'  => $run['reason'],
				'locked'  => null === $run['active_lock'] ? '' : 'yes',
				'created' => $run['created_at'],
			);
		}
		\WP_CLI\Utils\format_items( $format, $rows, array( 'run_id', 'site', 'state', 'reason', 'locked', 'created' ) );
	}

	/**
	 * Resolve a FAIL/UNKNOWN run after manual review and release the site lock.
	 *
	 * <run_id>
	 * : Run id.
	 *
	 * --note=<note>
	 * : What was checked or done.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 * @return void
	 */
	public function resolve( $args, $assoc ) {
		$result = Run_Repository::resolve( $args[0], isset( $assoc['note'] ) ? $assoc['note'] : '' );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( 'Resolved.' );
	}

	/**
	 * Check configuration and integrations.
	 *
	 * [<site_id>]
	 * : Also run the backup provider preflight for this site (read-only).
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 * @return void
	 */
	public function doctor( $args, $assoc ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		foreach ( Admin::health( isset( $args[0] ) ? (int) $args[0] : 0 ) as $row ) {
			$line = sprintf( '[%s] %s: %s', strtoupper( $row['status'] ), $row['check'], $row['detail'] );
			if ( 'ok' === $row['status'] ) {
				\WP_CLI::log( $line );
			} else {
				\WP_CLI::warning( $line );
			}
		}
	}
}

\WP_CLI::add_command( 'msug', CLI::class );
