<?php
/**
 * Minimal WordPress stubs so the pure classes can be tested without a WordPress install.
 * Integration behaviour is covered by tests/integration/ against a real WordPress.
 *
 * @package MSUpdateGuard
 */

define( 'ABSPATH', __DIR__ . '/' );

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
}
function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
}
function wp_generate_uuid4() {
	$b    = random_bytes( 16 );
	$b[6] = chr( ord( $b[6] ) & 0x0f | 0x40 );
	$b[8] = chr( ord( $b[8] ) & 0x3f | 0x80 );
	return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
}
function wp_timezone() {
	return new DateTimeZone( 'Europe/Berlin' );
}

require dirname( __DIR__ ) . '/includes/class-util.php';
require dirname( __DIR__ ) . '/includes/class-states.php';
require dirname( __DIR__ ) . '/includes/class-signature.php';
require dirname( __DIR__ ) . '/includes/class-backup-evidence.php';
require dirname( __DIR__ ) . '/includes/class-outcome.php';

/**
 * Tiny assertion harness.
 */
class T {
	public static $pass = 0;
	public static $fail = array();

	public static function eq( $expected, $actual, $label ) {
		if ( $expected === $actual ) {
			++self::$pass;
			return;
		}
		self::$fail[] = $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}

	public static function ok( $cond, $label ) {
		self::eq( true, (bool) $cond, $label );
	}

	public static function done() {
		foreach ( self::$fail as $f ) {
			fwrite( STDERR, "FAIL {$f}\n" );
		}
		printf( "%d passed, %d failed\n", self::$pass, count( self::$fail ) );
		exit( self::$fail ? 1 : 0 );
	}
}
