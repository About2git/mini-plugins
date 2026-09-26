<?php
/**
 * Decides whether a WPTC restore point is proven usable (F02/F03).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Pure evaluation of the evidence collected from the child. No I/O here, so every
 * negative case from the acceptance table can be unit-tested.
 *
 * Sources (see docs/ap0-schnittstellenmatrix.md):
 *  - $op    : Time Capsule abilities_v2 "operation_status" of the backup we started (or null when a
 *             fresh existing restore point is reused).
 *  - $site  : abilities_v2 "site" (account/plugin state, active operations, last_attempt_at =
 *             WPTC last_backup_time, which WPTC only writes in its regular completion path).
 *  - $probe : answer of the read-only evidence probe (child-probe/), the only source that shows
 *             database dump, upload completion and backup errors per backup id.
 */
class Backup_Evidence {

	const SAME_CLOCK_TOLERANCE  = 60;
	const CROSS_CLOCK_TOLERANCE = 300;
	const PROBE_VERSION         = 1;

	/**
	 * Evaluate.
	 *
	 * @param array|null $op      Operation status row or null (reuse mode).
	 * @param array|null $site    Site observation.
	 * @param array|null $probe   Probe answer.
	 * @param array      $context expected_home, not_before (unix, parent clock), max_age (s), now.
	 * @return array ok(bool), pending(bool), code, reasons[], restore_point[]
	 */
	public static function evaluate( $op, $site, $probe, array $context ) {
		$now = isset( $context['now'] ) ? (int) $context['now'] : time();

		if ( is_array( $op ) ) {
			$state = isset( $op['state'] ) ? $op['state'] : '';
			if ( in_array( $state, array( 'queued', 'running', 'verifying', 'reconciliation_required' ), true ) ) {
				return self::pending( 'backup_running' );
			}
			if ( in_array( $state, array( 'failed', 'cancelled' ), true ) ) {
				return self::reject( 'backup_' . $state );
			}
			if ( ! in_array( $state, array( 'succeeded', 'uncertain' ), true ) ) {
				return self::reject( 'backup_state_unknown', array( 'state' => $state ) );
			}
		}

		if ( ! is_array( $site ) || empty( $site['ok'] ) ) {
			return self::reject( 'site_observation_missing' );
		}
		if ( ! empty( $site['active_operation_count'] ) ) {
			return self::pending( 'backup_running' );
		}
		if ( 'ready' !== ( isset( $site['plugin_state'] ) ? $site['plugin_state'] : '' ) ) {
			return self::reject( 'wptc_plugin_missing' );
		}
		if ( 'connected' !== ( isset( $site['account_state'] ) ? $site['account_state'] : '' ) ) {
			return self::reject( 'wptc_account_disconnected' );
		}
		$last_completed = Util::parse_iso( isset( $site['last_attempt_at'] ) ? $site['last_attempt_at'] : null );
		if ( false === $last_completed ) {
			return self::reject( 'no_completed_backup' );
		}

		if ( is_array( $op ) ) {
			$started = Util::parse_iso( isset( $op['started_at'] ) ? $op['started_at'] : null );
			if ( false === $started ) {
				return self::reject( 'operation_without_start' );
			}
			// WPTC writes last_backup_time only in its regular completion path, never on a forced stop.
			// A value older than our start means our backup did not complete.
			if ( $last_completed < $started - self::SAME_CLOCK_TOLERANCE ) {
				return self::reject(
					'backup_not_completed',
					array(
						'last_completed' => Util::iso( $last_completed ),
						'started'        => Util::iso( $started ),
					)
				);
			}
		}

		if ( ! is_array( $probe ) || empty( $probe['ok'] ) || (int) ( isset( $probe['probe_version'] ) ? $probe['probe_version'] : 0 ) < self::PROBE_VERSION ) {
			// Without the probe nothing shows that the database and all files reached remote storage.
			return self::reject( 'evidence_insufficient' );
		}

		$backup_id = isset( $probe['backup_id'] ) ? (int) $probe['backup_id'] : 0;
		if ( $backup_id <= 0 || $backup_id !== $last_completed ) {
			return self::reject(
				'backup_id_mismatch',
				array(
					'probe' => $backup_id,
					'site'  => $last_completed,
				)
			);
		}
		if ( ! empty( $context['expected_home'] ) && self::host( $probe['home_url'] ?? '' ) !== self::host( $context['expected_home'] ) ) {
			return self::reject( 'backup_other_site', array( 'probe_home' => Util::short( $probe['home_url'] ?? '', 200 ) ) );
		}
		if ( ! empty( $probe['in_progress'] ) ) {
			return self::pending( 'backup_running' );
		}
		if ( empty( $probe['meta_row'] ) || (int) ( $probe['files_count'] ?? 0 ) < 1 ) {
			return self::reject( 'files_missing' );
		}
		if ( empty( $probe['db_dump']['present'] ) ) {
			return self::reject( 'database_missing' );
		}
		if ( empty( $probe['db_dump']['complete'] ) || ! empty( $probe['incomplete_uploads'] ) ) {
			return self::reject( 'upload_incomplete', array( 'incomplete_uploads' => (int) ( $probe['incomplete_uploads'] ?? 0 ) ) );
		}
		if ( (int) ( $probe['success_complete_time'] ?? 0 ) < $backup_id ) {
			return self::reject( 'completion_marker_missing' );
		}
		if ( ! empty( $probe['error_count'] ) ) {
			return self::reject( 'backup_errors', array( 'error_count' => (int) $probe['error_count'] ) );
		}
		if ( empty( $probe['cloud']['connected'] ) || empty( $probe['cloud']['repo'] ) ) {
			return self::reject( 'remote_unconfirmed' );
		}

		$not_before = isset( $context['not_before'] ) ? (int) $context['not_before'] : 0;
		if ( $not_before && $backup_id < $not_before - self::CROSS_CLOCK_TOLERANCE ) {
			return self::reject(
				'backup_not_fresh',
				array(
					'backup'     => Util::iso( $backup_id ),
					'not_before' => Util::iso( $not_before ),
				)
			);
		}
		$max_age = isset( $context['max_age'] ) ? (int) $context['max_age'] : 0;
		if ( $max_age && $now - $backup_id > $max_age + self::CROSS_CLOCK_TOLERANCE ) {
			return self::reject( 'backup_too_old', array( 'backup' => Util::iso( $backup_id ) ) );
		}
		if ( $backup_id > $now + self::CROSS_CLOCK_TOLERANCE ) {
			return self::reject( 'backup_in_future', array( 'backup' => Util::iso( $backup_id ) ) );
		}

		return array(
			'ok'            => true,
			'pending'       => false,
			'code'          => 'backup_ready',
			'reasons'       => array(),
			'restore_point' => array(
				'backup_id'   => $backup_id,
				'created_at'  => Util::iso( $backup_id ),
				'scope'       => 'files+database',
				'files_count' => (int) $probe['files_count'],
				'remote'      => Util::short( $probe['cloud']['repo'], 40 ),
				'label'       => Util::short( $probe['backup_name'] ?? '', 100 ),
				'verified_at' => Util::iso( $now ),
			),
		);
	}

	/**
	 * Host of a URL, lowercase, without www.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host( $url ) {
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		return preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Still running.
	 *
	 * @param string $code Code.
	 * @return array
	 */
	private static function pending( $code ) {
		return array(
			'ok'            => false,
			'pending'       => true,
			'code'          => $code,
			'reasons'       => array(),
			'restore_point' => array(),
		);
	}

	/**
	 * Final negative answer.
	 *
	 * @param string $code    Code.
	 * @param array  $details Details.
	 * @return array
	 */
	private static function reject( $code, array $details = array() ) {
		return array(
			'ok'            => false,
			'pending'       => false,
			'code'          => $code,
			'reasons'       => $details,
			'restore_point' => array(),
		);
	}
}
