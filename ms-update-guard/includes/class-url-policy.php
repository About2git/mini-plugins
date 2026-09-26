<?php
/**
 * Outbound URL allowlist (SSRF protection, spec section 7).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Every outbound request of the plugin goes to an https URL whose host is on the allowlist.
 */
class Url_Policy {

	/**
	 * Normalize a host entry.
	 *
	 * @param mixed $host Raw host.
	 * @return string Empty when invalid.
	 */
	public static function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		return 1 === preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D', $host ) ? $host : '';
	}

	/**
	 * Whether a URL may be called.
	 *
	 * @param string $url   URL.
	 * @param array  $hosts Allowed hosts; defaults to the configured list.
	 * @return bool
	 */
	public static function allowed( $url, $hosts = null ) {
		$hosts  = null === $hosts ? (array) Settings::get( 'allowed_hosts' ) : $hosts;
		$parts  = wp_parse_url( (string) $url );
		$scheme = empty( $parts['scheme'] ) ? '' : strtolower( $parts['scheme'] );
		if ( ( 'https' !== $scheme && ! ( 'http' === $scheme && self::test_mode() ) ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( self::test_mode() && '127.0.0.1' === $parts['host'] ) {
			return true;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$host = self::normalize_host( $parts['host'] );
		return '' !== $host && in_array( $host, $hosts, true );
	}

	/**
	 * Local end-to-end tests only: allows plain http to 127.0.0.1.
	 * Never define MSUG_INSECURE_LOCAL_TEST_MODE on a production Dashboard.
	 *
	 * @return bool
	 */
	public static function test_mode() {
		return defined( 'MSUG_INSECURE_LOCAL_TEST_MODE' ) && true === MSUG_INSECURE_LOCAL_TEST_MODE;
	}

	/**
	 * POST JSON bytes to an allowlisted URL.
	 *
	 * @param string $url     URL.
	 * @param string $body    Body bytes.
	 * @param array  $headers Extra headers.
	 * @param int    $timeout Seconds.
	 * @return array|\WP_Error
	 */
	public static function post( $url, $body, array $headers = array(), $timeout = 15 ) {
		if ( ! self::allowed( $url ) ) {
			return new \WP_Error( 'msug_url_not_allowed', 'Target host is not on the allowlist or not https.' );
		}
		$send = self::test_mode() ? 'wp_remote_post' : 'wp_safe_remote_post';
		return $send(
			$url,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'        => $body,
			)
		);
	}
}
