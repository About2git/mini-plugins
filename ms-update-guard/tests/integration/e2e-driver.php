<?php
/**
 * Local end-to-end acceptance run (spec section 8) against a real MainWP Dashboard, a real
 * MainWP Child and the real runner. Only the WPTC backup provider is a double (see
 * fixtures/dashboard-backup-double.php); scenario "real" uses the real WPTC protocol.
 *
 * Usage: wp eval-file tests/integration/e2e-driver.php <child-wp-path> <wp-cli-command>
 *
 * @package MSUpdateGuard
 */

use MSUpdateGuard\Journal;
use MSUpdateGuard\Plugin;
use MSUpdateGuard\Run_Repository;
use MSUpdateGuard\Schema;
use MSUpdateGuard\Settings;
use MSUpdateGuard\Signature;
use MSUpdateGuard\States;
use MSUpdateGuard\Util;

// phpcs:disable -- Test driver.

// eval-file runs inside a function scope, so shared state lives in $GLOBALS.
$GLOBALS['e2e_child_cli'] = isset( $args[0] ) ? $args[0] : '';
$GLOBALS['e2e_results']   = array();
$plugin                   = 'msug-dummy/msug-dummy.php';

function e2e_child( $cmd ) {
	return trim( (string) shell_exec( $GLOBALS['e2e_child_cli'] . ' ' . $cmd . ' 2>/dev/null' ) );
}

function e2e_check( $label, $cond, $detail = '' ) {
	$GLOBALS['e2e_results'][] = array( $label, (bool) $cond, $detail );
	WP_CLI::log( ( $cond ? '  PASS ' : '  FAIL ' ) . $label . ( $cond || '' === $detail ? '' : ' -- ' . $detail ) );
}

function e2e_events( $run_id ) {
	return array_column( Journal::for_run( $run_id ), 'event' );
}

function e2e_mails() {
	$m = get_option( 'msug_test_mails', array() );
	return is_array( $m ) ? $m : array();
}

/** Tick until the run is terminal; waits are shortened by resetting next_check_at. */
function e2e_drive( $run_id, $max = 120, $on_tick = null ) {
	global $wpdb;
	$table = Schema::table( 'runs' );
	for ( $i = 0; $i < $max; $i++ ) {
		Plugin::engine()->tick( 50 );
		wp_cache_flush();
		$run = Run_Repository::get( $run_id );
		if ( States::is_terminal( $run['state'] ) ) {
			return $run;
		}
		if ( $on_tick ) {
			$on_tick( $run );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_check_at = NULL WHERE run_id = %s", $run_id ) );
		sleep( 1 );
	}
	return Run_Repository::get( $run_id );
}

function e2e_offer( $version ) {
	e2e_child( 'option update msug_dummy_offer ' . $version );
}

function e2e_installed() {
	return e2e_child( "eval 'require_once ABSPATH . \"wp-admin/includes/plugin.php\"; echo get_plugin_data( WP_PLUGIN_DIR . \"/msug-dummy/msug-dummy.php\", false, false )[\"Version\"];'" );
}

function e2e_run( $selection, $backup = 'ok', $options = array() ) {
	update_option( 'msug_double_backup', $backup, false );
	delete_option( 'msug_double_calls' );
	$run = Plugin::engine()->enqueue( 1, $selection, 'cli', $options );
	if ( is_wp_error( $run ) ) {
		WP_CLI::error( $run->get_error_message() );
	}
	return $run;
}

function e2e_post_callback( $body, $secret ) {
	$res = wp_remote_post(
		rest_url( 'ms-update-guard/v1/runner-result' ),
		array(
			'headers' => array( 'Content-Type' => 'application/json', 'X-MSUG-Signature' => Signature::sign( $body, $secret ) ),
			'body'    => $body,
			'timeout' => 20,
		)
	);
	return is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
}

wp_set_current_user( 0 );
delete_option( 'msug_test_mails' );
$one = array( array( 'type' => 'plugin', 'slug' => $plugin ) );

// ---------------------------------------------------------------------------------------------
WP_CLI::log( 'S1 backup confirmed, update and tests pass -> PASS with complete chain' );
e2e_offer( '1.1.0' );
$run    = e2e_run( $one );
$busy   = Plugin::engine()->enqueue( 1, $one, 'cli' );
e2e_check( 'F01 second run for the same site is refused', is_wp_error( $busy ) && 'msug_site_busy' === $busy->get_error_code() );
$run    = e2e_drive( $run['run_id'] );
$events = e2e_events( $run['run_id'] );
e2e_check( 'state PASS', States::PASS === $run['state'], $run['state'] . ' ' . $run['reason'] );
e2e_check( 'BACKUP_READY journaled before UPDATE_STARTED', false !== array_search( 'BACKUP_READY', $events, true ) && array_search( 'BACKUP_READY', $events, true ) < array_search( 'UPDATE_STARTED', $events, true ), implode( ',', $events ) );
e2e_check( 'restore point id stored', ! empty( $run['data']['backup']['restore_point']['backup_id'] ) );
e2e_check( 'backup started with run id as idempotency key', in_array( 'start:' . $run['run_id'], (array) get_option( 'msug_double_calls' ), true ) );
e2e_check( 'pre-update test ran', 'HTTP_PASS' === ( $run['data']['preflight']['pretest']['result']['http'] ?? '' ) );
e2e_check( 'post-update HTTP and smoke passed', 'HTTP_PASS' === $run['data']['test']['result']['http'] && 'SMOKE_PASS' === $run['data']['test']['result']['smoke'] );
e2e_check( 'version verified by resync', 'updated' === $run['data']['verify']['components'][ $plugin ]['verdict'] );
e2e_check( 'child really runs 1.1.0', '1.1.0' === e2e_installed(), e2e_installed() );
e2e_check( 'site lock released', null === $run['active_lock'] );
e2e_check( 'F09 regression shown as not connected', 'not_connected' === $run['data']['regression']['state'] );
e2e_check( 'no alert for PASS', 0 === count( e2e_mails() ) );

// ---------------------------------------------------------------------------------------------
WP_CLI::log( 'S2 real WPTC without cloud account -> BLOCKED, no update call' );
e2e_offer( '1.4.0' );
$run    = e2e_drive( e2e_run( $one, 'real' )['run_id'] );
$events = e2e_events( $run['run_id'] );
e2e_check( 'state BLOCKED', States::BLOCKED === $run['state'], $run['state'] );
e2e_check( 'reason wptc_account_disconnected', 'wptc_account_disconnected' === $run['reason'], (string) $run['reason'] );
e2e_check( 'no UPDATE_STARTED', ! in_array( 'UPDATE_STARTED', $events, true ) );
e2e_check( 'child unchanged', '1.1.0' === e2e_installed() );
e2e_check( 'WARNING alert sent', (bool) preg_grep( '/^\[WARNING\].*blocked before update: wptc_account_disconnected/', array_column( e2e_mails(), 'subject' ) ) );

// ---------------------------------------------------------------------------------------------
WP_CLI::log( 'S3 unusable restore points -> BLOCKED with reason, never an update' );
foreach ( array( 'failed' => 'backup_failed', 'other_site' => 'backup_other_site', 'db_only' => 'files_missing', 'no_probe' => 'evidence_insufficient' ) as $scenario => $reason ) {
	$run = e2e_drive( e2e_run( $one, $scenario )['run_id'] );
	e2e_check( "{$scenario}: BLOCKED {$reason}", States::BLOCKED === $run['state'] && $reason === $run['reason'], $run['state'] . ' ' . $run['reason'] );
	e2e_check( "{$scenario}: no update", ! in_array( 'UPDATE_STARTED', e2e_events( $run['run_id'] ), true ) && '1.1.0' === e2e_installed() );
}
$table = Schema::table( 'runs' );
$run   = e2e_run( $one, 'running' );
$run   = e2e_drive(
	$run['run_id'],
	60,
	function ( $r ) use ( $table ) {
		global $wpdb;
		if ( States::BACKUP_PENDING === $r['state'] && ! empty( $r['data']['backup']['polls'] ) ) {
			// Time travel: the backup deadline passes while WPTC still reports running.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET deadline_at = %s WHERE run_id = %s", Util::now( -1 ), $r['run_id'] ) );
		}
	}
);
e2e_check( 'running forever: BLOCKED backup_timeout', States::BLOCKED === $run['state'] && 'backup_timeout' === $run['reason'], $run['state'] . ' ' . $run['reason'] );

// ---------------------------------------------------------------------------------------------
WP_CLI::log( 'S4 response says success, installed version unchanged -> FAIL, lock held' );
e2e_offer( '1.3.0' ); // The 1.3.0 package still carries version 1.1.0.
$run = e2e_drive( e2e_run( $one )['run_id'] );
e2e_check( 'state FAIL', States::FAIL === $run['state'], $run['state'] . ' ' . $run['reason'] );
e2e_check( 'contradiction named', false !== strpos( (string) $run['reason'], 'version_unchanged' ), (string) $run['reason'] );
e2e_check( 'site lock held', null !== $run['active_lock'] );
$blocked = Plugin::engine()->enqueue( 1, $one, 'cli' );
e2e_check( 'no further Guard run while unresolved', is_wp_error( $blocked ) );
e2e_check( 'ERROR alert with restore point', (bool) preg_grep( '/^\[ERROR\]/', array_column( e2e_mails(), 'subject' ) ) );
Run_Repository::resolve( $run['run_id'], 'e2e: checked, package was mislabelled' );
e2e_check( 'resolve releases lock', null === Run_Repository::get( $run['run_id'] )['active_lock'] );

// ---------------------------------------------------------------------------------------------
WP_CLI::log( 'S5 update breaks the front end (HTTP 500) -> external check, FAIL CRITICAL' );
e2e_offer( '1.2.0' );
$run = e2e_drive( e2e_run( $one )['run_id'] );
e2e_check( 'state FAIL', States::FAIL === $run['state'], $run['state'] . ' ' . $run['reason'] );
e2e_check( 'severity CRITICAL', States::SEVERITY_CRITICAL === $run['severity'] );
e2e_check( 'runner saw HTTP 500', 500 === (int) $run['data']['test']['result']['http_status'] );
e2e_check( 'browser skipped after HTTP failure', 'SMOKE_SKIPPED' === $run['data']['test']['result']['smoke'] );
$mails = e2e_mails();
$mail  = end( $mails );
e2e_check( 'CRITICAL alert names run, backup and link', 0 === strpos( $mail['subject'], '[CRITICAL]' ) && false !== strpos( $mail['message'], $run['run_id'] ) && false !== strpos( $mail['message'], 'WPTC restore point' ) && false !== strpos( $mail['message'], 'tools.php?page=ms-update-guard' ) );
Run_Repository::resolve( $run['run_id'], 'e2e' );

// ---------------------------------------------------------------------------------------------
WP_CLI::log( 'S6 pre-existing defect blocks by default, explicit acceptance continues' );
e2e_offer( '1.4.0' );
$run = e2e_drive( e2e_run( $one )['run_id'] );
e2e_check( 'BLOCKED preexisting_defect', States::BLOCKED === $run['state'] && 'preexisting_defect' === $run['reason'], $run['state'] . ' ' . $run['reason'] );
$run = e2e_drive( e2e_run( $one, 'ok', array( 'accept_preexisting_defect' => true ) )['run_id'] );
e2e_check( 'accepted defect journaled', in_array( 'PREEXISTING_DEFECT_ACCEPTED', e2e_events( $run['run_id'] ), true ) );
e2e_check( 'fixing update passes', States::PASS === $run['state'] && '1.4.0' === e2e_installed(), $run['state'] . ' ' . $run['reason'] );

// ---------------------------------------------------------------------------------------------
WP_CLI::log( 'S7 Parent dies during the update call -> no blind retry, result from evidence' );
e2e_offer( '1.1.0' );
e2e_child( 'option update msug_dummy_force_offer 1' );
$run = e2e_run( $one );
$run = e2e_drive(
	$run['run_id'],
	60,
	function ( $r ) use ( $table ) {
		global $wpdb;
		if ( States::BACKUP_READY === $r['state'] ) {
			// Simulate a crash right after UPDATE_STARTED was persisted: the executor never ran.
			$data           = $r['data'];
			$data['update'] = array( 'started_at' => Util::iso( time() - 3600 ) );
			$wpdb->update( $table, array( 'state' => States::UPDATE_RUNNING, 'data' => Util::json( $data ), 'deadline_at' => Util::now( -60 ), 'row_version' => $r['row_version'] + 1 ), array( 'run_id' => $r['run_id'] ) );
			Journal::add( $r['run_id'], $r['site_id'], 'UPDATE_STARTED', array( 'simulated_crash' => true ), array( 'state_from' => States::BACKUP_READY, 'state_to' => States::UPDATE_RUNNING ) );
		}
	}
);
e2e_check( 'UPDATE_NO_RESULT journaled', in_array( 'UPDATE_NO_RESULT', e2e_events( $run['run_id'] ), true ) );
e2e_check( 'no second update call', '1.4.0' === e2e_installed() );
e2e_check( 'not PASS', States::PASS !== $run['state'], $run['state'] );
e2e_child( 'option delete msug_dummy_force_offer' );
Run_Repository::resolve( $run['run_id'], 'e2e' );

// ---------------------------------------------------------------------------------------------
WP_CLI::log( 'S8 signed callbacks: forged, replayed and stale deliveries change nothing' );
$secret  = Settings::secret( 'callback' );
$body    = wp_json_encode( Signature::envelope( array( 'run_id' => $run['run_id'], 'test_id' => $run['data']['test']['test_id'], 'result' => array( 'run_id' => $run['run_id'], 'test_id' => $run['data']['test']['test_id'], 'http' => 'HTTP_PASS', 'smoke' => 'SMOKE_PASS' ) ) ) );
e2e_check( 'forged signature -> 401', 401 === e2e_post_callback( $body, str_repeat( 'x', 40 ) ) );
e2e_check( 'late callback for finished run -> 409', 409 === e2e_post_callback( $body, $secret ) );
e2e_check( 'replay of the same bytes -> duplicate (200)', 200 === e2e_post_callback( $body, $secret ) );
$after = Run_Repository::get( $run['run_id'] );
e2e_check( 'finished run unchanged by late callback', $after['state'] === $run['state'] );

// ---------------------------------------------------------------------------------------------
WP_CLI::log( 'S9 updates outside the Guard are refused or reported' );
wp_set_current_user( 1 );
$bulk = wp_get_ability( 'mainwp/run-updates-v1' )->execute( array( 'site_ids_or_domains' => array( 1 ), 'types' => array( 'plugins' ) ) );
$bulk_blocked = is_wp_error( $bulk ) || ( isset( $bulk['errors'][0]['code'] ) && 'msug_blocked' === $bulk['errors'][0]['code'] );
e2e_check( 'MainWP bulk update ability refused', $bulk_blocked, wp_json_encode( is_wp_error( $bulk ) ? $bulk->get_error_code() : $bulk ) );
e2e_offer( '1.1.0' );
e2e_child( 'option update msug_dummy_force_offer 1' );
wp_get_ability( 'mainwp/sync-sites-v1' )->execute( array( 'site_ids' => array( 1 ) ) );
wp_get_ability( 'mainwp/update-site-plugins-v1' )->execute( array( 'site_id_or_domain' => 1, 'slugs' => array( $plugin ) ) );
wp_get_ability( 'mainwp/sync-sites-v1' )->execute( array( 'site_ids' => array( 1 ) ) );
e2e_child( 'option delete msug_dummy_force_offer' );
wp_set_current_user( 0 );
$outside = array_filter( Journal::site_events( 20 ), function ( $e ) { return 'OUTSIDE_GUARD_UPDATE' === $e['event']; } );
e2e_check( 'manual MainWP update journaled as OUTSIDE_GUARD_UPDATE', count( $outside ) >= 1 );
e2e_check( 'marked as not backup-checked', false !== strpos( (string) reset( $outside )['data'], '"backup_checked":false' ) );
e2e_check( 'native auto updates forced off', 0 === (int) get_option( 'mainwp_pluginAutomaticDailyUpdate' ) );

// ---------------------------------------------------------------------------------------------
WP_CLI::log( 'S10 Guard scheduler replaces the native queue' );
delete_option( 'msug_last_schedule' );
e2e_offer( '1.4.0' );
$scheduled = Plugin::schedule_all();
e2e_check( 'one scheduled run', 1 === count( $scheduled ) && MSUpdateGuard\Util::is_uuid( reset( $scheduled ) ), wp_json_encode( $scheduled ) );
$again = Plugin::schedule_all();
e2e_check( 'not scheduled twice per day', 0 === count( $again ) );
$run = e2e_drive( reset( $scheduled ) );
e2e_check( 'components fixed from inventory', in_array( 'COMPONENTS_FIXED', e2e_events( $run['run_id'] ), true ) && $plugin === $run['components'][0]['slug'] );
e2e_check( 'scheduled run passes', States::PASS === $run['state'], $run['state'] . ' ' . $run['reason'] );

// ---------------------------------------------------------------------------------------------
WP_CLI::log( 'S11 heartbeat: every completed tick pings the external monitor' );
Settings::save( array( 'heartbeat_url' => 'http://127.0.0.1:8898/' ) );
delete_option( MSUpdateGuard\Heartbeat::OPTION );
Plugin::engine()->tick( 5 );
$hb = MSUpdateGuard\Heartbeat::status();
e2e_check( 'heartbeat ping accepted', ! empty( $hb['ok_at'] ) && 200 === (int) $hb['last_code'], wp_json_encode( $hb ) );
Settings::save( array( 'heartbeat_url' => '' ) );

// ---------------------------------------------------------------------------------------------
$failed = array_filter( $GLOBALS['e2e_results'], function ( $r ) { return ! $r[1]; } );
WP_CLI::log( sprintf( '%d checks, %d failed', count( $GLOBALS['e2e_results'] ), count( $failed ) ) );
if ( $failed ) {
	WP_CLI::halt( 1 );
}
