<?php
/**
 * Adapter contract Backup_Provider (spec section 5).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Backup_Provider adapter. Implementations return arrays or WP_Error and never throw.
 */
interface Backup_Provider {
	/**
	 * Readiness of the provider on the site (plugin, account, storage, no running job).
	 *
	 * @param int $site_id Site id.
	 * @return array|\WP_Error ok(bool), code, details.
	 */
	public function preflight( $site_id );

	/**
	 * Start a backup for this run. Must be idempotent per $run_id.
	 *
	 * @param int    $site_id Site id.
	 * @param string $run_id  Run id.
	 * @return array|\WP_Error job: job_id, state, started_at.
	 */
	public function start( $site_id, $run_id );

	/**
	 * Evidence for a started job (or for the newest restore point when $job is null).
	 *
	 * @param int        $site_id Site id.
	 * @param array|null $job     Job from start().
	 * @return array|\WP_Error op, site, probe (raw evidence for Backup_Evidence).
	 */
	public function evidence( $site_id, $job );
}
