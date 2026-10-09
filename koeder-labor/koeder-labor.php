<?php # -*- coding: utf-8 -*-
/**
 * Plugin Name: Köder-Labor
 * Description: Interaktives Lernwerkzeug zur Köderwahl auf deutsche Süßwasser-Raubfische. Shortcode: [koeder_labor theme="auto|light|dark"]
 * Plugin URI:  http://marketpress.com/
 * Version:     1.0.0
 * Author:      MarketPress
 * Author URI:  http://marketpress.com/
 * Text Domain: koeder-labor
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'KOEDER_LABOR_VERSION', '1.0.0' );

add_action( 'wp_enqueue_scripts', 'koeder_labor_register_assets' );
add_shortcode( 'koeder_labor', 'koeder_labor_shortcode' );

/**
 * Register (not enqueue) the assets. They are only loaded on pages that
 * actually contain the shortcode, see koeder_labor_shortcode().
 *
 * @return void
 */
function koeder_labor_register_assets() {

	$url = plugin_dir_url( __FILE__ ) . 'assets/';

	wp_register_style(
		'koeder-labor',
		$url . 'koeder-labor.css',
		array(),
		KOEDER_LABOR_VERSION
	);

	wp_register_script(
		'koeder-labor',
		$url . 'koeder-labor.js',
		array(),
		KOEDER_LABOR_VERSION,
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}

/**
 * Render the shortcode.
 *
 * @param  array|string $atts
 * @return string
 */
function koeder_labor_shortcode( $atts ) {

	// One instance per page: the script binds to a fixed element ID.
	static $rendered = false;

	if ( $rendered )
		return '';

	$rendered = true;

	$atts = shortcode_atts(
		array(
			'theme' => 'auto',
			'tab'   => 'lab',
		),
		$atts,
		'koeder_labor'
	);

	$theme = in_array( $atts[ 'theme' ], array( 'auto', 'light', 'dark' ), true ) ? $atts[ 'theme' ] : 'auto';
	$tab   = in_array( $atts[ 'tab' ], array( 'lab', 'quiz', 'wissen' ), true ) ? $atts[ 'tab' ] : 'lab';

	wp_enqueue_style( 'koeder-labor' );
	wp_enqueue_script( 'koeder-labor' );

	return sprintf(
		'<div id="koeder-labor" class="kl" data-kl-theme="%1$s" data-kl-tab="%2$s"><noscript>%3$s</noscript></div>',
		esc_attr( $theme ),
		esc_attr( $tab ),
		esc_html__( 'Das Köder-Labor benötigt JavaScript.', 'koeder-labor' )
	);
}
