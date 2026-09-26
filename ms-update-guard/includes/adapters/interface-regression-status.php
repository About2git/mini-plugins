<?php
/**
 * Adapter contract Regression_Status (spec section 5).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Regression_Status adapter. Implementations return arrays or WP_Error and never throw.
 */
interface Regression_Status {
	/**
	 * Result of MainWP Regression Testing for a run, if an integration exists.
	 *
	 * @param array $run Run.
	 * @return array state: not_connected|pending|pass|fail, details.
	 */
	public function for_run( array $run );
}
