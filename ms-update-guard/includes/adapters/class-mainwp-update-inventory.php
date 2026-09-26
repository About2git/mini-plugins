<?php
/**
 * Pending updates of a site via MainWP abilities.
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs the site first so the list reflects the child, not a stale Dashboard cache.
 */
class MainWP_Update_Inventory implements Update_Inventory {

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
	 * Sync one site through mainwp/sync-sites-v1.
	 *
	 * @param int $site_id Site id.
	 * @return true|\WP_Error
	 */
	public function sync( $site_id ) {
		$result = $this->gateway->ability( 'mainwp/sync-sites-v1', array( 'site_ids' => array( (int) $site_id ) ) );
		$this->gateway->forget( $site_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		foreach ( (array) ( isset( $result['synced'] ) ? $result['synced'] : array() ) as $row ) {
			if ( isset( $row['id'] ) && (int) $row['id'] === (int) $site_id ) {
				return true;
			}
		}
		$message = 'Sync failed.';
		foreach ( (array) ( isset( $result['errors'] ) ? $result['errors'] : array() ) as $row ) {
			if ( isset( $row['message'] ) ) {
				$message = \MSUpdateGuard\Util::short( $row['message'], 300 );
			}
		}
		return new \WP_Error( 'msug_sync_failed', $message );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $site_id Site id.
	 * @return array|\WP_Error
	 */
	public function list_pending( $site_id ) {
		$synced = $this->sync( $site_id );
		if ( is_wp_error( $synced ) ) {
			return $synced;
		}
		$result = $this->gateway->ability(
			'mainwp/get-site-updates-v1',
			array(
				'site_id_or_domain' => (int) $site_id,
				'types'             => array( 'core', 'plugins', 'themes' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$list = array();
		foreach ( (array) ( isset( $result['updates'] ) ? $result['updates'] : array() ) as $u ) {
			if ( ! isset( $u['type'], $u['slug'] ) || ! in_array( $u['type'], array( 'core', 'plugin', 'theme' ), true ) ) {
				continue;
			}
			$list[] = array(
				'type'            => $u['type'],
				'slug'            => (string) $u['slug'],
				'name'            => isset( $u['name'] ) ? (string) $u['name'] : (string) $u['slug'],
				'current_version' => isset( $u['current_version'] ) ? (string) $u['current_version'] : '',
				'new_version'     => isset( $u['new_version'] ) ? (string) $u['new_version'] : '',
			);
		}
		return $list;
	}
}
