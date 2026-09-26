<?php
/**
 * Control of competing update paths (spec 3 question 2 and 4, spec 7 last bullet).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Three layers:
 *  1. Enforce: MainWP's native automatic updates (the only path that runs unattended from the
 *     Dashboard) are forced off, so the Guard scheduler is the only automatic path.
 *  2. Block: MainWP bulk update abilities (REST/MCP "run-updates", "update-all") are refused
 *     outside a Guard run.
 *  3. Detect: every update MainWP performs outside a Guard run, and every version change seen
 *     in a sync that no Guard run explains, is journaled as OUTSIDE_GUARD and alerted.
 *     These updates are never shown as "backup checked".
 */
class Update_Path_Guard {

	const NATIVE_OPTIONS = array( 'mainwp_automaticDailyUpdate', 'mainwp_pluginAutomaticDailyUpdate', 'mainwp_themeAutomaticDailyUpdate', 'mainwp_transAutomaticDailyUpdate' );

	/**
	 * Site currently updated by the Guard.
	 *
	 * @var int
	 */
	private static $site_id = 0;

	/**
	 * Run currently updating.
	 *
	 * @var string
	 */
	private static $run_id = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		if ( Settings::get( 'enforce_native_updates_off' ) ) {
			foreach ( self::NATIVE_OPTIONS as $option ) {
				add_filter( 'pre_option_' . $option, array( __CLASS__, 'force_off' ) );
			}
		}
		add_filter( 'mainwp_run_update_result', array( __CLASS__, 'filter_bulk_ability' ), 10, 2 );
		add_action( 'mainwp_after_plugin_theme_translation_update', array( __CLASS__, 'after_update' ), 10, 4 );
		add_action( 'mainwp_after_wp_update', array( __CLASS__, 'after_core_update' ), 10, 2 );
		add_action( 'mainwp_after_core_update', array( __CLASS__, 'after_core_update' ), 10, 2 );
		add_action( 'mainwp_site_synced', array( __CLASS__, 'after_sync' ), 10, 2 );
	}

	/**
	 * Mark the start of a Guard update call.
	 *
	 * @param int    $site_id Site id.
	 * @param string $run_id  Run id.
	 * @return void
	 */
	public static function begin( $site_id, $run_id ) {
		self::$site_id = (int) $site_id;
		self::$run_id  = (string) $run_id;
	}

	/**
	 * Mark the end of a Guard update call.
	 *
	 * @return void
	 */
	public static function end() {
		self::$site_id = 0;
		self::$run_id  = '';
	}

	/**
	 * Whether the given site is being updated by the Guard right now.
	 *
	 * @param int $site_id Site id.
	 * @return bool
	 */
	public static function is_guarded( $site_id ) {
		return self::$site_id > 0 && self::$site_id === (int) $site_id;
	}

	/**
	 * Force MainWP's native automatic update settings to "Disabled".
	 *
	 * @return int
	 */
	public static function force_off() {
		return 0;
	}

	/**
	 * Refuse MainWP bulk update abilities outside a Guard run.
	 *
	 * @param mixed $pre     Previous value.
	 * @param int   $site_id Site id.
	 * @return mixed
	 */
	public static function filter_bulk_ability( $pre, $site_id ) {
		if ( ! Settings::get( 'block_unguarded_abilities' ) || self::is_guarded( $site_id ) ) {
			return $pre;
		}
		Journal::add( '', $site_id, 'OUTSIDE_GUARD_BLOCKED', array( 'path' => 'mainwp/run-updates-v1' ), array( 'severity' => States::SEVERITY_WARNING ) );
		return new \WP_Error( 'msug_blocked', 'Updates for this site run through MS Update Guard (backup gate). Use the Guard or disable its ability block.' );
	}

	/**
	 * MainWP finished a plugin/theme/translation update.
	 *
	 * @param mixed  $information Child response.
	 * @param string $type        plugin|theme|translation.
	 * @param string $slugs       Comma separated slugs.
	 * @param object $website     Site.
	 * @return void
	 */
	public static function after_update( $information, $type, $slugs, $website ) {
		$site_id = is_object( $website ) && isset( $website->id ) ? (int) $website->id : 0;
		if ( ! $site_id || self::is_guarded( $site_id ) ) {
			return;
		}
		self::outside( $site_id, 'mainwp_' . $type, array_filter( explode( ',', (string) $slugs ) ) );
	}

	/**
	 * MainWP finished a core update.
	 *
	 * @param mixed  $information Child response.
	 * @param object $website     Site.
	 * @return void
	 */
	public static function after_core_update( $information, $website ) {
		$site_id = is_object( $website ) && isset( $website->id ) ? (int) $website->id : 0;
		if ( ! $site_id || self::is_guarded( $site_id ) ) {
			return;
		}
		self::outside( $site_id, 'mainwp_core', array( 'wordpress' ) );
	}

	/**
	 * Compare versions after each sync with the last snapshot.
	 *
	 * @param object $website     Site.
	 * @param array  $information Sync data.
	 * @return void
	 */
	public static function after_sync( $website, $information ) {
		$site_id = is_object( $website ) && isset( $website->id ) ? (int) $website->id : 0;
		if ( ! $site_id || ! is_array( $information ) || ! Settings::site( $site_id )['enabled'] ) {
			return;
		}
		$now = array();
		foreach ( array(
			'plugins' => 'plugin',
			'themes'  => 'theme',
		) as $key => $type ) {
			foreach ( (array) ( isset( $information[ $key ] ) ? $information[ $key ] : array() ) as $row ) {
				if ( is_array( $row ) && isset( $row['slug'], $row['version'] ) ) {
					$now[ $type . ':' . $row['slug'] ] = (string) $row['version'];
				}
			}
		}
		if ( isset( $information['wpversion'] ) ) {
			$now['core:wordpress'] = (string) $information['wpversion'];
		}
		if ( ! $now ) {
			return;
		}
		$option = 'msug_versions_' . $site_id;
		$before = get_option( $option, null );
		update_option( $option, $now, false );
		if ( ! is_array( $before ) ) {
			return;
		}
		$changed = array();
		foreach ( $now as $key => $version ) {
			if ( isset( $before[ $key ] ) && $before[ $key ] !== $version && ! self::explained( $site_id, $key, $version ) ) {
				$changed[] = $key . ' ' . $before[ $key ] . ' -> ' . $version;
			}
		}
		if ( $changed ) {
			self::outside( $site_id, 'version_change_seen_in_sync', $changed );
		}
	}

	/**
	 * Whether a Guard run of this site (update started) targeted this version.
	 *
	 * @param int    $site_id Site id.
	 * @param string $key     type:slug.
	 * @param string $version New version.
	 * @return bool
	 */
	private static function explained( $site_id, $key, $version ) {
		if ( self::is_guarded( $site_id ) ) {
			return true;
		}
		foreach ( Run_Repository::query(
			array(
				'site_id' => $site_id,
				'limit'   => 10,
			)
		) as $run ) {
			if ( ! in_array( $run['state'], array( States::UPDATE_RUNNING, States::VERIFYING, States::TESTING, States::PASS, States::FAIL, States::UNKNOWN ), true ) ) {
				continue;
			}
			foreach ( $run['components'] as $c ) {
				if ( $c['type'] . ':' . $c['slug'] === $key && isset( $c['to_version'] ) && Outcome::same_version( $c['to_version'], $version ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Journal and alert an update outside the Guard.
	 *
	 * @param int    $site_id Site id.
	 * @param string $path    How it was noticed.
	 * @param array  $items   Components.
	 * @return void
	 */
	private static function outside( $site_id, $path, array $items ) {
		$items = array_slice( array_map( array( Util::class, 'short' ), $items ), 0, 50 );
		Journal::add(
			'',
			$site_id,
			'OUTSIDE_GUARD_UPDATE',
			array(
				'path'           => $path,
				'items'          => $items,
				'backup_checked' => false,
			),
			array( 'severity' => States::SEVERITY_WARNING )
		);
		Alerts::system(
			'outside:' . $site_id . ':' . md5( $path . implode( '|', $items ) ),
			States::SEVERITY_WARNING,
			sprintf( 'update outside the Guard on site %d', $site_id ),
			"An update ran without the Guard's backup gate. It is NOT covered by a verified restore point.\n\nSite: " . $site_id . "\nPath: " . $path . "\nItems:\n - " . implode( "\n - ", $items )
		);
	}
}
