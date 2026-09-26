<?php
/**
 * Signed callback endpoint for runner results (spec 5, F08, F10).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

use MSUpdateGuard\Adapters\HTTP_Test_Runner;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/ms-update-guard/v1/runner-result
 *
 * Accepts only bodies signed with the callback secret (current or previous key), inside their
 * time envelope, with an event id never seen before. A repeated delivery is acknowledged but
 * changes nothing; the first stored result of a test wins.
 */
class Rest_Controller {

	const NS = 'ms-update-guard/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_route(
			self::NS,
			'/runner-result',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'runner_result' ),
				'permission_callback' => array( __CLASS__, 'verify' ),
			)
		);
	}

	/**
	 * Signature and envelope check. Runs before the callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function verify( \WP_REST_Request $request ) {
		$body = $request->get_body();
		if ( '' === $body || strlen( $body ) > Signature::MAX_BODY ) {
			return new \WP_Error( 'msug_bad_request', 'Empty or oversized body.', array( 'status' => 400 ) );
		}
		$secrets = array( Settings::secret( 'callback' ), Settings::secret( 'callback_previous' ) );
		if ( ! Signature::verify( $body, (string) $request->get_header( 'x_msug_signature' ), $secrets ) ) {
			Journal::add(
				'',
				0,
				'CALLBACK_REJECTED',
				array( 'why' => 'signature' ),
				array(
					'severity' => States::SEVERITY_WARNING,
					'actor'    => 'runner',
				)
			);
			return new \WP_Error( 'msug_bad_signature', 'Invalid signature.', array( 'status' => 401 ) );
		}
		$message = json_decode( $body, true );
		if ( ! is_array( $message ) ) {
			return new \WP_Error( 'msug_bad_request', 'Invalid JSON.', array( 'status' => 400 ) );
		}
		$envelope = Signature::check_envelope( $message );
		if ( true !== $envelope ) {
			Journal::add(
				'',
				0,
				'CALLBACK_REJECTED',
				array( 'why' => $envelope ),
				array(
					'severity' => States::SEVERITY_WARNING,
					'actor'    => 'runner',
				)
			);
			return new \WP_Error( 'msug_bad_envelope', $envelope, array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Store a runner result.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function runner_result( \WP_REST_Request $request ) {
		global $wpdb;
		$message = json_decode( $request->get_body(), true );
		$run_id  = isset( $message['run_id'] ) ? (string) $message['run_id'] : '';
		$test_id = isset( $message['test_id'] ) ? (string) $message['test_id'] : '';
		if ( ! Util::is_uuid( $run_id ) || ! Util::is_uuid( $test_id ) ) {
			return new \WP_Error( 'msug_bad_request', 'run_id and test_id required.', array( 'status' => 400 ) );
		}

		// Replay protection: the event id can be used exactly once.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, atomic insert.
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (event_id, run_id, channel, received_at) VALUES (%s, %s, %s, %s)', Schema::table( 'receipts' ), $message['event_id'], $run_id, 'runner-result', Util::now() ) );
		if ( 1 !== (int) $inserted ) {
			Journal::add( $run_id, 0, 'CALLBACK_DUPLICATE', array( 'event_id' => $message['event_id'] ), array( 'actor' => 'runner' ) );
			return new \WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
		}

		$result = HTTP_Test_Runner::normalize_result( isset( $message['result'] ) ? $message['result'] : null, $run_id, $test_id );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$run = Run_Repository::get( $run_id );
			if ( ! $run ) {
				return new \WP_Error( 'msug_not_found', 'Unknown run.', array( 'status' => 404 ) );
			}
			$data = $run['data'];
			if ( States::PREFLIGHT === $run['state'] && isset( $data['preflight']['pretest']['test_id'] ) && $data['preflight']['pretest']['test_id'] === $test_id ) {
				$slot = &$data['preflight']['pretest'];
			} elseif ( States::TESTING === $run['state'] && isset( $data['test']['test_id'] ) && $data['test']['test_id'] === $test_id ) {
				$slot = &$data['test'];
			} else {
				Journal::add(
					$run_id,
					$run['site_id'],
					'CALLBACK_IGNORED',
					array(
						'test_id' => $test_id,
						'state'   => $run['state'],
					),
					array( 'actor' => 'runner' )
				);
				return new \WP_REST_Response( array( 'status' => 'ignored' ), 409 );
			}
			if ( ! empty( $slot['result'] ) ) {
				unset( $slot );
				Journal::add( $run_id, $run['site_id'], 'CALLBACK_DUPLICATE', array( 'test_id' => $test_id ), array( 'actor' => 'runner' ) );
				return new \WP_REST_Response( array( 'status' => 'already_recorded' ), 200 );
			}
			$slot['result'] = $result;
			$slot['status'] = 'result';
			$slot['source'] = 'callback';
			unset( $slot );

			$saved = Run_Repository::save(
				$run,
				array(
					'data'          => $data,
					'next_check_at' => Util::now(),
				)
			);
			if ( ! is_wp_error( $saved ) ) {
				Journal::add(
					$run_id,
					$run['site_id'],
					'TEST_RESULT',
					array(
						'source' => 'callback',
						'http'   => $result['http'],
						'smoke'  => $result['smoke'],
						'checks' => count( $result['checks'] ),
					),
					array( 'actor' => 'runner' )
				);
				return new \WP_REST_Response( array( 'status' => 'recorded' ), 200 );
			}
			usleep( 200000 );
		}
		// Nothing was stored, so the same message may be delivered again.
		$wpdb->delete( Schema::table( 'receipts' ), array( 'event_id' => $message['event_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		return new \WP_Error( 'msug_conflict', 'Run busy, retry.', array( 'status' => 503 ) );
	}
}
