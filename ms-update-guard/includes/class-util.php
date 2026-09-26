<?php
/**
 * Small helpers shared by all components.
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Time, id and encoding helpers. All timestamps are UTC.
 */
class Util {

	/**
	 * Current UTC time as MySQL DATETIME.
	 *
	 * @param int $offset Seconds to add.
	 * @return string
	 */
	public static function now( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + (int) $offset );
	}

	/**
	 * Convert a MySQL DATETIME (UTC) to a unix timestamp.
	 *
	 * @param string|null $datetime DATETIME value.
	 * @return int 0 when empty.
	 */
	public static function ts( $datetime ) {
		if ( empty( $datetime ) ) {
			return 0;
		}
		$ts = strtotime( $datetime . ' UTC' );
		return false === $ts ? 0 : $ts;
	}

	/**
	 * ISO 8601 UTC string for a unix timestamp.
	 *
	 * @param int $ts Timestamp.
	 * @return string
	 */
	public static function iso( $ts ) {
		return gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts );
	}

	/**
	 * Parse an ISO 8601 UTC string (Z suffix required).
	 *
	 * @param mixed $value Candidate.
	 * @return int|false
	 */
	public static function parse_iso( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/D', $value ) ) {
			return false;
		}
		return strtotime( $value );
	}

	/**
	 * Version 4 UUID.
	 *
	 * @return string
	 */
	public static function uuid() {
		return wp_generate_uuid4();
	}

	/**
	 * Whether a string is a lowercase v4 UUID.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_uuid( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value );
	}

	/**
	 * JSON encode for storage.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function json( $data ) {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '{}' : $json;
	}

	/**
	 * Decode stored JSON into an array.
	 *
	 * @param string|null $json JSON.
	 * @return array
	 */
	public static function unjson( $json ) {
		if ( empty( $json ) ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Shorten free text coming from remote systems before it is stored or mailed.
	 *
	 * @param mixed $text  Text.
	 * @param int   $limit Max characters.
	 * @return string
	 */
	public static function short( $text, $limit = 300 ) {
		if ( ! is_scalar( $text ) ) {
			return '';
		}
		$text = wp_strip_all_tags( (string) $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( trim( $text ), 0, $limit );
		}
		return substr( trim( $text ), 0, $limit );
	}
}
