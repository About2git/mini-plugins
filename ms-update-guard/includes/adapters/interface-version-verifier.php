<?php
/**
 * Adapter contract Version_Verifier (spec section 5).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Version_Verifier adapter. Implementations return arrays or WP_Error and never throw.
 */
interface Version_Verifier {
	/**
	 * Re-sync the site and read the installed versions.
	 *
	 * @param int   $site_id    Site id.
	 * @param array $components Components with from_version/to_version.
	 * @return array status (ok|sync_failed), components{slug:{actual,verdict}}, synced_at.
	 */
	public function resync_and_compare( $site_id, array $components );
}
