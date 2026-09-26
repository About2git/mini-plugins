<?php
/**
 * MainWP Regression Testing status (F09).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * The Regression Testing extension is not public and no per-run result interface could be
 * verified (docs/ap0-schnittstellenmatrix.md, question 5). The status is therefore
 * "not_connected" unless an integration answers the "msug_regression_status" filter
 * with a result that it can attribute to this run.
 */
class Regression_Status_Default implements Regression_Status {

	/**
	 * {@inheritDoc}
	 *
	 * @param array $run Run.
	 * @return array
	 */
	public function for_run( array $run ) {
		/**
		 * Provide a Regression Testing result for a Guard run.
		 *
		 * Return null when unknown. A result must contain 'state' (pending|pass|fail) and
		 * 'evidence' that links it to this run; anything else is shown as not connected.
		 *
		 * @param array|null $status Status.
		 * @param array      $run    Run.
		 */
		$status = apply_filters( 'msug_regression_status', null, $run );
		if ( is_array( $status ) && isset( $status['state'], $status['evidence'] ) && in_array( $status['state'], array( 'pending', 'pass', 'fail' ), true ) ) {
			return $status;
		}
		return array(
			'state'    => 'not_connected',
			'evidence' => '',
		);
	}
}
