<?php
/**
 * Client for the external test runner (spec 5 and 6).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard\Adapters;

use MSUpdateGuard\Settings;
use MSUpdateGuard\Signature;
use MSUpdateGuard\Url_Policy;
use MSUpdateGuard\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Sends signed jobs. The runner resolves URLs itself from site_id/profile_id;
 * no target URL ever leaves the Parent.
 */
class HTTP_Test_Runner implements External_Test_Runner {

	const HTTP_STATES  = array( 'HTTP_PASS', 'HTTP_FAIL', 'HTTP_UNKNOWN' );
	const SMOKE_STATES = array( 'SMOKE_PASS', 'SMOKE_FAIL', 'SMOKE_SKIPPED', 'SMOKE_UNKNOWN' );

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $run     Run.
	 * @param string $test_id Test id.
	 * @param string $trigger Trigger.
	 * @return array|\WP_Error
	 */
	public function start( array $run, $test_id, $trigger ) {
		$config   = Settings::site( $run['site_id'] );
		$body     = Util::json(
			Signature::envelope(
				array(
					'run_id'     => $run['run_id'],
					'test_id'    => $test_id,
					'site_id'    => (int) $run['site_id'],
					'profile_id' => (string) $config['profile_id'],
					'trigger'    => $trigger,
				),
				300
			)
		);
		$response = $this->send( '/v1/runs', $body, 'trigger' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'accepted'  => 202 === $code || 200 === $code,
			'http_code' => $code,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $run     Run.
	 * @param string $test_id Test id.
	 * @return array|\WP_Error pending(bool), result.
	 */
	public function status( array $run, $test_id ) {
		$body     = Util::json(
			Signature::envelope(
				array(
					'run_id'  => $run['run_id'],
					'test_id' => $test_id,
				),
				120
			)
		);
		$response = $this->send( '/v1/status', $body, 'status' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$raw = (string) wp_remote_retrieve_body( $response );
		if ( ! Signature::verify( $raw, (string) wp_remote_retrieve_header( $response, Signature::HEADER ), array( Settings::secret( 'status' ) ) ) ) {
			return new \WP_Error( 'msug_runner_bad_signature', 'Runner status answer is not signed correctly.' );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['run_id'] ) || $data['run_id'] !== $run['run_id'] ) {
			return new \WP_Error( 'msug_runner_bad_answer', 'Runner status answer does not match the run.' );
		}
		if ( isset( $data['state'] ) && 'done' === $data['state'] && isset( $data['result'] ) ) {
			$result = self::normalize_result( $data['result'], $run['run_id'], $test_id );
			return is_wp_error( $result ) ? $result : array(
				'pending' => false,
				'result'  => $result,
			);
		}
		return array(
			'pending' => true,
			'state'   => isset( $data['state'] ) ? Util::short( $data['state'], 20 ) : 'unknown',
		);
	}

	/**
	 * Validate and reduce a runner result to what the Parent may store (no personal data).
	 *
	 * @param mixed  $r       Raw result.
	 * @param string $run_id  Expected run id.
	 * @param string $test_id Expected test id.
	 * @return array|\WP_Error
	 */
	public static function normalize_result( $r, $run_id, $test_id ) {
		if ( ! is_array( $r ) ) {
			return new \WP_Error( 'msug_result_invalid', 'Result is not an object.' );
		}
		if ( ( $r['run_id'] ?? '' ) !== $run_id || ( $r['test_id'] ?? '' ) !== $test_id ) {
			return new \WP_Error( 'msug_result_mismatch', 'Result belongs to another run or test.' );
		}
		$http  = in_array( $r['http'] ?? '', self::HTTP_STATES, true ) ? $r['http'] : 'HTTP_UNKNOWN';
		$smoke = in_array( $r['smoke'] ?? '', self::SMOKE_STATES, true ) ? $r['smoke'] : 'SMOKE_UNKNOWN';

		$checks = array();
		foreach ( array_slice( (array) ( $r['checks'] ?? array() ), 0, 50 ) as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$checks[] = array(
				'name'     => Util::short( $check['name'] ?? '', 80 ),
				'kind'     => Util::short( $check['kind'] ?? '', 20 ),
				'status'   => in_array( $check['status'] ?? '', array( 'pass', 'fail', 'skipped', 'error' ), true ) ? $check['status'] : 'error',
				'message'  => Util::short( $check['message'] ?? '', 200 ),
				'artifact' => isset( $check['artifact'] ) && preg_match( '/^[A-Za-z0-9_-]{8,64}$/', (string) $check['artifact'] ) ? $check['artifact'] : '',
			);
		}
		return array(
			'run_id'          => $run_id,
			'test_id'         => $test_id,
			'trigger'         => Util::short( $r['trigger'] ?? '', 40 ),
			'started_at'      => false !== Util::parse_iso( $r['started_at'] ?? null ) ? $r['started_at'] : '',
			'finished_at'     => false !== Util::parse_iso( $r['finished_at'] ?? null ) ? $r['finished_at'] : '',
			'http'            => $http,
			'http_status'     => isset( $r['http_status'] ) ? (int) $r['http_status'] : 0,
			'smoke'           => $smoke,
			'smoke_mandatory' => isset( $r['smoke_mandatory'] ) ? (bool) $r['smoke_mandatory'] : true,
			'checks'          => $checks,
			'runner_error'    => Util::short( $r['runner_error'] ?? '', 200 ),
		);
	}

	/**
	 * POST a signed body.
	 *
	 * @param string $path   Path.
	 * @param string $body   Body.
	 * @param string $secret Secret name.
	 * @return array|\WP_Error
	 */
	private function send( $path, $body, $secret ) {
		$base = rtrim( (string) Settings::get( 'runner_url' ), '/' );
		$key  = Settings::secret( $secret );
		if ( '' === $base || '' === $key ) {
			return new \WP_Error( 'msug_runner_not_configured', 'Runner URL or secret missing.' );
		}
		return Url_Policy::post(
			$base . $path,
			$body,
			array(
				Signature::HEADER     => Signature::sign( $body, $key ),
				Signature::KEY_HEADER => (string) Settings::get( 'key_id' ),
			),
			15
		);
	}
}
