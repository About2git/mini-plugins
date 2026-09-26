<?php
/**
 * Single entry point to MainWP. Only public MainWP interfaces are used:
 *  - WordPress Abilities registered by MainWP 6.x ("mainwp/*-v1").
 *  - The extension filter "mainwp_fetchurlauthed" for signed Dashboard -> Child calls.
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard\Adapters;

use MSUpdateGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper; everything that talks to MainWP goes through here.
 */
class MainWP_Gateway {

	/**
	 * Per-request site cache.
	 *
	 * @var array<int,array|null>
	 */
	private $sites = array();

	/**
	 * Whether the MainWP APIs we rely on exist.
	 *
	 * @return true|\WP_Error
	 */
	public function available() {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new \WP_Error( 'msug_no_abilities_api', 'WordPress Abilities API missing (WordPress 6.9+ required).' );
		}
		foreach ( array( 'mainwp/sync-sites-v1', 'mainwp/get-site-v1', 'mainwp/get-site-updates-v1', 'mainwp/update-site-plugins-v1', 'mainwp/update-site-themes-v1', 'mainwp/update-site-core-v1', 'mainwp/get-site-plugins-v1', 'mainwp/get-site-themes-v1' ) as $name ) {
			if ( ! wp_get_ability( $name ) ) {
				return new \WP_Error( 'msug_mainwp_ability_missing', sprintf( 'MainWP ability %s is not registered. MainWP Dashboard 6.2 or newer is required.', $name ) );
			}
		}
		if ( ! has_filter( 'mainwp_fetchurlauthed' ) ) {
			return new \WP_Error( 'msug_mainwp_missing', 'MainWP Dashboard is not active.' );
		}
		return true;
	}

	/**
	 * Execute a MainWP ability as the configured service user.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Input.
	 * @return mixed|\WP_Error
	 */
	public function ability( $name, array $input ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return new \WP_Error( 'msug_no_abilities_api', 'Abilities API missing.' );
		}
		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			return new \WP_Error( 'msug_mainwp_ability_missing', 'Ability not registered: ' . $name );
		}
		return $this->as_service_user(
			function () use ( $ability, $input ) {
				try {
					return $ability->execute( $input );
				} catch ( \Throwable $e ) {
					return new \WP_Error( 'msug_ability_exception', $e->getMessage() );
				}
			}
		);
	}

	/**
	 * Signed call to the child through MainWP's extension API.
	 *
	 * @param int    $site_id Site id.
	 * @param string $what    Child callable, e.g. time_capsule, extra_execution.
	 * @param array  $params  POST params.
	 * @return array|\WP_Error
	 */
	public function fetch( $site_id, $what, array $params ) {
		$enabled = apply_filters( 'mainwp_extension_enabled_check', MSUG_FILE );
		if ( ! is_array( $enabled ) || empty( $enabled['key'] ) ) {
			return new \WP_Error( 'msug_mainwp_missing', 'MainWP extension API unavailable.' );
		}
		$result = $this->as_service_user(
			function () use ( $enabled, $site_id, $what, $params ) {
				try {
					return apply_filters( 'mainwp_fetchurlauthed', MSUG_FILE, $enabled['key'], (int) $site_id, $what, $params );
				} catch ( \Throwable $e ) {
					return new \WP_Error( 'msug_fetch_exception', $e->getMessage() );
				}
			}
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'msug_child_no_response', 'No usable response from the child site.' );
		}
		if ( isset( $result['error'] ) && ! isset( $result['protocol'] ) ) {
			return new \WP_Error( 'msug_child_error', \MSUpdateGuard\Util::short( is_string( $result['error'] ) ? $result['error'] : 'error', 300 ), isset( $result['errorCode'] ) ? $result['errorCode'] : null );
		}
		return $result;
	}

	/**
	 * Basic site data.
	 *
	 * @param int $site_id Site id.
	 * @return array|null id, url, name, status, last_sync, wp_version.
	 */
	public function site( $site_id ) {
		$site_id = (int) $site_id;
		if ( array_key_exists( $site_id, $this->sites ) ) {
			return $this->sites[ $site_id ];
		}
		$result                  = $this->ability( 'mainwp/get-site-v1', array( 'site_id_or_domain' => $site_id ) );
		$this->sites[ $site_id ] = is_array( $result ) && isset( $result['url'] ) ? $result : null;
		return $this->sites[ $site_id ];
	}

	/**
	 * Forget cached site data (after a sync).
	 *
	 * @param int $site_id Site id.
	 * @return void
	 */
	public function forget( $site_id ) {
		unset( $this->sites[ (int) $site_id ] );
	}

	/**
	 * All sites (id, url, name) for admin screens.
	 *
	 * @return array
	 */
	public function all_sites() {
		$items = array();
		for ( $page = 1; $page <= 20; $page++ ) {
			$result = $this->ability(
				'mainwp/list-sites-v1',
				array(
					'page'     => $page,
					'per_page' => 100,
				)
			);
			if ( ! is_array( $result ) || empty( $result['items'] ) || ! is_array( $result['items'] ) ) {
				break;
			}
			$items = array_merge( $items, $result['items'] );
			if ( count( $items ) >= (int) ( isset( $result['total'] ) ? $result['total'] : 0 ) ) {
				break;
			}
		}
		return $items;
	}

	/**
	 * Run a callback as the service user when the current user cannot manage MainWP.
	 *
	 * WP-CLI and REST callbacks have no logged-in user; MainWP checks manage_options and
	 * site ownership, so the Guard acts as one dedicated administrator account.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	public function as_service_user( callable $callback ) {
		$previous = get_current_user_id();
		$service  = (int) Settings::get( 'service_user_id' );
		$switch   = ! current_user_can( 'manage_options' ) && $service > 0 && user_can( $service, 'manage_options' );
		if ( $switch ) {
			wp_set_current_user( $service );
		}
		try {
			return $callback();
		} finally {
			if ( $switch ) {
				wp_set_current_user( $previous );
			}
		}
	}
}
