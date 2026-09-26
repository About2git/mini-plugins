<?php
/**
 * Bootstrap and service wiring.
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

use MSUpdateGuard\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the adapter instances. Replace any of them with the "msug_adapters" filter.
 */
class Plugin {

	/**
	 * Gateway.
	 *
	 * @var Adapters\MainWP_Gateway|null
	 */
	private static $gateway = null;

	/**
	 * Engine.
	 *
	 * @var Engine|null
	 */
	private static $engine = null;

	/**
	 * Boot on plugins_loaded.
	 *
	 * @return void
	 */
	public static function boot() {
		Schema::maybe_upgrade();
		Update_Path_Guard::register();
		add_action( 'rest_api_init', array( Rest_Controller::class, 'register' ) );
		if ( is_admin() ) {
			Admin::register();
		}
	}

	/**
	 * MainWP gateway.
	 *
	 * @return Adapters\MainWP_Gateway
	 */
	public static function gateway() {
		if ( null === self::$gateway ) {
			self::$gateway = new Adapters\MainWP_Gateway();
		}
		return self::$gateway;
	}

	/**
	 * Engine with adapters.
	 *
	 * @return Engine
	 */
	public static function engine() {
		if ( null === self::$engine ) {
			$gateway   = self::gateway();
			$inventory = new Adapters\MainWP_Update_Inventory( $gateway );
			$adapters  = array(
				'inventory'  => $inventory,
				'backup'     => new Adapters\WPTC_Backup_Provider( $gateway ),
				'executor'   => new Adapters\MainWP_Update_Executor( $gateway ),
				'verifier'   => new Adapters\MainWP_Version_Verifier( $gateway, $inventory ),
				'runner'     => new Adapters\HTTP_Test_Runner(),
				'regression' => new Adapters\Regression_Status_Default(),
			);
			/**
			 * Replace adapters (staging doubles, other backup providers).
			 *
			 * @param array $adapters Adapter instances keyed by role.
			 */
			$adapters     = apply_filters( 'msug_adapters', $adapters );
			self::$engine = new Engine( $adapters );
		}
		return self::$engine;
	}

	/**
	 * Enqueue scheduled runs for all enabled sites with automatic types (Guard scheduler,
	 * replaces MainWP's native auto-update queue).
	 *
	 * @return array site_id => run_id|error code
	 */
	public static function schedule_all() {
		$out   = array();
		$today = wp_date( 'Y-m-d' );
		$done  = get_option( 'msug_last_schedule', array() );
		$done  = is_array( $done ) ? $done : array();
		foreach ( array_keys( Settings::sites() ) as $site_id ) {
			$config = Settings::site( $site_id );
			if ( empty( $config['enabled'] ) || empty( $config['auto_types'] ) || ! Settings::in_window( (string) $config['window'] ) ) {
				continue;
			}
			// At most one scheduled run per site and local day.
			if ( isset( $done[ $site_id ] ) && $done[ $site_id ] === $today ) {
				continue;
			}
			$run             = self::engine()->enqueue( $site_id, array( 'types' => $config['auto_types'] ), 'schedule' );
			$out[ $site_id ] = is_wp_error( $run ) ? $run->get_error_code() : $run['run_id'];
			if ( ! is_wp_error( $run ) ) {
				$done[ $site_id ] = $today;
			}
		}
		update_option( 'msug_last_schedule', $done, false );
		return $out;
	}
}
