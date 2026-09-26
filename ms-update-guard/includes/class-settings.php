<?php
/**
 * Global and per-site configuration, secrets.
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage. Global values are defaults only; every site can override them (spec 7).
 */
class Settings {

	const OPTION        = 'msug_settings';
	const SITES_OPTION  = 'msug_sites';
	const SECRET_OPTION = 'msug_secrets';

	/**
	 * Secret names and the wp-config constant that takes precedence over the stored value.
	 */
	const SECRETS = array(
		'trigger'           => 'MSUG_TRIGGER_SECRET',
		'status'            => 'MSUG_STATUS_SECRET',
		'callback'          => 'MSUG_CALLBACK_SECRET',
		'callback_previous' => 'MSUG_CALLBACK_SECRET_PREVIOUS',
	);

	/**
	 * Defaults for global settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'service_user_id'            => 0,
			'runner_url'                 => '',
			'key_id'                     => 'k1',
			'allowed_hosts'              => array(),
			'alert_emails'               => '',
			'alert_webhook_url'          => '',
			'heartbeat_url'              => '',
			'enforce_native_updates_off' => 1,
			'block_unguarded_abilities'  => 1,
			'require_wptc_bbu_off'       => 1,
			'max_parallel'               => 2,
			'reminder_hours'             => 12,
			'retention_days'             => 400,
			'backup_timeout_min'         => 90,
			'update_timeout_min'         => 15,
			'test_timeout_min'           => 20,
			'settle_seconds'             => 45,
			'backup_max_age_min'         => 120,
			'reuse_backup_min'           => 0,
			'preflight_http'             => 1,
			'window'                     => '',
		);
	}

	/**
	 * Keys a site may override.
	 *
	 * @return string[]
	 */
	public static function site_keys() {
		return array( 'enabled', 'profile_id', 'auto_types', 'exclude_components', 'backup_timeout_min', 'update_timeout_min', 'test_timeout_min', 'settle_seconds', 'backup_max_age_min', 'reuse_backup_min', 'preflight_http', 'window', 'critical_components', 'alert_emails' );
	}

	/**
	 * All global settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Single global setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Save sanitized global settings.
	 *
	 * @param array $input Raw input.
	 * @return array Saved values.
	 */
	public static function save( array $input ) {
		// Keys missing from $input keep their stored value; a partial save never resets flags.
		$clean = self::sanitize( array_merge( self::all(), $input ) );
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Sanitize global settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$d   = self::defaults();
		$out = array();

		$out['service_user_id'] = isset( $input['service_user_id'] ) ? absint( $input['service_user_id'] ) : 0;
		$out['key_id']          = isset( $input['key_id'] ) && preg_match( '/^[A-Za-z0-9_-]{1,32}$/', $input['key_id'] ) ? $input['key_id'] : $d['key_id'];

		$hosts = isset( $input['allowed_hosts'] ) ? $input['allowed_hosts'] : array();
		if ( is_string( $hosts ) ) {
			$hosts = preg_split( '/[\s,]+/', $hosts );
		}
		$out['allowed_hosts'] = array_values( array_unique( array_filter( array_map( array( Url_Policy::class, 'normalize_host' ), (array) $hosts ) ) ) );

		foreach ( array( 'runner_url', 'alert_webhook_url', 'heartbeat_url' ) as $key ) {
			$url         = isset( $input[ $key ] ) ? esc_url_raw( trim( (string) $input[ $key ] ), Url_Policy::test_mode() ? array( 'https', 'http' ) : array( 'https' ) ) : '';
			$out[ $key ] = $url;
		}

		$emails              = isset( $input['alert_emails'] ) ? (string) $input['alert_emails'] : '';
		$out['alert_emails'] = implode( ',', array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', $emails ) ) ) );

		foreach ( array( 'enforce_native_updates_off', 'block_unguarded_abilities', 'require_wptc_bbu_off', 'preflight_http' ) as $key ) {
			$out[ $key ] = array_key_exists( $key, $input ) ? ( empty( $input[ $key ] ) ? 0 : 1 ) : $d[ $key ];
		}

		$ranges = array(
			'max_parallel'       => array( 1, 3 ),
			'reminder_hours'     => array( 1, 168 ),
			'retention_days'     => array( 30, 3650 ),
			'backup_timeout_min' => array( 5, 720 ),
			'update_timeout_min' => array( 2, 120 ),
			'test_timeout_min'   => array( 2, 240 ),
			'settle_seconds'     => array( 0, 600 ),
			'backup_max_age_min' => array( 5, 1440 ),
			'reuse_backup_min'   => array( 0, 240 ),
		);
		foreach ( $ranges as $key => $range ) {
			$value       = isset( $input[ $key ] ) ? (int) $input[ $key ] : $d[ $key ];
			$out[ $key ] = max( $range[0], min( $range[1], $value ) );
		}

		$out['window'] = isset( $input['window'] ) ? self::sanitize_window( $input['window'] ) : '';

		return $out;
	}

	/**
	 * Validate a maintenance window "HH:MM-HH:MM" (site timezone). Empty means always.
	 *
	 * @param mixed $window Raw value.
	 * @return string
	 */
	public static function sanitize_window( $window ) {
		$window = is_string( $window ) ? trim( $window ) : '';
		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/', $window ) ? $window : '';
	}

	/**
	 * Per-site config merged over global defaults.
	 *
	 * @param int $site_id MainWP site id.
	 * @return array
	 */
	public static function site( $site_id ) {
		$sites  = get_option( self::SITES_OPTION, array() );
		$own    = is_array( $sites ) && isset( $sites[ $site_id ] ) && is_array( $sites[ $site_id ] ) ? $sites[ $site_id ] : array();
		$global = self::all();

		$config = array(
			'enabled'             => 0,
			'profile_id'          => '',
			'auto_types'          => array(),
			'exclude_components'  => array(),
			'critical_components' => array(),
			'alert_emails'        => $global['alert_emails'],
		);
		foreach ( array( 'backup_timeout_min', 'update_timeout_min', 'test_timeout_min', 'settle_seconds', 'backup_max_age_min', 'reuse_backup_min', 'preflight_http', 'window' ) as $key ) {
			$config[ $key ] = $global[ $key ];
		}
		foreach ( self::site_keys() as $key ) {
			if ( array_key_exists( $key, $own ) && '' !== $own[ $key ] && null !== $own[ $key ] ) {
				$config[ $key ] = $own[ $key ];
			}
		}
		return $config;
	}

	/**
	 * All stored site overrides.
	 *
	 * @return array<int,array>
	 */
	public static function sites() {
		$sites = get_option( self::SITES_OPTION, array() );
		return is_array( $sites ) ? $sites : array();
	}

	/**
	 * Save one site's overrides.
	 *
	 * @param int   $site_id Site id.
	 * @param array $input   Raw input.
	 * @return array Stored overrides.
	 */
	public static function save_site( $site_id, array $input ) {
		$clean               = array();
		$clean['enabled']    = empty( $input['enabled'] ) ? 0 : 1;
		$profile             = isset( $input['profile_id'] ) ? (string) $input['profile_id'] : '';
		$clean['profile_id'] = preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $profile ) ? $profile : '';

		foreach ( array( 'backup_timeout_min', 'update_timeout_min', 'test_timeout_min', 'settle_seconds', 'backup_max_age_min', 'reuse_backup_min' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== $input[ $key ] ) {
				$sanitized     = self::sanitize( array( $key => $input[ $key ] ) );
				$clean[ $key ] = $sanitized[ $key ];
			}
		}
		if ( isset( $input['preflight_http'] ) && '' !== $input['preflight_http'] ) {
			$clean['preflight_http'] = empty( $input['preflight_http'] ) ? 0 : 1;
		}
		if ( isset( $input['window'] ) && '' !== $input['window'] ) {
			$clean['window'] = self::sanitize_window( $input['window'] );
		}
		if ( isset( $input['alert_emails'] ) && '' !== $input['alert_emails'] ) {
			$sanitized             = self::sanitize( array( 'alert_emails' => $input['alert_emails'] ) );
			$clean['alert_emails'] = $sanitized['alert_emails'];
		}
		foreach ( array( 'critical_components', 'exclude_components' ) as $key ) {
			$list = isset( $input[ $key ] ) ? $input[ $key ] : array();
			if ( is_string( $list ) ) {
				$list = preg_split( '/[\s,]+/', $list );
			}
			$clean[ $key ] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $list ) ) );
		}
		$types               = isset( $input['auto_types'] ) ? (array) $input['auto_types'] : array();
		$clean['auto_types'] = array_values( array_intersect( array( 'core', 'plugin', 'theme' ), $types ) );

		$sites             = self::sites();
		$sites[ $site_id ] = $clean;
		update_option( self::SITES_OPTION, $sites, false );
		return $clean;
	}

	/**
	 * Read a secret. A wp-config constant always wins over the stored value.
	 *
	 * @param string $name trigger|status|callback|callback_previous.
	 * @return string Empty when not configured.
	 */
	public static function secret( $name ) {
		if ( ! isset( self::SECRETS[ $name ] ) ) {
			return '';
		}
		$constant = self::SECRETS[ $name ];
		if ( defined( $constant ) && is_string( constant( $constant ) ) ) {
			return (string) constant( $constant );
		}
		$stored = get_option( self::SECRET_OPTION, array() );
		return is_array( $stored ) && isset( $stored[ $name ] ) && is_string( $stored[ $name ] ) ? $stored[ $name ] : '';
	}

	/**
	 * Where a secret comes from, for display without revealing it.
	 *
	 * @param string $name Secret name.
	 * @return string constant|option|missing
	 */
	public static function secret_source( $name ) {
		if ( isset( self::SECRETS[ $name ] ) && defined( self::SECRETS[ $name ] ) ) {
			return 'constant';
		}
		return '' === self::secret( $name ) ? 'missing' : 'option';
	}

	/**
	 * Store a secret (write-only from the admin form). Too short values are rejected.
	 *
	 * @param string $name  Secret name.
	 * @param string $value Secret value.
	 * @return bool
	 */
	public static function set_secret( $name, $value ) {
		if ( ! isset( self::SECRETS[ $name ] ) || ! is_string( $value ) || strlen( $value ) < 32 ) {
			return false;
		}
		$stored          = get_option( self::SECRET_OPTION, array() );
		$stored          = is_array( $stored ) ? $stored : array();
		$stored[ $name ] = $value;
		return update_option( self::SECRET_OPTION, $stored, false );
	}

	/**
	 * Whether "now" lies inside a maintenance window in the site timezone.
	 *
	 * @param string   $window "HH:MM-HH:MM" or empty.
	 * @param int|null $now    Unix time.
	 * @return bool
	 */
	public static function in_window( $window, $now = null ) {
		if ( '' === $window ) {
			return true;
		}
		$now                 = null === $now ? time() : $now;
		list( $start, $end ) = explode( '-', $window );
		$local               = new \DateTimeImmutable( '@' . $now );
		$local               = $local->setTimezone( wp_timezone() );
		$minutes             = (int) $local->format( 'H' ) * 60 + (int) $local->format( 'i' );
		$s                   = (int) substr( $start, 0, 2 ) * 60 + (int) substr( $start, 3, 2 );
		$e                   = (int) substr( $end, 0, 2 ) * 60 + (int) substr( $end, 3, 2 );
		if ( $s === $e ) {
			return true;
		}
		return $s < $e ? ( $minutes >= $s && $minutes < $e ) : ( $minutes >= $s || $minutes < $e );
	}
}
