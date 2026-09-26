<?php
/**
 * Adapter contract Update_Inventory (spec section 5).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Update_Inventory adapter. Implementations return arrays or WP_Error and never throw.
 */
interface Update_Inventory {
	/**
	 * Pending updates after a fresh sync.
	 *
	 * @param int $site_id Site id.
	 * @return array|\WP_Error List of type, slug, name, current_version, new_version.
	 */
	public function list_pending( $site_id );
}
