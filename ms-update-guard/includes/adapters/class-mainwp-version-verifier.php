<?php
/**
 * Independent version check after an update (F06).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard\Adapters;

use MSUpdateGuard\Outcome;

defined( 'ABSPATH' ) || exit;

/**
 * Forces a fresh MainWP sync and reads the installed versions from the sync result.
 * A failed sync yields "sync_failed" - never a guess from the update response.
 */
class MainWP_Version_Verifier implements Version_Verifier {

	/**
	 * Gateway.
	 *
	 * @var MainWP_Gateway
	 */
	private $gateway;

	/**
	 * Inventory (for its sync()).
	 *
	 * @var MainWP_Update_Inventory
	 */
	private $inventory;

	/**
	 * Constructor.
	 *
	 * @param MainWP_Gateway          $gateway   Gateway.
	 * @param MainWP_Update_Inventory $inventory Inventory.
	 */
	public function __construct( MainWP_Gateway $gateway, MainWP_Update_Inventory $inventory ) {
		$this->gateway   = $gateway;
		$this->inventory = $inventory;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int   $site_id    Site id.
	 * @param array $components Components.
	 * @return array
	 */
	public function resync_and_compare( $site_id, array $components ) {
		$synced = $this->inventory->sync( $site_id );
		if ( is_wp_error( $synced ) ) {
			return array(
				'status'     => 'sync_failed',
				'error'      => \MSUpdateGuard\Util::short( $synced->get_error_message(), 300 ),
				'components' => array(),
			);
		}

		$installed = array(
			'plugin' => $this->versions( 'mainwp/get-site-plugins-v1', 'plugins', $site_id ),
			'theme'  => $this->versions( 'mainwp/get-site-themes-v1', 'themes', $site_id ),
			'core'   => array(),
		);
		$site      = $this->gateway->site( $site_id );
		if ( $site && ! empty( $site['wp_version'] ) ) {
			$installed['core']['wordpress'] = (string) $site['wp_version'];
		}

		$out = array();
		foreach ( $components as $c ) {
			$list   = isset( $installed[ $c['type'] ] ) ? $installed[ $c['type'] ] : array();
			$actual = null === $list ? null : ( isset( $list[ $c['slug'] ] ) ? $list[ $c['slug'] ] : null );

			$out[ $c['slug'] ] = array(
				'actual'  => $actual,
				'verdict' => Outcome::version_verdict( $actual, isset( $c['to_version'] ) ? $c['to_version'] : '', isset( $c['from_version'] ) ? $c['from_version'] : '' ),
			);
		}
		return array(
			'status'     => 'ok',
			'components' => $out,
			'synced_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	/**
	 * Map slug => version from a listing ability.
	 *
	 * @param string $ability Ability.
	 * @param string $key     Result key.
	 * @param int    $site_id Site id.
	 * @return array|null Null when the listing failed.
	 */
	private function versions( $ability, $key, $site_id ) {
		$result = $this->gateway->ability( $ability, array( 'site_id_or_domain' => (int) $site_id ) );
		if ( is_wp_error( $result ) || ! isset( $result[ $key ] ) ) {
			return null;
		}
		$map = array();
		foreach ( (array) $result[ $key ] as $row ) {
			if ( isset( $row['slug'], $row['version'] ) ) {
				$map[ (string) $row['slug'] ] = (string) $row['version'];
			}
		}
		return $map;
	}
}
