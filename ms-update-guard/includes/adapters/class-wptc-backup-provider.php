<?php
/**
 * WP Time Capsule backups through MainWP Child.
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard\Adapters;

use MSUpdateGuard\Settings;
use MSUpdateGuard\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Uses two child endpoints, both reached through MainWP's signed connection:
 *
 *  1. MainWP Child "time_capsule" callable, operation "abilities_v2" (MainWP Child 6.2+):
 *     site, policy, start_backup (idempotent per request_ref), operation_status.
 *  2. The optional read-only evidence probe (child-probe/msug-wptc-evidence-probe.php) answering
 *     on "extra_execution". Without it a backup can be started but never proven, and every run
 *     ends BLOCKED with "evidence_insufficient" (see docs/ap0-schnittstellenmatrix.md, Gate 1).
 */
class WPTC_Backup_Provider implements Backup_Provider {

	/**
	 * Errors of the v2 protocol after which the same request may be repeated safely
	 * (the child de-duplicates by request_ref).
	 */
	const RETRYABLE = array( 'outcome_unknown', 'lock_busy', 'storage_unavailable', 'state_conflict', 'stale_generation', 'provider_unavailable' );

	/**
	 * Gateway.
	 *
	 * @var MainWP_Gateway
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param MainWP_Gateway $gateway Gateway.
	 */
	public function __construct( MainWP_Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $site_id Site id.
	 * @return array|\WP_Error
	 */
	public function preflight( $site_id ) {
		$caps = $this->v2( $site_id, 'capabilities', array() );
		if ( is_wp_error( $caps ) ) {
			return $this->fail( 'wptc_protocol_unavailable', $caps->get_error_message() );
		}
		foreach ( array( 'site', 'policy', 'start_backup', 'operation_status' ) as $needed ) {
			if ( ! in_array( $needed, (array) ( isset( $caps['operations'] ) ? $caps['operations'] : array() ), true ) ) {
				return $this->fail( 'wptc_protocol_incomplete', 'Child does not offer ' . $needed . ' (MainWP Child 6.2+ required).' );
			}
		}

		$site = $this->v2( $site_id, 'site', array() );
		if ( is_wp_error( $site ) ) {
			return $this->fail( 'wptc_site_unreadable', $site->get_error_message() );
		}
		if ( 'ready' !== $site['plugin_state'] ) {
			return $this->fail( 'wptc_plugin_missing' );
		}
		if ( 'connected' !== $site['account_state'] ) {
			return $this->fail( 'wptc_account_disconnected' );
		}
		if ( ! empty( $site['active_operation_count'] ) ) {
			return array(
				'ok'    => false,
				'retry' => true,
				'code'  => 'wptc_backup_already_running',
			);
		}

		$policy = $this->v2( $site_id, 'policy', array() );
		if ( is_wp_error( $policy ) ) {
			return $this->fail( 'wptc_policy_unreadable', $policy->get_error_message() );
		}
		if ( Settings::get( 'require_wptc_bbu_off' ) && ! empty( $policy['backup_before_update'] ) ) {
			// WPTC's own "backup before update" hooks the child's update flows and WordPress'
			// auto_update_* filters. The Guard already holds a proven restore point, so a second,
			// WPTC-driven backup would only add load and unclear timing (see AP0 matrix, row B6).
			return $this->fail( 'wptc_backup_before_update_enabled', 'Disable "Backup before updates" in WPTC for Guard-managed sites.' );
		}

		$probe = $this->probe( $site_id, 0 );
		if ( is_wp_error( $probe ) || empty( $probe['ok'] ) ) {
			return $this->fail( 'evidence_probe_missing', 'The read-only evidence probe does not answer; a restore point cannot be proven.' );
		}
		if ( empty( $probe['cloud']['connected'] ) ) {
			return $this->fail( 'wptc_remote_storage_not_connected' );
		}

		return array(
			'ok'      => true,
			'code'    => 'ready',
			'details' => array(
				'last_attempt_at' => $site['last_attempt_at'],
				'remote'          => Util::short( $probe['cloud']['repo'], 40 ),
				'probe_version'   => (int) $probe['probe_version'],
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $site_id Site id.
	 * @param string $run_id  Run id; doubles as request_ref, so a repeated call never starts a second backup.
	 * @return array|\WP_Error
	 */
	public function start( $site_id, $run_id ) {
		$policy = $this->v2( $site_id, 'policy', array() );
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}
		$result = $this->v2(
			$site_id,
			'start_backup',
			array(
				'scope'             => 'full',
				'label'             => 'MS Update Guard ' . substr( $run_id, 0, 8 ),
				'policy_generation' => $policy['policy_generation'],
			),
			$run_id
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'job_id'      => $result['operation_ref'],
			'state'       => $result['state'],
			'request_ref' => $run_id,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int        $site_id Site id.
	 * @param array|null $job     Job.
	 * @return array|\WP_Error
	 */
	public function evidence( $site_id, $job ) {
		$op = null;
		if ( is_array( $job ) && ! empty( $job['job_id'] ) ) {
			$op = $this->v2( $site_id, 'operation_status', array( 'operation_ref' => $job['job_id'] ) );
			if ( is_wp_error( $op ) ) {
				return $op;
			}
		}
		$site = $this->v2( $site_id, 'site', array() );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$probe          = null;
		$last_completed = Util::parse_iso( isset( $site['last_attempt_at'] ) ? $site['last_attempt_at'] : null );
		if ( false !== $last_completed && empty( $site['active_operation_count'] ) ) {
			$probe = $this->probe( $site_id, $last_completed );
			if ( is_wp_error( $probe ) ) {
				$probe = null;
			}
		}
		return array(
			'op'    => $op,
			'site'  => $site,
			'probe' => $probe,
		);
	}

	/**
	 * One abilities_v2 request.
	 *
	 * @param int         $site_id     Site id.
	 * @param string      $operation   Operation.
	 * @param array       $payload     Payload.
	 * @param string|null $request_ref Request ref for mutations.
	 * @return array|\WP_Error Response with ok=true, or WP_Error (data: retry flag).
	 */
	private function v2( $site_id, $operation, array $payload, $request_ref = null ) {
		$request = array(
			'protocol'  => '2',
			'operation' => $operation,
			'payload'   => (object) $payload,
		);
		if ( null !== $request_ref ) {
			$request['request_ref'] = $request_ref;
		}
		$response = $this->gateway->fetch(
			$site_id,
			'time_capsule',
			array(
				'mwp_action' => 'abilities_v2',
				'request'    => wp_json_encode( $request ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( $response->get_error_code(), $response->get_error_message(), array( 'retry' => true ) );
		}
		if ( ! isset( $response['protocol'] ) || '2' !== (string) $response['protocol'] ) {
			return new \WP_Error( 'wptc_protocol_unavailable', 'Unexpected Time Capsule response (MainWP Child 6.2+ with WPTC required).', array( 'retry' => false ) );
		}
		if ( empty( $response['ok'] ) ) {
			$code = isset( $response['code'] ) ? (string) $response['code'] : 'unknown';
			return new \WP_Error( 'wptc_' . $code, 'Time Capsule answered ' . $code . ' to ' . $operation . '.', array( 'retry' => in_array( $code, self::RETRYABLE, true ) ) );
		}
		return $response;
	}

	/**
	 * Ask the evidence probe.
	 *
	 * @param int $site_id   Site id.
	 * @param int $backup_id WPTC backup id (unix time) or 0 for a health check.
	 * @return array|\WP_Error
	 */
	private function probe( $site_id, $backup_id ) {
		$response = $this->gateway->fetch(
			$site_id,
			'extra_execution',
			array(
				'msug_probe'   => 'backup_evidence',
				'msug_version' => 1,
				'backup_id'    => (int) $backup_id,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['msug_probe'] ) && is_array( $response['msug_probe'] ) ? $response['msug_probe'] : new \WP_Error( 'evidence_probe_missing', 'Probe not installed on the child.' );
	}

	/**
	 * Negative preflight answer.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return array
	 */
	private function fail( $code, $message = '' ) {
		return array(
			'ok'      => false,
			'retry'   => false,
			'code'    => $code,
			'message' => Util::short( $message, 300 ),
		);
	}
}
