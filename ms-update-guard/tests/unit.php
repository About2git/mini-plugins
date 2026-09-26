<?php
/**
 * Unit tests for the decision logic. Run: php tests/unit.php
 *
 * Each block names the acceptance case from spec section 8 it covers.
 *
 * @package MSUpdateGuard
 */

require __DIR__ . '/bootstrap.php';

use MSUpdateGuard\Backup_Evidence as BE;
use MSUpdateGuard\Outcome;
use MSUpdateGuard\Signature;
use MSUpdateGuard\States;
use MSUpdateGuard\Util;

$now     = 1790000000;
$started = $now - 600;
$bid     = $started + 3;

$op_ok   = array( 'state' => 'uncertain', 'started_at' => Util::iso( $started ) );
$site_ok = array(
	'ok'                     => true,
	'plugin_state'           => 'ready',
	'account_state'          => 'connected',
	'active_operation_count' => 0,
	'last_attempt_at'        => Util::iso( $bid ),
);
$probe_ok = array(
	'ok'                    => true,
	'probe_version'         => 1,
	'home_url'              => 'https://www.kunde.de/',
	'backup_id'             => $bid,
	'in_progress'           => false,
	'meta_row'              => true,
	'files_count'           => 42,
	'backup_name'           => 'MS Update Guard abcd',
	'db_dump'               => array( 'present' => true, 'complete' => true ),
	'incomplete_uploads'    => 0,
	'success_complete_time' => $bid + 400,
	'error_count'           => 0,
	'cloud'                 => array( 'repo' => 's3', 'connected' => true ),
);
$ctx = array(
	'expected_home' => 'https://kunde.de',
	'not_before'    => $started - 60,
	'max_age'       => 7200,
	'now'           => $now,
);

// --- Case 1: backup successful, remote confirmed -> BACKUP_READY with restore point.
$r = BE::evaluate( $op_ok, $site_ok, $probe_ok, $ctx );
T::ok( $r['ok'], 'success: ok' );
T::eq( $bid, $r['restore_point']['backup_id'], 'success: backup id' );
T::eq( 'files+database', $r['restore_point']['scope'], 'success: scope' );

// --- Case 2: job still running, failed, remote missing, timeout-relevant pending.
$r = BE::evaluate( array( 'state' => 'running', 'started_at' => Util::iso( $started ) ), $site_ok, null, $ctx );
T::ok( $r['pending'] && ! $r['ok'], 'running: pending' );
$r = BE::evaluate( $op_ok, array_merge( $site_ok, array( 'active_operation_count' => 1 ) ), $probe_ok, $ctx );
T::ok( $r['pending'], 'site busy: pending' );
$r = BE::evaluate( array( 'state' => 'failed', 'started_at' => Util::iso( $started ) ), $site_ok, $probe_ok, $ctx );
T::eq( 'backup_failed', $r['code'], 'failed op' );
T::ok( ! $r['pending'], 'failed op is final' );
$r = BE::evaluate( array( 'state' => 'cancelled', 'started_at' => Util::iso( $started ) ), $site_ok, $probe_ok, $ctx );
T::eq( 'backup_cancelled', $r['code'], 'cancelled op' );
$r = BE::evaluate( $op_ok, array_merge( $site_ok, array( 'last_attempt_at' => Util::iso( $started - 86400 ) ) ), $probe_ok, $ctx );
T::eq( 'backup_not_completed', $r['code'], 'stopped backup: last completed older than start' );
$r = BE::evaluate( $op_ok, $site_ok, array_merge( $probe_ok, array( 'cloud' => array( 'repo' => 's3', 'connected' => false ) ) ), $ctx );
T::eq( 'remote_unconfirmed', $r['code'], 'remote not connected' );
$r = BE::evaluate( $op_ok, $site_ok, array_merge( $probe_ok, array( 'incomplete_uploads' => 3 ) ), $ctx );
T::eq( 'upload_incomplete', $r['code'], 'uploads open' );
$r = BE::evaluate( $op_ok, $site_ok, array_merge( $probe_ok, array( 'db_dump' => array( 'present' => true, 'complete' => false ) ) ), $ctx );
T::eq( 'upload_incomplete', $r['code'], 'db upload open' );
$r = BE::evaluate( $op_ok, $site_ok, array_merge( $probe_ok, array( 'error_count' => 2 ) ), $ctx );
T::eq( 'backup_errors', $r['code'], 'backup errors' );
$r = BE::evaluate( $op_ok, array_merge( $site_ok, array( 'account_state' => 'disconnected' ) ), $probe_ok, $ctx );
T::eq( 'wptc_account_disconnected', $r['code'], 'account disconnected' );

// --- Case 3: other site, too old, database only / files missing.
$r = BE::evaluate( $op_ok, $site_ok, array_merge( $probe_ok, array( 'home_url' => 'https://anderer-kunde.de/' ) ), $ctx );
T::eq( 'backup_other_site', $r['code'], 'other site' );
$r = BE::evaluate( $op_ok, $site_ok, array_merge( $probe_ok, array( 'backup_id' => $bid - 10 ) ), $ctx );
T::eq( 'backup_id_mismatch', $r['code'], 'probe answered for another backup' );
$old_ctx = array_merge( $ctx, array( 'not_before' => 0, 'max_age' => 600, 'now' => $bid + 3600 ) );
$r       = BE::evaluate( null, $site_ok, $probe_ok, $old_ctx );
T::eq( 'backup_too_old', $r['code'], 'too old' );
$r = BE::evaluate( null, $site_ok, $probe_ok, array_merge( $ctx, array( 'not_before' => $bid + 3600 ) ) );
T::eq( 'backup_not_fresh', $r['code'], 'before this update window' );
$r = BE::evaluate( $op_ok, $site_ok, array_merge( $probe_ok, array( 'meta_row' => false, 'files_count' => 0 ) ), $ctx );
T::eq( 'files_missing', $r['code'], 'database only' );
$r = BE::evaluate( $op_ok, $site_ok, array_merge( $probe_ok, array( 'db_dump' => array( 'present' => false, 'complete' => false ) ) ), $ctx );
T::eq( 'database_missing', $r['code'], 'files only' );
$r = BE::evaluate( $op_ok, $site_ok, array_merge( $probe_ok, array( 'success_complete_time' => $bid - 5 ) ), $ctx );
T::eq( 'completion_marker_missing', $r['code'], 'no success marker' );

// --- Gate 1: without the probe nothing is proven.
$r = BE::evaluate( $op_ok, $site_ok, null, $ctx );
T::eq( 'evidence_insufficient', $r['code'], 'no probe -> blocked' );
T::ok( ! $r['pending'], 'no probe is final' );

// --- Outcome.
$c       = array( array( 'type' => 'plugin', 'slug' => 'akismet/akismet.php', 'from_version' => '5.0', 'to_version' => '5.1' ) );
$upd_ok  = array( 'status' => 'responded', 'updated' => array( 'akismet/akismet.php' ), 'errors' => array() );
$ver_ok  = array( 'status' => 'ok', 'components' => array( 'akismet/akismet.php' => array( 'verdict' => 'updated', 'actual' => '5.1' ) ) );
$test_ok = array( 'status' => 'result', 'http' => 'HTTP_PASS', 'smoke' => 'SMOKE_PASS', 'smoke_mandatory' => true );

$o = Outcome::evaluate( $c, $upd_ok, $ver_ok, $test_ok );
T::eq( 'PASS', $o['state'], 'outcome pass' );
T::eq( 'INFO', $o['severity'], 'pass severity' );

// Case 4: HTTP 500 after update / child died before callback.
$o = Outcome::evaluate( $c, $upd_ok, $ver_ok, array( 'status' => 'result', 'http' => 'HTTP_FAIL', 'smoke' => 'SMOKE_SKIPPED', 'smoke_mandatory' => true ) );
T::eq( 'FAIL', $o['state'], '500 -> FAIL' );
T::eq( 'CRITICAL', $o['severity'], '500 -> CRITICAL' );
$o = Outcome::evaluate( $c, array( 'status' => 'no_result' ), array( 'status' => 'sync_failed' ), array( 'status' => 'result', 'http' => 'HTTP_FAIL', 'smoke' => 'SMOKE_SKIPPED' ) );
T::eq( 'FAIL', $o['state'], 'child died: FAIL' );
T::eq( 'CRITICAL', $o['severity'], 'child died: CRITICAL' );
$o = Outcome::evaluate( $c, $upd_ok, $ver_ok, array( 'status' => 'no_result' ) );
T::eq( 'UNKNOWN', $o['state'], 'no runner result -> UNKNOWN' );
T::eq( 'CRITICAL', $o['severity'], 'no runner result -> CRITICAL' );
$o = Outcome::evaluate( $c, $upd_ok, $ver_ok, array( 'status' => 'trigger_failed' ) );
T::eq( 'UNKNOWN', $o['state'], 'runner unreachable -> UNKNOWN' );

// Case 5: response says success, version unchanged.
$o = Outcome::evaluate( $c, $upd_ok, array( 'status' => 'ok', 'components' => array( 'akismet/akismet.php' => array( 'verdict' => 'unchanged', 'actual' => '5.0' ) ) ), $test_ok );
T::eq( 'FAIL', $o['state'], 'unchanged version -> FAIL' );
T::ok( in_array( 'response_success_but_version_unchanged:akismet/akismet.php', $o['reasons'], true ), 'reason names contradiction' );

// Simulated abort without callback: versions look right but no update result -> never PASS.
$o = Outcome::evaluate( $c, array( 'status' => 'no_result' ), $ver_ok, $test_ok );
T::eq( 'UNKNOWN', $o['state'], 'no update result -> UNKNOWN' );
$o = Outcome::evaluate( $c, $upd_ok, array( 'status' => 'sync_failed' ), $test_ok );
T::eq( 'UNKNOWN', $o['state'], 'resync failed -> UNKNOWN' );
T::eq( 'ERROR', $o['severity'], 'resync failed, site up -> ERROR' );
$o = Outcome::evaluate( $c, array( 'status' => 'responded', 'updated' => array(), 'errors' => array( 'akismet/akismet.php' => 'x' ) ), $ver_ok, $test_ok );
T::eq( 'UNKNOWN', $o['state'], 'error reported but version changed -> UNKNOWN' );

// Case 6: HTTP 200, smoke fails.
$o = Outcome::evaluate( $c, $upd_ok, $ver_ok, array( 'status' => 'result', 'http' => 'HTTP_PASS', 'smoke' => 'SMOKE_FAIL' ) );
T::eq( 'FAIL', $o['state'], 'smoke fail -> FAIL' );
T::eq( 'ERROR', $o['severity'], 'smoke fail -> ERROR' );
$o = Outcome::evaluate( $c, $upd_ok, $ver_ok, array( 'status' => 'result', 'http' => 'HTTP_PASS', 'smoke' => 'SMOKE_UNKNOWN' ) );
T::eq( 'UNKNOWN', $o['state'], 'smoke unknown -> UNKNOWN' );
$o = Outcome::evaluate( $c, $upd_ok, $ver_ok, array( 'status' => 'result', 'http' => 'HTTP_PASS', 'smoke' => 'SMOKE_SKIPPED', 'smoke_mandatory' => false ) );
T::eq( 'PASS', $o['state'], 'http-only profile -> PASS' );
$o = Outcome::evaluate( $c, $upd_ok, $ver_ok, array( 'status' => 'result', 'http' => 'HTTP_PASS', 'smoke' => 'SMOKE_SKIPPED', 'smoke_mandatory' => true ) );
T::eq( 'UNKNOWN', $o['state'], 'mandatory smoke skipped -> never PASS' );
$o = Outcome::evaluate( $c, array( 'status' => 'responded', 'updated' => array(), 'errors' => array( 'akismet/akismet.php' => 'download failed' ) ), array( 'status' => 'ok', 'components' => array( 'akismet/akismet.php' => array( 'verdict' => 'unchanged', 'actual' => '5.0' ) ) ), $test_ok );
T::eq( 'FAIL', $o['state'], 'failed update, site reachable -> FAIL' );
T::eq( 'ERROR', $o['severity'], 'failed update, site reachable -> ERROR' );

T::eq( 'updated', Outcome::version_verdict( '5.1.0', '5.1', '5.0' ), 'version compare normalizes' );
T::eq( 'other', Outcome::version_verdict( '5.2', '5.1', '5.0' ), 'unexpected version' );
T::eq( 'missing', Outcome::version_verdict( null, '5.1', '5.0' ), 'missing version' );

// --- Signature (F10).
$body    = '{"run_id":"x"}';
$secret  = str_repeat( 'a', 32 );
$header  = Signature::sign( $body, $secret );
T::ok( Signature::verify( $body, $header, array( $secret ) ), 'verify' );
T::ok( ! Signature::verify( $body . ' ', $header, array( $secret ) ), 'changed bytes rejected' );
T::ok( Signature::verify( $body, $header, array( str_repeat( 'b', 32 ), $secret ) ), 'previous key accepted during rotation' );
T::ok( ! Signature::verify( $body, $header, array( 'short' ) ), 'short secret ignored' );
// Cross-language vector; runner/test/signature.test.js asserts the same value.
T::eq( 'v1=37de43ef025b8ac0e43203c93db021a8d09b1b9e021b04ebb9490553a144f456', Signature::sign( 'msug-vector', 'msug-test-vector-secret-0123456789' ), 'cross-language vector' );

$t   = time();
$env = array(
	'event_id'   => wp_generate_uuid4(),
	'issued_at'  => Util::iso( $t ),
	'expires_at' => Util::iso( $t + 300 ),
);
T::eq( true, Signature::check_envelope( $env ), 'envelope ok' );
T::eq( 'expired', Signature::check_envelope( array_merge( $env, array( 'expires_at' => Util::iso( $t - 1 ) ) ) ), 'expired' );
T::eq( 'issued_in_future', Signature::check_envelope( array_merge( $env, array( 'issued_at' => Util::iso( $t + 600 ), 'expires_at' => Util::iso( $t + 700 ) ) ) ), 'future' );
T::eq( 'lifetime_too_long', Signature::check_envelope( array_merge( $env, array( 'expires_at' => Util::iso( $t + 3600 ) ) ) ), 'lifetime' );
T::eq( 'bad_event_id', Signature::check_envelope( array_merge( $env, array( 'event_id' => 'x' ) ) ), 'event id' );
T::eq( 'bad_time', Signature::check_envelope( array_merge( $env, array( 'issued_at' => '2026-01-01 00:00:00' ) ) ), 'time format' );

// --- State machine.
T::ok( States::can( States::BACKUP_READY, States::UPDATE_RUNNING ), 'ready -> update' );
T::ok( ! States::can( States::BACKUP_PENDING, States::UPDATE_RUNNING ), 'no update without BACKUP_READY' );
T::ok( ! States::can( States::PREFLIGHT, States::UPDATE_RUNNING ), 'no shortcut from preflight' );
T::ok( ! States::can( States::UPDATE_RUNNING, States::BLOCKED ), 'after update start no BLOCKED' );
T::ok( ! States::can( States::UPDATE_RUNNING, States::PASS ), 'no PASS without verification and test' );
T::ok( ! States::can( States::PASS, States::QUEUED ), 'terminal' );
T::ok( States::holds_site_lock( States::FAIL ) && States::holds_site_lock( States::UNKNOWN ), 'FAIL/UNKNOWN keep lock' );
T::ok( ! States::holds_site_lock( States::PASS ) && ! States::holds_site_lock( States::BLOCKED ), 'PASS/BLOCKED release lock' );
$reachable = array( States::QUEUED );
for ( $i = 0; $i < 10; $i++ ) {
	foreach ( $reachable as $s ) {
		foreach ( isset( States::TRANSITIONS[ $s ] ) ? States::TRANSITIONS[ $s ] : array() as $to ) {
			if ( ! in_array( $to, $reachable, true ) ) {
				$reachable[] = $to;
			}
		}
	}
}
// Every path into UPDATE_RUNNING passes BACKUP_READY.
$into_update = array();
foreach ( States::TRANSITIONS as $from => $tos ) {
	if ( in_array( States::UPDATE_RUNNING, $tos, true ) ) {
		$into_update[] = $from;
	}
}
T::eq( array( States::BACKUP_READY ), $into_update, 'only BACKUP_READY leads to UPDATE_RUNNING' );

T::done();
