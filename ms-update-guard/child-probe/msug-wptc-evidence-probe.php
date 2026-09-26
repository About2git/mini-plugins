<?php
/**
 * Plugin Name: MS Update Guard - WPTC evidence probe
 * Description: Read-only answer to the MainWP Dashboard about one WP Time Capsule backup: database dump present, uploads complete, errors, remote storage connected. No writes, no settings, no UI.
 * Version:     1.0.0
 * Author:      Marius Sonnentag
 * License:     GPL-2.0-or-later
 *
 * Install as a must-use plugin on child sites (wp-content/mu-plugins/). It is only reachable
 * through MainWP Child's signed "extra_execution" call, i.e. only the connected Dashboard can ask.
 *
 * Why it exists: MainWP Child 6.2 can start a WPTC backup and report that it stopped, but states
 * itself that it cannot tell whether that backup succeeded ("uncertain"), and it exposes no
 * database or upload evidence. See docs/ap0-schnittstellenmatrix.md, Gate 1.
 *
 * @package MSUpdateGuard
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'mainwp_child_extra_execution', 'msug_probe_answer', 10, 2 );

/**
 * Answer a probe request.
 *
 * @param array $information Response built so far.
 * @param array $post        Signed request parameters.
 * @return array
 */
function msug_probe_answer( $information, $post ) {
	if ( ! is_array( $post ) || ! isset( $post['msug_probe'] ) || 'backup_evidence' !== $post['msug_probe'] ) {
		return $information;
	}
	$information               = is_array( $information ) ? $information : array();
	$information['msug_probe'] = msug_probe_collect( isset( $post['backup_id'] ) ? (int) $post['backup_id'] : 0 );
	return $information;
}

/**
 * Collect evidence. Every failure yields ok=false instead of a guess.
 *
 * @param int $backup_id WPTC backup id (unix time) or 0 for a health check.
 * @return array
 */
function msug_probe_collect( $backup_id ) {
	global $wpdb;

	$out = array(
		'ok'            => false,
		'probe_version' => 1,
		'home_url'      => home_url( '/' ),
	);

	// Same loader MainWP Child uses before it talks to WPTC.
	if ( function_exists( 'wptc_load_files' ) ) {
		wptc_load_files();
	}
	if ( ! class_exists( 'WPTC_Factory' ) ) {
		$out['error'] = 'wptc_not_loaded';
		return $out;
	}

	try {
		$config = WPTC_Factory::get( 'config' );
		if ( ! is_object( $config ) || ! method_exists( $config, 'get_option' ) ) {
			$out['error'] = 'wptc_config_unavailable';
			return $out;
		}

		$repo      = defined( 'DEFAULT_REPO' ) ? (string) DEFAULT_REPO : '';
		$connected = false;
		if ( '' !== $repo && class_exists( 'WPTC_Base_Factory' ) ) {
			$settings = WPTC_Base_Factory::get( 'Wptc_Settings' );
			if ( is_object( $settings ) && method_exists( $settings, 'get_connected_cloud_info' ) ) {
				$info      = $settings->get_connected_cloud_info();
				$connected = ! empty( $info ) && 'Not connected' !== $info;
			}
		}
		$out['cloud']       = array(
			'repo'      => $repo,
			'connected' => $connected,
		);
		$out['in_progress'] = (bool) $config->get_option( 'in_progress' );

		if ( $backup_id <= 0 ) {
			$out['ok'] = true;
			return $out;
		}

		$suppress = $wpdb->suppress_errors( true );

		$backups = $wpdb->base_prefix . 'wptc_backups';
		$files   = $wpdb->base_prefix . 'wptc_processed_files';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Provider-owned tables, live values.
		$meta = $wpdb->get_row( $wpdb->prepare( 'SELECT backup_id, backup_type, files_count, backup_name FROM %i WHERE backup_id = %s', $backups, (string) $backup_id ), ARRAY_A );
		$db   = $wpdb->get_results( $wpdb->prepare( 'SELECT offset, uploadid FROM %i WHERE backupID = %d AND file LIKE %s', $files, $backup_id, '%backup.sql%' ), ARRAY_A );
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE backupID = %d AND ( offset > 0 OR ( uploadid IS NOT NULL AND uploadid NOT IN ('', '0') ) )", $files, $backup_id ) );
		// phpcs:enable
		$failed = '' !== $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( $failed ) {
			$out['error'] = 'wptc_tables_unreadable';
			return $out;
		}

		$db_complete = ! empty( $db );
		foreach ( (array) $db as $row ) {
			if ( (int) $row['offset'] > 0 || ( null !== $row['uploadid'] && '' !== $row['uploadid'] && '0' !== (string) $row['uploadid'] ) ) {
				$db_complete = false;
			}
		}

		$errors = method_exists( $config, 'get_option_arr_bool_compat' ) ? $config->get_option_arr_bool_compat( 'mail_backup_errors' ) : $config->get_option( 'mail_backup_errors' );

		$out['backup_id']             = $backup_id;
		$out['meta_row']              = ! empty( $meta );
		$out['files_count']           = $meta ? (int) $meta['files_count'] : 0;
		$out['backup_name']           = $meta ? substr( wp_strip_all_tags( (string) $meta['backup_name'] ), 0, 100 ) : '';
		$out['db_dump']               = array(
			'present'  => ! empty( $db ),
			'complete' => $db_complete,
		);
		$out['incomplete_uploads']    = $open;
		$out['success_complete_time'] = (int) $config->get_option( 'last_backup_success_complete_time' );
		$out['last_backup_time']      = (int) $config->get_option( 'last_backup_time' );
		$out['error_count']           = is_array( $errors ) ? count( $errors, COUNT_RECURSIVE ) : ( empty( $errors ) ? 0 : 1 );
		$out['ok']                    = true;
	} catch ( Throwable $e ) {
		$out['ok']    = false;
		$out['error'] = 'exception';
	}
	return $out;
}
