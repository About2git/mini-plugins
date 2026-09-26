<?php
/**
 * Adapter contract External_Test_Runner (spec section 5).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * External_Test_Runner adapter. Implementations return arrays or WP_Error and never throw.
 */
interface External_Test_Runner {
	/**
	 * Trigger a test. 202 means accepted, not passed.
	 *
	 * @param array  $run     Run.
	 * @param string $test_id Pre-generated test id.
	 * @param string $trigger preflight|update_finished_or_timeout.
	 * @return array|\WP_Error accepted(bool), http_code.
	 */
	public function start( array $run, $test_id, $trigger );

	/**
	 * Ask for a result (used after the callback deadline).
	 *
	 * @param array  $run     Run.
	 * @param string $test_id Test id.
	 * @return array|\WP_Error result or pending.
	 */
	public function status( array $run, $test_id );
}
