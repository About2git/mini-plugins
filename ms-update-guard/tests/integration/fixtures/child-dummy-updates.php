<?php
/**
 * Test fixture for the CHILD site of the local end-to-end test. Never install in production.
 *
 * Offers an update of the "msug-dummy" plugin from a local zip (option msug_dummy_offer = version)
 * and allows the upgrader to download from 127.0.0.1.
 *
 * @package MSUpdateGuard
 */

add_filter(
	'site_transient_update_plugins',
	function ( $value ) {
		$offer = get_option( 'msug_dummy_offer' );
		if ( ! $offer ) {
			return $value;
		}
		if ( ! is_object( $value ) ) {
			$value = new stdClass();
		}
		if ( ! isset( $value->response ) || ! is_array( $value->response ) ) {
			$value->response = array();
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = WP_PLUGIN_DIR . '/msug-dummy/msug-dummy.php';
		if ( file_exists( $file ) ) {
			$installed = get_plugin_data( $file, false, false );
			if ( version_compare( $installed['Version'], $offer, '>=' ) && ! get_option( 'msug_dummy_force_offer' ) ) {
				unset( $value->response['msug-dummy/msug-dummy.php'] );
				return $value;
			}
		}
		$value->response['msug-dummy/msug-dummy.php'] = (object) array(
			'id'          => 'msug-dummy',
			'slug'        => 'msug-dummy',
			'plugin'      => 'msug-dummy/msug-dummy.php',
			'new_version' => $offer,
			'url'         => home_url( '/' ),
			'package'     => home_url( '/msug-dummy-' . $offer . '.zip' ),
		);
		return $value;
	},
	99
);

add_filter( 'http_request_host_is_external', '__return_true' );
