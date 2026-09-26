<?php
/**
 * External dead man's switch for the Parent scheduler (spec 6, last paragraph).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Pings an external heartbeat monitor (e.g. Better Stack heartbeat) after every completed tick.
 * If the Parent, its cron or its database dies, the pings stop and the external service alerts.
 */
class Heartbeat {

	const OPTION = 'msug_heartbeat';

	/**
	 * Ping unless the last successful ping is younger than a minute.
	 *
	 * @param array $stats Tick statistics (only counters, no site data).
	 * @return bool|null Null when not configured or throttled.
	 */
	public static function ping( array $stats ) {
		$url = Settings::get( 'heartbeat_url' );
		if ( ! $url ) {
			return null;
		}
		$last = get_option( self::OPTION, array() );
		if ( is_array( $last ) && ! empty( $last['ok_at'] ) && time() - (int) $last['ok_at'] < 55 ) {
			return null;
		}
		$response = Url_Policy::post( $url, Util::json( $stats ), array(), 10 );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$ok       = $code >= 200 && $code < 300;
		update_option(
			self::OPTION,
			array(
				'ok_at'      => $ok ? time() : ( is_array( $last ) && isset( $last['ok_at'] ) ? $last['ok_at'] : 0 ),
				'tried_at'   => time(),
				'last_code'  => $code,
				'last_error' => is_wp_error( $response ) ? $response->get_error_code() : '',
			),
			false
		);
		return $ok;
	}

	/**
	 * Last state for the admin screen.
	 *
	 * @return array
	 */
	public static function status() {
		$last = get_option( self::OPTION, array() );
		return is_array( $last ) ? $last : array();
	}
}
