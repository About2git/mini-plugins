<?php
/**
 * The run state machine (spec section 4).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Driven by `wp msug tick` from system cron (every minute). Each call advances every due run
 * by at most one short step and never blocks on a long poll; waiting is expressed through
 * next_check_at, so a Parent restart simply continues from the persisted state.
 */
class Engine {

	const TICK_LOCK = 'msug_tick_lock';

	/**
	 * Worker id for leases.
	 *
	 * @var string
	 */
	private $owner;

	/**
	 * Adapters.
	 *
	 * @var array
	 */
	private $a;

	/**
	 * Constructor.
	 *
	 * @param array $adapters inventory, backup, executor, verifier, runner, regression.
	 */
	public function __construct( array $adapters ) {
		$this->a     = $adapters;
		$this->owner = substr( gethostname() . ':' . getmypid() . ':' . wp_generate_password( 6, false ), 0, 64 );
	}

	/**
	 * Create a run.
	 *
	 * @param int    $site_id   Site id.
	 * @param array  $selection Either explicit components (type, slug) or ['types' => [...]] for "all pending of these types".
	 * @param string $source    manual|schedule|cli.
	 * @param array  $options   accept_preexisting_defect.
	 * @return array|\WP_Error
	 */
	public function enqueue( $site_id, array $selection, $source = 'manual', array $options = array() ) {
		$config = Settings::site( $site_id );
		if ( empty( $config['enabled'] ) ) {
			return new \WP_Error( 'msug_site_not_enabled', 'The site is not enabled for the Guard.' );
		}
		$components = array();
		$types      = array();
		if ( isset( $selection['types'] ) ) {
			$types = array_values( array_intersect( array( 'core', 'plugin', 'theme' ), (array) $selection['types'] ) );
			if ( ! $types ) {
				return new \WP_Error( 'msug_no_types', 'No update types selected.' );
			}
		} else {
			foreach ( $selection as $c ) {
				if ( ! isset( $c['type'], $c['slug'] ) || ! in_array( $c['type'], array( 'core', 'plugin', 'theme' ), true ) || '' === (string) $c['slug'] ) {
					return new \WP_Error( 'msug_bad_component', 'Components need type (core|plugin|theme) and slug.' );
				}
				$components[] = array(
					'type' => $c['type'],
					'slug' => 'core' === $c['type'] ? 'wordpress' : sanitize_text_field( $c['slug'] ),
				);
			}
			if ( ! $components ) {
				return new \WP_Error( 'msug_no_components', 'No components selected.' );
			}
		}
		return Run_Repository::create(
			$site_id,
			$components,
			$source,
			array(
				'selection' => array( 'types' => $types ),
				'preflight' => array(
					'step'                      => 'inventory',
					'accept_preexisting_defect' => ! empty( $options['accept_preexisting_defect'] ),
				),
			)
		);
	}

	/**
	 * Advance all due runs.
	 *
	 * @param int $budget Seconds this tick may use before it stops picking new runs.
	 * @return array Stats.
	 */
	public function tick( $budget = 50 ) {
		$stats = array(
			'processed' => 0,
			'errors'    => 0,
			'skipped'   => 0,
		);
		if ( ! $this->lock() ) {
			$stats['skipped'] = 1;
			return $stats;
		}
		$start = time();
		try {
			foreach ( Run_Repository::due( 20 ) as $run ) {
				if ( time() - $start > $budget ) {
					break;
				}
				$result = $this->process( $run );
				if ( is_wp_error( $result ) ) {
					++$stats['errors'];
				} else {
					++$stats['processed'];
				}
			}
			$stats['reminders'] = Alerts::remind();
			if ( get_transient( 'msug_cleanup' ) === false ) {
				Journal::cleanup( (int) Settings::get( 'retention_days' ) );
				set_transient( 'msug_cleanup', 1, DAY_IN_SECONDS );
			}
		} finally {
			$this->unlock();
		}
		$stats['active'] = Run_Repository::count_active();
		Heartbeat::ping( $stats );
		return $stats;
	}

	/**
	 * Advance one run by one step under a lease.
	 *
	 * @param array $run Run.
	 * @return array|\WP_Error|null
	 */
	public function process( array $run ) {
		$config = Settings::site( $run['site_id'] );
		$lease  = in_array( $run['state'], array( States::BACKUP_READY, States::UPDATE_RUNNING ), true ) ? 60 * ( (int) $config['update_timeout_min'] + 5 ) : 600;
		$run    = Run_Repository::claim( $run, $this->owner, $lease );
		if ( ! $run ) {
			return null;
		}
		try {
			switch ( $run['state'] ) {
				case States::QUEUED:
					$result = $this->on_queued( $run, $config );
					break;
				case States::PREFLIGHT:
					$result = $this->on_preflight( $run, $config );
					break;
				case States::BACKUP_PENDING:
					$result = $this->on_backup_pending( $run, $config );
					break;
				case States::BACKUP_READY:
					$result = $this->on_backup_ready( $run, $config );
					break;
				case States::UPDATE_RUNNING:
					$result = $this->on_update_running( $run );
					break;
				case States::VERIFYING:
					$result = $this->on_verifying( $run, $config );
					break;
				case States::TESTING:
					$result = $this->on_testing( $run, $config );
					break;
				default:
					$result = null;
			}
		} catch ( \Throwable $e ) {
			Journal::add( $run['run_id'], $run['site_id'], 'ENGINE_ERROR', array( 'error' => Util::short( get_class( $e ) . ': ' . $e->getMessage(), 300 ) ), array( 'severity' => States::SEVERITY_ERROR ) );
			$fresh  = Run_Repository::get( $run['run_id'] );
			$result = new \WP_Error( 'msug_engine_error', $e->getMessage() );
			if ( $fresh && ! States::is_terminal( $fresh['state'] ) ) {
				Run_Repository::save( $fresh, array( 'next_check_at' => Util::now( 300 ) ) );
			}
		} finally {
			Run_Repository::release( $run['run_id'], $this->owner );
		}
		return $result;
	}

	/**
	 * QUEUED: wait for window and capacity.
	 *
	 * @param array $run    Run.
	 * @param array $config Site config.
	 * @return array|\WP_Error
	 */
	private function on_queued( array $run, array $config ) {
		if ( empty( $config['enabled'] ) ) {
			return $this->block( $run, 'site_not_enabled', States::SEVERITY_INFO );
		}
		if ( ! Settings::in_window( (string) $config['window'] ) ) {
			return Run_Repository::save( $run, array( 'next_check_at' => Util::now( 300 ) ) );
		}
		if ( Run_Repository::count_active() >= (int) Settings::get( 'max_parallel' ) ) {
			return Run_Repository::save( $run, array( 'next_check_at' => Util::now( 60 ) ) );
		}
		$available = Plugin::gateway()->available();
		if ( is_wp_error( $available ) ) {
			return $this->block( $run, 'mainwp_unavailable', States::SEVERITY_WARNING, array( 'error' => $available->get_error_message() ) );
		}
		return Run_Repository::transition(
			$run,
			States::PREFLIGHT,
			array(
				'next_check_at' => Util::now(),
				'deadline_at'   => Util::now( 60 * (int) $config['backup_timeout_min'] ),
				'attempt'       => 0,
			),
			'PREFLIGHT_STARTED'
		);
	}

	/**
	 * PREFLIGHT: inventory, provider readiness, optional pre-update HTTP test.
	 *
	 * @param array $run    Run.
	 * @param array $config Site config.
	 * @return array|\WP_Error
	 */
	private function on_preflight( array $run, array $config ) {
		$data = $run['data'];
		$step = isset( $data['preflight']['step'] ) ? $data['preflight']['step'] : 'inventory';

		if ( 'inventory' === $step ) {
			$pending = $this->a['inventory']->list_pending( $run['site_id'] );
			if ( is_wp_error( $pending ) ) {
				return $this->retry_or_block( $run, 'site_unreachable', array( 'error' => Util::short( $pending->get_error_message() ) ), 3, 120 );
			}
			$resolved = $this->resolve_components( $run, $pending, $config );
			if ( is_wp_error( $resolved ) ) {
				return $this->block( $run, $resolved->get_error_code(), States::SEVERITY_INFO, (array) $resolved->get_error_data() );
			}
			if ( '' === (string) $config['profile_id'] ) {
				return $this->block( $run, 'test_profile_missing', States::SEVERITY_WARNING );
			}
			if ( '' === (string) Settings::get( 'runner_url' ) || '' === Settings::secret( 'trigger' ) || '' === Settings::secret( 'callback' ) || '' === Settings::secret( 'status' ) ) {
				return $this->block( $run, 'runner_not_configured', States::SEVERITY_WARNING );
			}
			$data['preflight']['step'] = 'provider';
			$saved                     = Run_Repository::save(
				$run,
				array(
					'components'    => $resolved,
					'data'          => $data,
					'attempt'       => 0,
					'next_check_at' => Util::now(),
				)
			);
			if ( ! is_wp_error( $saved ) ) {
				Journal::add( $run['run_id'], $run['site_id'], 'COMPONENTS_FIXED', array( 'components' => $resolved ) );
			}
			return $saved;
		}

		if ( 'provider' === $step ) {
			$pf = $this->a['backup']->preflight( $run['site_id'] );
			if ( is_wp_error( $pf ) ) {
				return $this->retry_or_block( $run, 'backup_provider_unreachable', array( 'error' => Util::short( $pf->get_error_message() ) ), 3, 120 );
			}
			if ( empty( $pf['ok'] ) ) {
				if ( ! empty( $pf['retry'] ) && ! $this->past_deadline( $run ) ) {
					return Run_Repository::save( $run, array( 'next_check_at' => Util::now( 120 ) ) );
				}
				return $this->block( $run, $pf['code'], States::SEVERITY_WARNING, array( 'message' => isset( $pf['message'] ) ? $pf['message'] : '' ) );
			}
			$data['preflight']['provider'] = $pf['details'];
			$data['preflight']['step']     = ! empty( $config['preflight_http'] ) ? 'pretest' : 'done';
			$run                           = Run_Repository::save(
				$run,
				array(
					'data'          => $data,
					'attempt'       => 0,
					'next_check_at' => Util::now(),
				)
			);
			if ( is_wp_error( $run ) ) {
				return $run;
			}
			Journal::add( $run['run_id'], $run['site_id'], 'BACKUP_PROVIDER_READY', $pf['details'] );
			if ( 'done' !== $data['preflight']['step'] ) {
				return $run;
			}
			return $this->to_backup_pending( $run, $config );
		}

		if ( 'pretest' === $step ) {
			$test = isset( $data['preflight']['pretest'] ) ? $data['preflight']['pretest'] : array();
			$test = $this->drive_test( $run, $test, 'preflight' );
			if ( is_wp_error( $test ) ) {
				return $test;
			}
			$data                         = $run['data'];
			$data['preflight']['pretest'] = $test;
			if ( empty( $test['done'] ) ) {
				return Run_Repository::save(
					$run,
					array(
						'data'          => $data,
						'next_check_at' => $test['next_check_at'],
					)
				);
			}
			$r       = isset( $test['result'] ) ? $test['result'] : array();
			$healthy = isset( $r['http'] ) && 'HTTP_PASS' === $r['http'] && ( 'SMOKE_PASS' === $r['smoke'] || ( 'SMOKE_SKIPPED' === $r['smoke'] && empty( $r['smoke_mandatory'] ) ) );
			if ( ! $healthy ) {
				if ( empty( $test['result'] ) ) {
					$run = Run_Repository::save( $run, array( 'data' => $data ) );
					return is_wp_error( $run ) ? $run : $this->block( $run, 'pretest_no_result', States::SEVERITY_WARNING );
				}
				if ( empty( $data['preflight']['accept_preexisting_defect'] ) ) {
					$run = Run_Repository::save( $run, array( 'data' => $data ) );
					return is_wp_error( $run ) ? $run : $this->block(
						$run,
						'preexisting_defect',
						States::SEVERITY_WARNING,
						array(
							'http'  => $r['http'],
							'smoke' => $r['smoke'],
						)
					);
				}
				Journal::add(
					$run['run_id'],
					$run['site_id'],
					'PREEXISTING_DEFECT_ACCEPTED',
					array(
						'http'  => $r['http'],
						'smoke' => $r['smoke'],
					),
					array( 'severity' => States::SEVERITY_WARNING )
				);
			}
			$data['preflight']['step'] = 'done';
			$run                       = Run_Repository::save( $run, array( 'data' => $data ) );
			return is_wp_error( $run ) ? $run : $this->to_backup_pending( $run, $config );
		}

		return $this->to_backup_pending( $run, $config );
	}

	/**
	 * BACKUP_PENDING: start (idempotent) and poll until the restore point is proven.
	 *
	 * @param array $run    Run.
	 * @param array $config Site config.
	 * @return array|\WP_Error
	 */
	private function on_backup_pending( array $run, array $config ) {
		$data   = $run['data'];
		$backup = isset( $data['backup'] ) ? $data['backup'] : array();
		$site   = Plugin::gateway()->site( $run['site_id'] );
		$home   = $site ? $site['url'] : '';

		if ( empty( $backup['mode'] ) ) {
			$backup['mode'] = 'new';
			$reuse          = (int) $config['reuse_backup_min'] * 60;
			if ( $reuse > 0 ) {
				$ev = $this->a['backup']->evidence( $run['site_id'], null );
				if ( ! is_wp_error( $ev ) ) {
					$eval = Backup_Evidence::evaluate(
						null,
						$ev['site'],
						$ev['probe'],
						array(
							'expected_home' => $home,
							'not_before'    => time() - $reuse,
							'max_age'       => $reuse,
						)
					);
					Journal::add(
						$run['run_id'],
						$run['site_id'],
						'BACKUP_REUSE_CHECKED',
						array(
							'code'    => $eval['code'],
							'reasons' => $eval['reasons'],
						)
					);
					if ( $eval['ok'] ) {
						$backup['mode']          = 'reuse';
						$backup['restore_point'] = $eval['restore_point'];
						$data['backup']          = $backup;
						return $this->backup_ready( $run, $data );
					}
				}
			}
		}

		if ( empty( $backup['job'] ) ) {
			$backup['start_attempts'] = isset( $backup['start_attempts'] ) ? $backup['start_attempts'] + 1 : 1;
			$backup['requested_at']   = isset( $backup['requested_at'] ) ? $backup['requested_at'] : Util::iso( time() );
			$job                      = $this->a['backup']->start( $run['site_id'], $run['run_id'] );
			if ( is_wp_error( $job ) ) {
				$data['backup'] = $backup;
				$err            = $job->get_error_data();
				$retry          = is_array( $err ) && ! empty( $err['retry'] );
				Journal::add(
					$run['run_id'],
					$run['site_id'],
					'BACKUP_START_FAILED',
					array(
						'code'    => $job->get_error_code(),
						'attempt' => $backup['start_attempts'],
					)
				);
				if ( $retry && $backup['start_attempts'] < 6 && ! $this->past_deadline( $run ) ) {
					return Run_Repository::save(
						$run,
						array(
							'data'          => $data,
							'next_check_at' => Util::now( $this->backoff( $backup['start_attempts'] ) ),
						)
					);
				}
				$run = Run_Repository::save( $run, array( 'data' => $data ) );
				return is_wp_error( $run ) ? $run : $this->block( $run, 'backup_start_failed', States::SEVERITY_WARNING, array( 'code' => $job->get_error_code() ) );
			}
			$backup['job']   = $job;
			$backup['polls'] = 0;
			$data['backup']  = $backup;
			Journal::add(
				$run['run_id'],
				$run['site_id'],
				'BACKUP_REQUESTED',
				array(
					'job_id' => $job['job_id'],
					'state'  => $job['state'],
				)
			);
			return Run_Repository::save(
				$run,
				array(
					'data'          => $data,
					'next_check_at' => Util::now( 60 ),
				)
			);
		}

		$backup['polls'] = isset( $backup['polls'] ) ? $backup['polls'] + 1 : 1;
		$ev              = $this->a['backup']->evidence( $run['site_id'], $backup['job'] );
		if ( is_wp_error( $ev ) ) {
			$eval = array(
				'ok'      => false,
				'pending' => true,
				'code'    => 'evidence_unreachable:' . $ev->get_error_code(),
				'reasons' => array(),
			);
		} else {
			$eval = Backup_Evidence::evaluate(
				$ev['op'],
				$ev['site'],
				$ev['probe'],
				array(
					'expected_home' => $home,
					'not_before'    => Util::ts( $run['created_at'] ),
					'max_age'       => 60 * (int) $config['backup_max_age_min'],
				)
			);
		}
		if ( ! isset( $backup['last_code'] ) || $backup['last_code'] !== $eval['code'] ) {
			Journal::add(
				$run['run_id'],
				$run['site_id'],
				'BACKUP_STATUS',
				array(
					'code'    => $eval['code'],
					'reasons' => $eval['reasons'],
					'poll'    => $backup['polls'],
				)
			);
		}
		$backup['last_code']  = $eval['code'];
		$backup['checked_at'] = Util::iso( time() );
		$data['backup']       = $backup;

		if ( $eval['ok'] ) {
			$data['backup']['restore_point'] = $eval['restore_point'];
			return $this->backup_ready( $run, $data );
		}
		if ( $eval['pending'] && ! $this->past_deadline( $run ) ) {
			return Run_Repository::save(
				$run,
				array(
					'data'          => $data,
					'next_check_at' => Util::now( $this->backoff( $backup['polls'] ) ),
				)
			);
		}
		$run = Run_Repository::save( $run, array( 'data' => $data ) );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		return $this->block( $run, $eval['pending'] ? 'backup_timeout' : $eval['code'], States::SEVERITY_WARNING, $eval['reasons'] );
	}

	/**
	 * BACKUP_READY: persist UPDATE_STARTED first, then call MainWP exactly once.
	 *
	 * @param array $run    Run.
	 * @param array $config Site config.
	 * @return array|\WP_Error
	 */
	private function on_backup_ready( array $run, array $config ) {
		$data = $run['data'];
		$rp   = isset( $data['backup']['restore_point'] ) ? $data['backup']['restore_point'] : array();
		if ( empty( $rp['backup_id'] ) ) {
			return $this->block( $run, 'restore_point_missing', States::SEVERITY_WARNING );
		}
		if ( time() - (int) $rp['backup_id'] > 60 * (int) $config['backup_max_age_min'] + Backup_Evidence::CROSS_CLOCK_TOLERANCE ) {
			return $this->block( $run, 'backup_expired_before_update', States::SEVERITY_WARNING, array( 'backup_id' => $rp['backup_id'] ) );
		}

		$data['update'] = array( 'started_at' => Util::iso( time() ) );
		$run            = Run_Repository::transition(
			$run,
			States::UPDATE_RUNNING,
			array(
				'data'          => $data,
				'deadline_at'   => Util::now( 60 * (int) $config['update_timeout_min'] ),
				'next_check_at' => Util::now( 60 * (int) $config['update_timeout_min'] ),
			),
			'UPDATE_STARTED',
			array(
				'components' => $run['components'],
				'backup_id'  => $rp['backup_id'],
			)
		);
		if ( is_wp_error( $run ) ) {
			return $run;
		}

		$result = $this->a['executor']->start( $run['site_id'], $run['components'], $run['run_id'] );

		$fresh = Run_Repository::get( $run['run_id'] );
		if ( ! $fresh || States::UPDATE_RUNNING !== $fresh['state'] ) {
			return new \WP_Error( 'msug_state_moved', 'Run left UPDATE_RUNNING while the update was executing.' );
		}
		$data                          = $fresh['data'];
		$data['update']['result']      = $result;
		$data['update']['finished_at'] = Util::iso( time() );
		return Run_Repository::transition(
			$fresh,
			States::VERIFYING,
			array(
				'data'          => $data,
				'next_check_at' => Util::now( (int) $config['settle_seconds'] ),
				'deadline_at'   => null,
			),
			'UPDATE_RESULT',
			array(
				'status'     => $result['status'],
				'updated'    => $result['updated'],
				'errors'     => $result['errors'],
				'site_error' => $result['site_error'],
			)
		);
	}

	/**
	 * UPDATE_RUNNING found by a tick: the worker that ran the update died or hung.
	 * No blind retry - the result is recorded as unknown and verification takes over.
	 *
	 * @param array $run Run.
	 * @return array|\WP_Error|null
	 */
	private function on_update_running( array $run ) {
		if ( ! $this->past_deadline( $run ) ) {
			return Run_Repository::save( $run, array( 'next_check_at' => $run['deadline_at'] ) );
		}
		$data                     = $run['data'];
		$data['update']['result'] = array(
			'status'     => 'no_result',
			'updated'    => array(),
			'errors'     => array(),
			'site_error' => '',
		);
		return Run_Repository::transition(
			$run,
			States::VERIFYING,
			array(
				'data'          => $data,
				'next_check_at' => Util::now(),
			),
			'UPDATE_NO_RESULT',
			array( 'note' => 'Update call did not report back before the update timeout.' )
		);
	}

	/**
	 * VERIFYING: independent resync, then hand over to the external test.
	 *
	 * @param array $run    Run.
	 * @param array $config Site config.
	 * @return array|\WP_Error
	 */
	private function on_verifying( array $run, array $config ) {
		$data               = $run['data'];
		$data['verify']     = $this->a['verifier']->resync_and_compare( $run['site_id'], $run['components'] );
		$data['regression'] = $this->a['regression']->for_run( $run );
		$data['test']       = array();
		return Run_Repository::transition(
			$run,
			States::TESTING,
			array(
				'data'          => $data,
				'next_check_at' => Util::now(),
				'deadline_at'   => Util::now( 60 * (int) $config['test_timeout_min'] ),
			),
			'VERSIONS_VERIFIED',
			$data['verify']
		);
	}

	/**
	 * TESTING: trigger, wait for callback, poll once after the deadline, then decide.
	 *
	 * @param array $run    Run.
	 * @param array $config Site config.
	 * @return array|\WP_Error
	 */
	private function on_testing( array $run, array $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Uniform handler signature.
		$test = $this->drive_test( $run, isset( $run['data']['test'] ) ? $run['data']['test'] : array(), 'update_finished_or_timeout' );
		if ( is_wp_error( $test ) ) {
			return $test;
		}
		$data         = $run['data'];
		$data['test'] = $test;
		if ( empty( $test['done'] ) ) {
			return Run_Repository::save(
				$run,
				array(
					'data'          => $data,
					'next_check_at' => $test['next_check_at'],
				)
			);
		}
		return $this->finish( $run, $data );
	}

	/**
	 * Shared test driver for pre- and post-update tests.
	 *
	 * @param array  $run     Run; replaced by the saved version when the test id is persisted.
	 * @param array  $test    Test state.
	 * @param string $trigger Trigger.
	 * @return array|\WP_Error Test state with done flag and next_check_at.
	 */
	private function drive_test( array &$run, array $test, $trigger ) {
		if ( ! empty( $test['result'] ) ) {
			$test['done'] = true;
			return $test;
		}
		if ( empty( $test['test_id'] ) ) {
			// Persist the id before the trigger so an early callback always finds it.
			$test['test_id']  = Util::uuid();
			$test['attempts'] = 0;
			$test['status']   = 'pending';
			$test['deadline'] = time() + 60 * (int) Settings::site( $run['site_id'] )['test_timeout_min'];
			$data             = $run['data'];
			if ( 'preflight' === $trigger ) {
				$data['preflight']['pretest'] = $test;
			} else {
				$data['test'] = $test;
			}
			$saved = Run_Repository::save( $run, array( 'data' => $data ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$run = $saved;
		}
		if ( 'pending' === $test['status'] ) {
			$test['attempts'] = (int) $test['attempts'] + 1;
			$started          = $this->a['runner']->start( $run, $test['test_id'], $trigger );
			if ( ! is_wp_error( $started ) && ! empty( $started['accepted'] ) ) {
				$test['status']       = 'triggered';
				$test['triggered_at'] = Util::iso( time() );
				Journal::add(
					$run['run_id'],
					$run['site_id'],
					'TEST_TRIGGERED',
					array(
						'test_id'   => $test['test_id'],
						'trigger'   => $trigger,
						'http_code' => $started['http_code'],
					)
				);
			} else {
				$error = is_wp_error( $started ) ? $started->get_error_code() : 'http_' . $started['http_code'];
				Journal::add(
					$run['run_id'],
					$run['site_id'],
					'TEST_TRIGGER_FAILED',
					array(
						'test_id' => $test['test_id'],
						'error'   => $error,
						'attempt' => $test['attempts'],
					),
					array( 'severity' => States::SEVERITY_WARNING )
				);
				if ( $test['attempts'] >= 3 ) {
					$test['status'] = 'trigger_failed';
					$test['done']   = true;
					return $test;
				}
				$test['next_check_at'] = Util::now( 30 * $test['attempts'] );
				return $test;
			}
		}
		if ( time() < (int) $test['deadline'] ) {
			$test['next_check_at'] = Util::now( min( 60, max( 5, (int) $test['deadline'] - time() ) ) );
			return $test;
		}
		// Deadline passed without callback: ask the runner once more (spec 5).
		$status = $this->a['runner']->status( $run, $test['test_id'] );
		if ( ! is_wp_error( $status ) && empty( $status['pending'] ) ) {
			$test['result'] = $status['result'];
			$test['status'] = 'result';
			$test['source'] = 'status_poll';
			Journal::add(
				$run['run_id'],
				$run['site_id'],
				'TEST_RESULT',
				array(
					'source' => 'status_poll',
					'http'   => $status['result']['http'],
					'smoke'  => $status['result']['smoke'],
				)
			);
		} else {
			$test['status'] = 'no_result';
			Journal::add(
				$run['run_id'],
				$run['site_id'],
				'TEST_NO_RESULT',
				array(
					'test_id'      => $test['test_id'],
					'status_error' => is_wp_error( $status ) ? $status->get_error_code() : 'pending',
				),
				array( 'severity' => States::SEVERITY_CRITICAL )
			);
		}
		$test['done'] = true;
		return $test;
	}

	/**
	 * Decide the terminal state and alert.
	 *
	 * @param array $run  Run.
	 * @param array $data Data including test.
	 * @return array|\WP_Error
	 */
	private function finish( array $run, array $data ) {
		$test = $data['test'];
		$eval = array(
			'status' => ! empty( $test['result'] ) ? 'result' : ( isset( $test['status'] ) ? $test['status'] : 'no_result' ),
		);
		if ( ! empty( $test['result'] ) ) {
			$eval['http']            = $test['result']['http'];
			$eval['smoke']           = $test['result']['smoke'];
			$eval['smoke_mandatory'] = $test['result']['smoke_mandatory'];
		}
		$outcome         = Outcome::evaluate(
			$run['components'],
			isset( $data['update']['result'] ) ? $data['update']['result'] : array(),
			isset( $data['verify'] ) ? $data['verify'] : array(),
			$eval
		);
		$data['outcome'] = $outcome;
		$run             = Run_Repository::transition(
			$run,
			$outcome['state'],
			array(
				'data'     => $data,
				'severity' => $outcome['severity'],
				'reason'   => substr( implode( ',', $outcome['reasons'] ), 0, 191 ),
			),
			'RUN_FINISHED',
			$outcome
		);
		if ( ! is_wp_error( $run ) && States::PASS !== $outcome['state'] ) {
			Alerts::for_run( $run, $outcome['severity'], sprintf( '%s after update', $outcome['state'] ), $outcome );
		}
		return $run;
	}

	/**
	 * Enter BACKUP_PENDING.
	 *
	 * @param array $run    Run.
	 * @param array $config Config.
	 * @return array|\WP_Error
	 */
	private function to_backup_pending( array $run, array $config ) {
		return Run_Repository::transition(
			$run,
			States::BACKUP_PENDING,
			array(
				'next_check_at' => Util::now(),
				'deadline_at'   => Util::now( 60 * (int) $config['backup_timeout_min'] ),
				'attempt'       => 0,
			),
			'PREFLIGHT_PASSED',
			array( 'components' => $run['components'] )
		);
	}

	/**
	 * Enter BACKUP_READY with the proof stored.
	 *
	 * @param array $run  Run.
	 * @param array $data Data with backup.restore_point.
	 * @return array|\WP_Error
	 */
	private function backup_ready( array $run, array $data ) {
		return Run_Repository::transition(
			$run,
			States::BACKUP_READY,
			array(
				'data'          => $data,
				'next_check_at' => Util::now(),
				'deadline_at'   => null,
			),
			'BACKUP_READY',
			array(
				'mode'          => $data['backup']['mode'],
				'restore_point' => $data['backup']['restore_point'],
			)
		);
	}

	/**
	 * Map inventory to the explicit component list of this run.
	 *
	 * @param array $run     Run.
	 * @param array $pending Pending updates.
	 * @param array $config  Site config.
	 * @return array|\WP_Error
	 */
	private function resolve_components( array $run, array $pending, array $config ) {
		$by_key = array();
		foreach ( $pending as $p ) {
			$by_key[ $p['type'] . ':' . $p['slug'] ] = $p;
		}
		$wanted = $run['components'];
		if ( ! $wanted && ! empty( $run['data']['selection']['types'] ) ) {
			$exclude = isset( $config['exclude_components'] ) ? (array) $config['exclude_components'] : array();
			foreach ( $pending as $p ) {
				if ( in_array( $p['type'], $run['data']['selection']['types'], true ) && ! in_array( $p['slug'], $exclude, true ) ) {
					$wanted[] = array(
						'type' => $p['type'],
						'slug' => $p['slug'],
					);
				}
			}
			if ( ! $wanted ) {
				return new \WP_Error( 'nothing_to_update', 'No pending updates for the selected types.' );
			}
		}
		$out     = array();
		$missing = array();
		foreach ( $wanted as $c ) {
			$key = $c['type'] . ':' . $c['slug'];
			if ( ! isset( $by_key[ $key ] ) ) {
				$missing[] = $key;
				continue;
			}
			$p     = $by_key[ $key ];
			$out[] = array(
				'type'         => $p['type'],
				'slug'         => $p['slug'],
				'name'         => Util::short( $p['name'], 120 ),
				'from_version' => $p['current_version'],
				'to_version'   => $p['new_version'],
				'critical'     => in_array( $p['slug'], (array) $config['critical_components'], true ),
			);
		}
		if ( $missing ) {
			return new \WP_Error( 'update_not_available', 'Requested updates are not pending on the site.', array( 'missing' => $missing ) );
		}
		return $out;
	}

	/**
	 * Retry a step a few times, then block.
	 *
	 * @param array  $run     Run.
	 * @param string $code    Block reason.
	 * @param array  $details Details.
	 * @param int    $max     Max attempts.
	 * @param int    $delay   Seconds between attempts.
	 * @return array|\WP_Error
	 */
	private function retry_or_block( array $run, $code, array $details, $max, $delay ) {
		$attempt = $run['attempt'] + 1;
		Journal::add(
			$run['run_id'],
			$run['site_id'],
			'STEP_RETRY',
			array_merge(
				$details,
				array(
					'code'    => $code,
					'attempt' => $attempt,
				)
			)
		);
		if ( $attempt < $max && ! $this->past_deadline( $run ) ) {
			return Run_Repository::save(
				$run,
				array(
					'attempt'       => $attempt,
					'next_check_at' => Util::now( $delay ),
				)
			);
		}
		return $this->block( $run, $code, States::SEVERITY_WARNING, $details );
	}

	/**
	 * Terminal BLOCKED: no update was started by this run.
	 *
	 * @param array  $run      Run.
	 * @param string $code     Reason.
	 * @param string $severity Severity.
	 * @param array  $details  Details.
	 * @return array|\WP_Error
	 */
	private function block( array $run, $code, $severity, array $details = array() ) {
		$fresh = Run_Repository::transition(
			$run,
			States::BLOCKED,
			array(
				'reason'   => substr( $code, 0, 191 ),
				'severity' => $severity,
			),
			'RUN_BLOCKED',
			array(
				'reason'  => $code,
				'details' => $details,
			)
		);
		if ( ! is_wp_error( $fresh ) && States::SEVERITY_INFO !== $severity ) {
			Alerts::for_run(
				$fresh,
				$severity,
				'blocked before update: ' . $code,
				array( 'reasons' => array( $code ) )
			);
		}
		return $fresh;
	}

	/**
	 * Whether the state deadline passed.
	 *
	 * @param array $run Run.
	 * @return bool
	 */
	private function past_deadline( array $run ) {
		return ! empty( $run['deadline_at'] ) && Util::ts( $run['deadline_at'] ) <= time();
	}

	/**
	 * Bounded exponential backoff: 60, 120, 240, 480, 600, 600 ...
	 *
	 * @param int $n Attempt.
	 * @return int Seconds.
	 */
	private function backoff( $n ) {
		return (int) min( 600, 60 * pow( 2, max( 0, (int) $n - 1 ) ) );
	}

	/**
	 * Non-blocking tick lock (a second cron invocation exits immediately).
	 *
	 * @return bool
	 */
	private function lock() {
		$until = time() + 300;
		if ( add_option( self::TICK_LOCK, $until, '', false ) ) {
			return true;
		}
		$current = (int) get_option( self::TICK_LOCK );
		if ( $current < time() ) {
			// Stale lock of a crashed tick: take it over with compare-and-set.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic takeover of a stale lock.
			$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $until, self::TICK_LOCK, (string) $current ) );
			wp_cache_delete( self::TICK_LOCK, 'options' );
			return 1 === (int) $taken;
		}
		return false;
	}

	/**
	 * Release the tick lock.
	 *
	 * @return void
	 */
	private function unlock() {
		delete_option( self::TICK_LOCK );
	}
}
