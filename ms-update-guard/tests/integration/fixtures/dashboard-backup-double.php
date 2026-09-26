<?php
/**
 * Test fixture for the DASHBOARD of the local end-to-end test. Never install in production.
 *
 * WP Time Capsule cannot back up without a WPTC cloud account, so the local test replaces only the
 * backup provider with a double that returns the raw evidence shapes of the real protocol
 * (abilities_v2 "operation_status"/"site" and the evidence probe). The decision on that evidence is
 * made by the real Backup_Evidence class. Scenario: option msug_double_backup.
 * Mails are captured into the option msug_test_mails.
 *
 * @package MSUpdateGuard
 */

add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$mails   = get_option( 'msug_test_mails', array() );
		$mails[] = array( 'subject' => $atts['subject'], 'message' => $atts['message'] );
		update_option( 'msug_test_mails', $mails, false );
		return true;
	},
	10,
	2
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! interface_exists( 'MSUpdateGuard\\Adapters\\Backup_Provider' ) ) {
			return;
		}

		class MSUG_Backup_Double implements MSUpdateGuard\Adapters\Backup_Provider {
			public $calls = array();

			private function scenario() {
				wp_cache_delete( 'msug_double_backup', 'options' );
				return (string) get_option( 'msug_double_backup', 'ok' );
			}

			private function real() {
				return new MSUpdateGuard\Adapters\WPTC_Backup_Provider( MSUpdateGuard\Plugin::gateway() );
			}

			public function preflight( $site_id ) {
				if ( 'real' === $this->scenario() ) {
					return $this->real()->preflight( $site_id );
				}
				update_option( 'msug_double_calls', array_merge( (array) get_option( 'msug_double_calls', array() ), array( 'preflight' ) ), false );
				return array(
					'ok'      => true,
					'code'    => 'ready',
					'details' => array( 'double' => true ),
				);
			}

			public function start( $site_id, $run_id ) {
				if ( 'real' === $this->scenario() ) {
					return $this->real()->start( $site_id, $run_id );
				}
				update_option( 'msug_double_calls', array_merge( (array) get_option( 'msug_double_calls', array() ), array( 'start:' . $run_id ) ), false );
				update_option( 'msug_double_started', time(), false );
				return array(
					'job_id'      => hash( 'sha256', $run_id ),
					'state'       => 'running',
					'request_ref' => $run_id,
				);
			}

			public function evidence( $site_id, $job ) {
				if ( 'real' === $this->scenario() ) {
					return $this->real()->evidence( $site_id, $job );
				}
				$started = (int) get_option( 'msug_double_started', time() );
				$bid     = $started + 2;
				$site    = MSUpdateGuard\Plugin::gateway()->site( $site_id );
				$s       = $this->scenario();
				$op      = array(
					'state'      => 'running' === $s ? 'running' : ( 'failed' === $s ? 'failed' : 'uncertain' ),
					'started_at' => gmdate( 'Y-m-d\TH:i:s\Z', $started ),
				);
				$obs     = array(
					'ok'                     => true,
					'plugin_state'           => 'ready',
					'account_state'          => 'connected',
					'active_operation_count' => 'running' === $s ? 1 : 0,
					'last_attempt_at'        => gmdate( 'Y-m-d\TH:i:s\Z', $bid ),
				);
				$probe   = array(
					'ok'                    => true,
					'probe_version'         => 1,
					'home_url'              => 'other_site' === $s ? 'https://fremde-site.example/' : $site['url'],
					'backup_id'             => $bid,
					'in_progress'           => false,
					'meta_row'              => 'db_only' !== $s,
					'files_count'           => 'db_only' === $s ? 0 : 120,
					'backup_name'           => 'double',
					'db_dump'               => array( 'present' => true, 'complete' => true ),
					'incomplete_uploads'    => 0,
					'success_complete_time' => $bid + 30,
					'error_count'           => 0,
					'cloud'                 => array( 'repo' => 's3', 'connected' => true ),
				);
				return array(
					'op'    => $op,
					'site'  => $obs,
					'probe' => 'no_probe' === $s ? null : $probe,
				);
			}
		}

		add_filter(
			'msug_adapters',
			function ( $adapters ) {
				$adapters['backup'] = new MSUG_Backup_Double();
				return $adapters;
			}
		);
	},
	5
);
