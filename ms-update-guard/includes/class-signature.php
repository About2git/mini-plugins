<?php
/**
 * HMAC signing of runner messages (spec section 5).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Signs the exact body bytes and checks the time envelope inside the body.
 *
 * Header: X-MSUG-Signature: v1=<hex hmac-sha256(secret, body)>
 *         X-MSUG-Key-Id:    <key id> (lets the receiver pick the right key during rotation)
 */
class Signature {

	const HEADER       = 'X-MSUG-Signature';
	const KEY_HEADER   = 'X-MSUG-Key-Id';
	const MAX_SKEW     = 60;
	const MAX_LIFETIME = 900;
	const MAX_BODY     = 65536;

	/**
	 * Signature header value for a body.
	 *
	 * @param string $body   Exact bytes that will be sent.
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function sign( $body, $secret ) {
		return 'v1=' . hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * Constant-time check against one or more accepted secrets.
	 *
	 * @param string   $body    Raw body bytes as received.
	 * @param string   $header  Signature header.
	 * @param string[] $secrets Accepted secrets (current, previous).
	 * @return bool
	 */
	public static function verify( $body, $header, array $secrets ) {
		if ( ! is_string( $header ) || 0 !== strpos( $header, 'v1=' ) || ! is_string( $body ) ) {
			return false;
		}
		$ok = false;
		foreach ( $secrets as $secret ) {
			if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
				continue;
			}
			// Keep looping so timing does not reveal which key matched.
			if ( hash_equals( self::sign( $body, $secret ), $header ) ) {
				$ok = true;
			}
		}
		return $ok;
	}

	/**
	 * Build a signed envelope around a payload.
	 *
	 * @param array $payload  Payload fields.
	 * @param int   $lifetime Seconds until expiry.
	 * @return array Payload with event_id, issued_at, expires_at.
	 */
	public static function envelope( array $payload, $lifetime = 300 ) {
		$now                   = time();
		$payload['event_id']   = Util::uuid();
		$payload['issued_at']  = Util::iso( $now );
		$payload['expires_at'] = Util::iso( $now + max( 30, min( self::MAX_LIFETIME, (int) $lifetime ) ) );
		return $payload;
	}

	/**
	 * Validate the time envelope and event id of a decoded message.
	 *
	 * @param array    $message Decoded body.
	 * @param int|null $now     Current time.
	 * @return true|string True or an error code.
	 */
	public static function check_envelope( array $message, $now = null ) {
		$now = null === $now ? time() : $now;
		if ( empty( $message['event_id'] ) || ! is_string( $message['event_id'] ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{16,64}$/D', $message['event_id'] ) ) {
			return 'bad_event_id';
		}
		$issued  = Util::parse_iso( isset( $message['issued_at'] ) ? $message['issued_at'] : null );
		$expires = Util::parse_iso( isset( $message['expires_at'] ) ? $message['expires_at'] : null );
		if ( false === $issued || false === $expires ) {
			return 'bad_time';
		}
		if ( $issued > $now + self::MAX_SKEW ) {
			return 'issued_in_future';
		}
		if ( $expires <= $now ) {
			return 'expired';
		}
		if ( $expires - $issued > self::MAX_LIFETIME || $expires < $issued ) {
			return 'lifetime_too_long';
		}
		return true;
	}
}
