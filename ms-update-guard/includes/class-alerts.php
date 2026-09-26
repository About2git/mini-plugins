<?php
/**
 * Alerting with deduplication and reminders (F07, spec 6).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one alert per run and failure picture, reminds while a run stays unresolved.
 * Channels: e-mail (wp_mail) and one generic JSON webhook (e.g. Better Stack, Slack relay).
 */
class Alerts {

	/**
	 * Alert for a run.
	 *
	 * @param array  $run      Run.
	 * @param string $severity Severity.
	 * @param string $title    Short title.
	 * @param array  $evidence Evidence for the message.
	 * @param bool   $reminder Whether this is a reminder.
	 * @return bool Whether something was sent.
	 */
	public static function for_run( array $run, $severity, $title, array $evidence, $reminder = false ) {
		$reasons = isset( $evidence['reasons'] ) ? (array) $evidence['reasons'] : array();
		sort( $reasons );
		$key = 'run:' . $run['run_id'] . ':' . $run['state'] . ':' . substr( md5( implode( '|', $reasons ) ), 0, 12 );
		if ( ! $reminder && ! self::claim( $key, $run['run_id'], $severity ) ) {
			return false;
		}

		$site   = Plugin::gateway()->site( $run['site_id'] );
		$config = Settings::site( $run['site_id'] );
		$data   = $run['data'];
		$lines  = array(
			'Severity:   ' . $severity,
			'Site:       ' . ( $site ? $site['name'] . ' <' . $site['url'] . '>' : '#' . $run['site_id'] ),
			'Run:        ' . $run['run_id'] . ' (' . $run['state'] . ')',
			'Components: ' . implode( ', ', array_map( array( __CLASS__, 'component_label' ), $run['components'] ) ),
			'Reason:     ' . ( $reasons ? implode( ', ', $reasons ) : (string) $run['reason'] ),
			'Backup:     ' . ( ! empty( $data['backup']['restore_point']['backup_id'] ) ? 'WPTC restore point ' . $data['backup']['restore_point']['backup_id'] . ' (' . $data['backup']['restore_point']['created_at'] . ', ' . $data['backup']['restore_point']['remote'] . ')' : 'none - no update was started by the Guard' ),
			'Test:       ' . self::test_label( isset( $data['test'] ) ? $data['test'] : array() ),
			'Details:    ' . admin_url( 'tools.php?page=ms-update-guard&run=' . rawurlencode( $run['run_id'] ) ),
		);
		if ( in_array( $run['state'], array( States::FAIL, States::UNKNOWN ), true ) ) {
			$lines[] = '';
			$lines[] = 'Recovery: see docs/betrieb.md "Runbook". The site stays locked for Guard updates until the run is resolved.';
			$lines[] = 'Manual restore path: WPTC restore of the restore point above, or host/SSH/WP-CLI when the site does not boot.';
		}

		$subject = sprintf( '[%s] MS Update Guard: %s', $severity, $title );
		if ( $reminder ) {
			$subject = '[REMINDER] ' . $subject;
		}
		return self::send(
			$subject,
			implode( "\n", $lines ),
			$severity,
			$config['alert_emails'],
			array(
				'run_id'   => $run['run_id'],
				'site_id'  => $run['site_id'],
				'state'    => $run['state'],
				'severity' => $severity,
				'reasons'  => $reasons,
				'url'      => admin_url( 'tools.php?page=ms-update-guard&run=' . rawurlencode( $run['run_id'] ) ),
			)
		);
	}

	/**
	 * Alert without a run (updates outside the Guard, config drift).
	 *
	 * @param string $dedupe   Stable key for this situation.
	 * @param string $severity Severity.
	 * @param string $title    Title.
	 * @param string $text     Body.
	 * @return bool
	 */
	public static function system( $dedupe, $severity, $title, $text ) {
		if ( ! self::claim( 'sys:' . substr( $dedupe, 0, 180 ), '', $severity ) ) {
			return false;
		}
		return self::send(
			sprintf( '[%s] MS Update Guard: %s', $severity, $title ),
			$text,
			$severity,
			Settings::get( 'alert_emails' ),
			array(
				'severity' => $severity,
				'title'    => $title,
				'text'     => $text,
			)
		);
	}

	/**
	 * Re-send alerts for runs that still hold a site lock.
	 *
	 * @return int Reminders sent.
	 */
	public static function remind() {
		global $wpdb;
		$hours  = max( 1, (int) Settings::get( 'reminder_hours' ) );
		$alerts = Schema::table( 'alerts' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE resolved = 0 AND run_id <> '' AND last_sent_at < %s LIMIT 20", $alerts, Util::now( -HOUR_IN_SECONDS * $hours ) ), ARRAY_A );
		$sent = 0;
		foreach ( (array) $rows as $row ) {
			$run = Run_Repository::get( $row['run_id'] );
			if ( ! $run || null === $run['active_lock'] ) {
				self::resolve_run( $row['run_id'] );
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_sent_at = %s, send_count = send_count + 1 WHERE dedupe_key = %s', $alerts, Util::now(), $row['dedupe_key'] ) );
			$evidence = isset( $run['data']['outcome'] ) ? $run['data']['outcome'] : array();
			if ( self::for_run( $run, $row['severity'], 'unresolved since ' . $row['first_sent_at'] . ' UTC', $evidence, true ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Mark all alerts of a run resolved.
	 *
	 * @param string $run_id Run id.
	 * @return void
	 */
	public static function resolve_run( $run_id ) {
		global $wpdb;
		$wpdb->update( Schema::table( 'alerts' ), array( 'resolved' => 1 ), array( 'run_id' => $run_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Insert the dedupe row. False when this alert was already sent.
	 *
	 * @param string $key      Key.
	 * @param string $run_id   Run id.
	 * @param string $severity Severity.
	 * @return bool
	 */
	private static function claim( $key, $run_id, $severity ) {
		global $wpdb;
		$table = Schema::table( 'alerts' );
		// INSERT IGNORE keeps the first row; a duplicate reports zero affected rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$affected = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (dedupe_key, run_id, severity, first_sent_at, last_sent_at, send_count, resolved) VALUES (%s, %s, %s, %s, %s, 1, 0)', $table, $key, $run_id, $severity, Util::now(), Util::now() ) );
		return 1 === (int) $affected;
	}

	/**
	 * Deliver to all configured channels.
	 *
	 * @param string $subject  Subject.
	 * @param string $body     Plain text body.
	 * @param string $severity Severity.
	 * @param string $emails   Comma separated recipients.
	 * @param array  $payload  Webhook payload.
	 * @return bool True when at least one channel accepted the message.
	 */
	private static function send( $subject, $body, $severity, $emails, array $payload ) {
		$delivered = false;
		$emails    = array_filter( array_map( 'trim', explode( ',', (string) $emails ) ) );
		if ( $emails ) {
			$delivered = (bool) wp_mail( $emails, $subject, $body );
		}
		$webhook = Settings::get( 'alert_webhook_url' );
		if ( $webhook ) {
			$payload['subject'] = $subject;
			$payload['message'] = $body;
			$response           = Url_Policy::post( $webhook, Util::json( $payload ), array(), 10 );
			$code               = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$delivered          = $delivered || ( $code >= 200 && $code < 300 );
		}
		/**
		 * Lets other channels (SMS, chat) pick up the alert.
		 *
		 * @param array  $payload  Structured alert.
		 * @param string $subject  Subject.
		 * @param string $body     Body.
		 */
		do_action( 'msug_alert', $payload, $subject, $body );
		Journal::add( isset( $payload['run_id'] ) ? $payload['run_id'] : '', isset( $payload['site_id'] ) ? $payload['site_id'] : 0, $delivered ? 'ALERT_SENT' : 'ALERT_DELIVERY_FAILED', array( 'subject' => $subject ), array( 'severity' => $severity ) );
		return $delivered;
	}

	/**
	 * Component label.
	 *
	 * @param array $c Component.
	 * @return string
	 */
	public static function component_label( $c ) {
		$to = isset( $c['to_version'] ) ? $c['to_version'] : '?';
		return sprintf( '%s %s %s -> %s', $c['type'], $c['slug'], isset( $c['from_version'] ) ? $c['from_version'] : '?', $to );
	}

	/**
	 * Test summary.
	 *
	 * @param array $test Test data.
	 * @return string
	 */
	private static function test_label( array $test ) {
		if ( empty( $test['result'] ) ) {
			return empty( $test['status'] ) ? 'not started' : $test['status'];
		}
		$r    = $test['result'];
		$text = $r['http'] . ' / ' . $r['smoke'];
		foreach ( (array) ( $r['checks'] ?? array() ) as $check ) {
			if ( 'pass' !== ( $check['status'] ?? '' ) ) {
				$text .= sprintf( "\n            - %s: %s %s", $check['name'] ?? '?', $check['status'] ?? '?', $check['message'] ?? '' );
				if ( ! empty( $check['artifact'] ) ) {
					$text .= ' [artifact ' . $check['artifact'] . ']';
				}
			}
		}
		return $text;
	}
}
