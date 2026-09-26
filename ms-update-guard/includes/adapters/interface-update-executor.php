<?php
/**
 * Adapter contract Update_Executor (spec section 5).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Update_Executor adapter. Implementations return arrays or WP_Error and never throw.
 */
interface Update_Executor {
	/**
	 * Run the stored component list. Called once per run, never retried blindly.
	 *
	 * @param int    $site_id    Site id.
	 * @param array  $components Components.
	 * @param string $run_id     Run id.
	 * @return array status (responded|error|no_result), updated[], errors{}, site_error, raw summary.
	 */
	public function start( $site_id, array $components, $run_id );
}
