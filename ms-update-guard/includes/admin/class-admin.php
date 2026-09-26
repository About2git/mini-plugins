<?php
/**
 * Admin screens (Tools > MS Update Guard).
 *
 * @package MSUpdateGuard
 */

namespace MSUpdateGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Runs, run journal, site configuration, settings and health. Every write goes through
 * admin-post.php with capability check and nonce. No front-end assets are loaded.
 */
class Admin {

	const SLUG = 'ms-update-guard';

	/**
	 * Capability for an action.
	 *
	 * @param string $action view|manage.
	 * @return string
	 */
	public static function cap( $action ) {
		/**
		 * Capability used by the Guard screens.
		 *
		 * @param string $cap    Capability.
		 * @param string $action view|manage.
		 */
		return (string) apply_filters( 'msug_capability', 'manage_options', $action );
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		foreach ( array( 'enqueue', 'resolve', 'save_settings', 'save_site', 'save_secret' ) as $action ) {
			add_action( 'admin_post_msug_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Menu entry.
	 *
	 * @return void
	 */
	public static function menu() {
		add_management_page( 'MS Update Guard', 'MS Update Guard', self::cap( 'view' ), self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Page router.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::cap( 'view' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'ms-update-guard' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'runs';
		$run_id = isset( $_GET['run'] ) ? sanitize_text_field( wp_unslash( $_GET['run'] ) ) : '';
		$notice = isset( $_GET['msug_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['msug_notice'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap"><h1>MS Update Guard</h1>';
		if ( '' !== $notice ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}
		$tabs = array(
			'runs'     => __( 'Runs', 'ms-update-guard' ),
			'outside'  => __( 'Outside the Guard', 'ms-update-guard' ),
			'sites'    => __( 'Sites', 'ms-update-guard' ),
			'settings' => __( 'Settings', 'ms-update-guard' ),
			'health'   => __( 'Health', 'ms-update-guard' ),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a class="nav-tab%s" href="%s">%s</a>', $key === $tab && '' === $run_id ? ' nav-tab-active' : '', esc_url( self::url( array( 'tab' => $key ) ) ), esc_html( $label ) );
		}
		echo '</nav>';

		if ( '' !== $run_id ) {
			self::render_run( $run_id );
		} elseif ( 'outside' === $tab ) {
			self::render_outside();
		} elseif ( 'sites' === $tab ) {
			self::render_sites();
		} elseif ( 'settings' === $tab ) {
			self::render_settings();
		} elseif ( 'health' === $tab ) {
			self::render_health();
		} else {
			self::render_runs();
		}
		echo '</div>';
	}

	/**
	 * Run list.
	 *
	 * @return void
	 */
	private static function render_runs() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$open = ! empty( $_GET['open'] );
		$runs = Run_Repository::query(
			array(
				'open'  => $open,
				'limit' => 100,
			)
		);
		printf(
			'<p><a href="%s">%s</a> | <a href="%s">%s</a></p>',
			esc_url( self::url( array( 'tab' => 'runs' ) ) ),
			esc_html__( 'All', 'ms-update-guard' ),
			esc_url(
				self::url(
					array(
						'tab'  => 'runs',
						'open' => 1,
					)
				)
			),
			esc_html__( 'Holding a site lock', 'ms-update-guard' )
		);
		echo '<table class="widefat striped"><thead><tr><th>Run</th><th>Site</th><th>State</th><th>Severity</th><th>Reason</th><th>Components</th><th>Restore point</th><th>Test</th><th>Regression</th><th>Created (UTC)</th></tr></thead><tbody>';
		if ( ! $runs ) {
			echo '<tr><td colspan="10">' . esc_html__( 'No runs yet.', 'ms-update-guard' ) . '</td></tr>';
		}
		foreach ( $runs as $run ) {
			$site = Plugin::gateway()->site( $run['site_id'] );
			$rp   = isset( $run['data']['backup']['restore_point']['backup_id'] ) ? $run['data']['backup']['restore_point']['created_at'] : '-';
			$test = isset( $run['data']['test']['result'] ) ? $run['data']['test']['result']['http'] . ' / ' . $run['data']['test']['result']['smoke'] : ( isset( $run['data']['test']['status'] ) ? $run['data']['test']['status'] : '-' );
			$reg  = isset( $run['data']['regression']['state'] ) ? $run['data']['regression']['state'] : '-';
			printf(
				'<tr><td><a href="%s"><code>%s</code></a>%s</td><td>%s</td><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( self::url( array( 'run' => $run['run_id'] ) ) ),
				esc_html( substr( $run['run_id'], 0, 8 ) ),
				null !== $run['active_lock'] ? ' &#128274;' : '',
				esc_html( $site ? $site['name'] : '#' . $run['site_id'] ),
				esc_html( $run['state'] ),
				esc_html( (string) $run['severity'] ),
				esc_html( (string) $run['reason'] ),
				esc_html( implode( ', ', array_map( array( Alerts::class, 'component_label' ), $run['components'] ) ) ),
				esc_html( $rp ),
				esc_html( $test ),
				esc_html( 'not_connected' === $reg ? __( 'not connected', 'ms-update-guard' ) : $reg ),
				esc_html( $run['created_at'] )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * One run with its journal.
	 *
	 * @param string $run_id Run id.
	 * @return void
	 */
	private static function render_run( $run_id ) {
		$run = Run_Repository::get( $run_id );
		if ( ! $run ) {
			echo '<p>' . esc_html__( 'Run not found.', 'ms-update-guard' ) . '</p>';
			return;
		}
		$site = Plugin::gateway()->site( $run['site_id'] );
		printf( '<h2>Run <code>%s</code></h2>', esc_html( $run['run_id'] ) );
		echo '<table class="form-table" role="presentation">';
		$rows = array(
			'Site'       => $site ? $site['name'] . ' (' . $site['url'] . ')' : '#' . $run['site_id'],
			'State'      => $run['state'] . ( $run['severity'] ? ' / ' . $run['severity'] : '' ),
			'Reason'     => (string) $run['reason'],
			'Site lock'  => null === $run['active_lock'] ? 'released' : 'held - resolve after review',
			'Components' => implode( "\n", array_map( array( Alerts::class, 'component_label' ), $run['components'] ) ),
			'Source'     => $run['source'],
			'Created'    => $run['created_at'] . ' UTC',
			'Finished'   => (string) $run['finished_at'],
			'Regression' => isset( $run['data']['regression']['state'] ) ? $run['data']['regression']['state'] : '-',
		);
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row">%s</th><td><pre style="margin:0;white-space:pre-wrap">%s</pre></td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</table>';

		echo '<h3>' . esc_html__( 'Evidence', 'ms-update-guard' ) . '</h3>';
		echo '<pre style="max-height:24em;overflow:auto;background:#fff;padding:1em;border:1px solid #c3c4c7">' . esc_html( wp_json_encode( $run['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';

		echo '<h3>' . esc_html__( 'Journal', 'ms-update-guard' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Event</th><th>From</th><th>To</th><th>Severity</th><th>Actor</th><th>Data</th></tr></thead><tbody>';
		foreach ( Journal::for_run( $run['run_id'] ) as $e ) {
			printf(
				'<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><code style="white-space:pre-wrap;word-break:break-all">%s</code></td></tr>',
				esc_html( $e['created_at'] ),
				esc_html( $e['event'] ),
				esc_html( (string) $e['state_from'] ),
				esc_html( (string) $e['state_to'] ),
				esc_html( (string) $e['severity'] ),
				esc_html( $e['actor'] ),
				esc_html( $e['data'] )
			);
		}
		echo '</tbody></table>';

		if ( null !== $run['active_lock'] && States::is_terminal( $run['state'] ) && current_user_can( self::cap( 'manage' ) ) ) {
			echo '<h3>' . esc_html__( 'Resolve', 'ms-update-guard' ) . '</h3>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'msug_resolve' );
			echo '<input type="hidden" name="action" value="msug_resolve"><input type="hidden" name="run_id" value="' . esc_attr( $run['run_id'] ) . '">';
			echo '<p><label for="msug-note">' . esc_html__( 'What was checked or restored?', 'ms-update-guard' ) . '</label><br><textarea id="msug-note" name="note" rows="3" cols="80" required></textarea></p>';
			submit_button( __( 'Mark resolved and release the site lock', 'ms-update-guard' ), 'primary', 'submit', false );
			echo '</form>';
		}
	}

	/**
	 * Updates noticed outside the Guard.
	 *
	 * @return void
	 */
	private static function render_outside() {
		echo '<p>' . esc_html__( 'These updates ran without the backup gate. They are not covered by a verified restore point.', 'ms-update-guard' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Site</th><th>Event</th><th>Data</th></tr></thead><tbody>';
		foreach ( Journal::site_events( 100 ) as $e ) {
			printf( '<tr><td>%s</td><td>%d</td><td><code>%s</code></td><td><code style="white-space:pre-wrap;word-break:break-all">%s</code></td></tr>', esc_html( $e['created_at'] ), (int) $e['site_id'], esc_html( $e['event'] ), esc_html( $e['data'] ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Site configuration and manual enqueue.
	 *
	 * @return void
	 */
	private static function render_sites() {
		$sites  = Plugin::gateway()->all_sites();
		$manage = current_user_can( self::cap( 'manage' ) );
		echo '<p>' . esc_html__( 'Global settings are defaults; values set here override them for one site.', 'ms-update-guard' ) . '</p>';
		foreach ( $sites as $site ) {
			$id     = (int) $site['id'];
			$config = Settings::site( $id );
			$own    = Settings::sites();
			$own    = isset( $own[ $id ] ) ? $own[ $id ] : array();
			$active = Run_Repository::active_for_site( $id );
			echo '<details style="background:#fff;border:1px solid #c3c4c7;margin:0 0 8px;padding:8px 12px">';
			printf( '<summary><strong>%s</strong> <code>#%d</code> %s %s</summary>', esc_html( $site['name'] ), (int) $id, $config['enabled'] ? '&#9989;' : '&#11036;', $active ? '<a href="' . esc_url( self::url( array( 'run' => $active['run_id'] ) ) ) . '">' . esc_html( $active['state'] ) . '</a>' : '' );
			if ( ! $manage ) {
				echo '</details>';
				continue;
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'msug_save_site' );
			echo '<input type="hidden" name="action" value="msug_save_site"><input type="hidden" name="site_id" value="' . (int) $id . '">';
			echo '<table class="form-table" role="presentation">';
			self::checkbox_row( 'enabled', __( 'Enabled for the Guard', 'ms-update-guard' ), ! empty( $own['enabled'] ) );
			self::text_row( 'profile_id', __( 'Runner profile id', 'ms-update-guard' ), isset( $own['profile_id'] ) ? $own['profile_id'] : '', 'corporate-basic' );
			echo '<tr><th scope="row">' . esc_html__( 'Scheduled types', 'ms-update-guard' ) . '</th><td>';
			foreach ( array( 'plugin', 'theme', 'core' ) as $type ) {
				printf( '<label style="margin-right:1em"><input type="checkbox" name="auto_types[]" value="%1$s" %2$s> %1$s</label>', esc_attr( $type ), checked( in_array( $type, (array) $config['auto_types'], true ), true, false ) );
			}
			echo '</td></tr>';
			self::text_row( 'exclude_components', __( 'Never schedule (slugs)', 'ms-update-guard' ), implode( ', ', (array) $config['exclude_components'] ), '' );
			self::text_row( 'critical_components', __( 'Critical components (slugs)', 'ms-update-guard' ), implode( ', ', (array) $config['critical_components'] ), '' );
			foreach ( array(
				'window'             => 'HH:MM-HH:MM',
				'backup_timeout_min' => '',
				'update_timeout_min' => '',
				'test_timeout_min'   => '',
				'settle_seconds'     => '',
				'backup_max_age_min' => '',
				'reuse_backup_min'   => '',
				'alert_emails'       => '',
			) as $key => $placeholder ) {
				self::text_row( $key, $key, isset( $own[ $key ] ) ? $own[ $key ] : '', '' !== $placeholder ? $placeholder : (string) Settings::get( $key ) );
			}
			echo '</table>';
			submit_button( __( 'Save site', 'ms-update-guard' ), 'secondary', 'submit', false );
			echo '</form>';

			if ( $config['enabled'] && ! $active ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
				wp_nonce_field( 'msug_enqueue' );
				echo '<input type="hidden" name="action" value="msug_enqueue"><input type="hidden" name="site_id" value="' . (int) $id . '">';
				echo '<label>' . esc_html__( 'Plugins (slugs, comma separated)', 'ms-update-guard' ) . ' <input type="text" name="plugins" class="regular-text"></label> ';
				echo '<label>' . esc_html__( 'Themes', 'ms-update-guard' ) . ' <input type="text" name="themes"></label> ';
				echo '<label><input type="checkbox" name="core" value="1"> core</label> ';
				echo '<label><input type="checkbox" name="accept_preexisting_defect" value="1"> ' . esc_html__( 'accept pre-existing defect', 'ms-update-guard' ) . '</label> ';
				submit_button( __( 'Queue guarded update', 'ms-update-guard' ), 'primary', 'submit', false );
				echo '</form>';
			}
			echo '</details>';
		}
		if ( ! $sites ) {
			echo '<p>' . esc_html__( 'No MainWP sites found (is MainWP 6.2+ active?).', 'ms-update-guard' ) . '</p>';
		}
	}

	/**
	 * Global settings and secrets.
	 *
	 * @return void
	 */
	private static function render_settings() {
		if ( ! current_user_can( self::cap( 'manage' ) ) ) {
			return;
		}
		$s = Settings::all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'msug_save_settings' );
		echo '<input type="hidden" name="action" value="msug_save_settings"><table class="form-table" role="presentation">';
		self::text_row( 'service_user_id', __( 'Service user id (administrator used by cron/CLI)', 'ms-update-guard' ), $s['service_user_id'], '' );
		self::text_row( 'runner_url', __( 'Runner base URL (https)', 'ms-update-guard' ), $s['runner_url'], 'https://runner.example.net' );
		self::text_row( 'key_id', __( 'Current key id', 'ms-update-guard' ), $s['key_id'], 'k1' );
		self::text_row( 'allowed_hosts', __( 'Allowed outbound hosts', 'ms-update-guard' ), implode( ', ', $s['allowed_hosts'] ), 'runner.example.net, uptime.betterstack.com' );
		self::text_row( 'alert_emails', __( 'Alert e-mails', 'ms-update-guard' ), $s['alert_emails'], '' );
		self::text_row( 'alert_webhook_url', __( 'Alert webhook URL', 'ms-update-guard' ), $s['alert_webhook_url'], '' );
		self::text_row( 'heartbeat_url', __( 'Heartbeat URL (external monitor)', 'ms-update-guard' ), $s['heartbeat_url'], '' );
		self::checkbox_row( 'enforce_native_updates_off', __( 'Force MainWP native automatic updates off', 'ms-update-guard' ), $s['enforce_native_updates_off'] );
		self::checkbox_row( 'block_unguarded_abilities', __( 'Refuse MainWP bulk update abilities outside the Guard', 'ms-update-guard' ), $s['block_unguarded_abilities'] );
		self::checkbox_row( 'require_wptc_bbu_off', __( 'Require WPTC "backup before update" to be off', 'ms-update-guard' ), $s['require_wptc_bbu_off'] );
		self::checkbox_row( 'preflight_http', __( 'Pre-update HTTP/smoke test (default)', 'ms-update-guard' ), $s['preflight_http'] );
		foreach ( array( 'max_parallel', 'reminder_hours', 'retention_days', 'backup_timeout_min', 'update_timeout_min', 'test_timeout_min', 'settle_seconds', 'backup_max_age_min', 'reuse_backup_min', 'window' ) as $key ) {
			self::text_row( $key, $key, $s[ $key ], '' );
		}
		echo '</table>';
		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html__( 'Secrets', 'ms-update-guard' ) . '</h2><p>' . esc_html__( 'Prefer constants in wp-config.php. Values are write-only here; at least 32 characters.', 'ms-update-guard' ) . '</p><table class="widefat striped" style="max-width:720px"><tbody>';
		foreach ( Settings::SECRETS as $name => $constant ) {
			printf( '<tr><td><code>%s</code></td><td><code>%s</code></td><td>%s</td><td>', esc_html( $name ), esc_html( $constant ), esc_html( Settings::secret_source( $name ) ) );
			if ( 'constant' !== Settings::secret_source( $name ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'msug_save_secret' );
				printf( '<input type="hidden" name="action" value="msug_save_secret"><input type="hidden" name="name" value="%s"><input type="password" name="value" autocomplete="new-password" minlength="32"> ', esc_attr( $name ) );
				submit_button( __( 'Set', 'ms-update-guard' ), 'small', 'submit', false );
				echo '</form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Health checks.
	 *
	 * @return void
	 */
	private static function render_health() {
		echo '<table class="widefat striped" style="max-width:960px"><tbody>';
		foreach ( self::health( 0 ) as $row ) {
			printf( '<tr><td>%s</td><td><strong>%s</strong></td><td>%s</td></tr>', 'ok' === $row['status'] ? '&#9989;' : '&#9888;&#65039;', esc_html( $row['check'] ), esc_html( $row['detail'] ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Health rows, shared with `wp msug doctor`.
	 *
	 * @param int $site_id Optional site for a live provider preflight.
	 * @return array
	 */
	public static function health( $site_id ) {
		$rows = array();
		$add  = function ( $check, $ok, $detail ) use ( &$rows ) {
			$rows[] = array(
				'check'  => $check,
				'status' => $ok ? 'ok' : 'problem',
				'detail' => $detail,
			);
		};
		$gw   = Plugin::gateway()->available();
		$add( 'MainWP Dashboard 6.2+ and Abilities API', ! is_wp_error( $gw ), is_wp_error( $gw ) ? $gw->get_error_message() : 'available' );
		$uid = (int) Settings::get( 'service_user_id' );
		$add( 'Service user', $uid > 0 && user_can( $uid, 'manage_options' ), $uid > 0 ? 'user #' . $uid : 'not set - cron/CLI cannot call MainWP' );
		$runner = (string) Settings::get( 'runner_url' );
		$add( 'Runner URL on allowlist', '' !== $runner && Url_Policy::allowed( $runner ), '' !== $runner ? $runner : 'not set' );
		foreach ( array( 'trigger', 'status', 'callback' ) as $name ) {
			$source = Settings::secret_source( $name );
			$add( 'Secret ' . $name, 'missing' !== $source, $source );
		}
		$add( 'Production mode', ! Url_Policy::test_mode(), Url_Policy::test_mode() ? 'MSUG_INSECURE_LOCAL_TEST_MODE is defined - remove it outside local tests' : 'https and allowlist enforced' );
		$native = array();
		foreach ( Update_Path_Guard::NATIVE_OPTIONS as $option ) {
			if ( (int) get_option( $option ) ) {
				$native[] = $option;
			}
		}
		$add( 'MainWP native automatic updates off', ! $native, $native ? 'active: ' . implode( ', ', $native ) : 'off' . ( Settings::get( 'enforce_native_updates_off' ) ? ' (enforced by the Guard)' : '' ) );
		$hb = Heartbeat::status();
		$add( 'Heartbeat', (bool) Settings::get( 'heartbeat_url' ) && ! empty( $hb['ok_at'] ) && time() - (int) $hb['ok_at'] < 600, Settings::get( 'heartbeat_url' ) ? 'last ok ' . ( ! empty( $hb['ok_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $hb['ok_at'] ) . ' UTC' : 'never' ) : 'not configured' );
		$add( 'Alert channel', '' !== (string) Settings::get( 'alert_emails' ) || '' !== (string) Settings::get( 'alert_webhook_url' ), 'e-mail: ' . ( Settings::get( 'alert_emails' ) ? Settings::get( 'alert_emails' ) : '-' ) . ' / webhook: ' . ( Settings::get( 'alert_webhook_url' ) ? 'set' : '-' ) );
		$add( 'System cron', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'Run `wp msug tick` every minute from system cron; DISABLE_WP_CRON ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'is set' : 'is not set' ) );
		if ( $site_id > 0 ) {
			$pf = ( new Adapters\WPTC_Backup_Provider( Plugin::gateway() ) )->preflight( $site_id );
			$add( 'WPTC preflight site #' . $site_id, ! is_wp_error( $pf ) && ! empty( $pf['ok'] ), is_wp_error( $pf ) ? $pf->get_error_message() : $pf['code'] . ( ! empty( $pf['message'] ) ? ' - ' . $pf['message'] : '' ) );
		}
		return $rows;
	}

	/**
	 * Queue a run from the site screen.
	 *
	 * @return void
	 */
	public static function handle_enqueue() {
		self::require_cap();
		check_admin_referer( 'msug_enqueue' );
		$site_id   = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
		$selection = array();
		foreach ( array(
			'plugins' => 'plugin',
			'themes'  => 'theme',
		) as $key => $type ) {
			$list = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			foreach ( array_filter( array_map( 'trim', explode( ',', $list ) ) ) as $slug ) {
				$selection[] = array(
					'type' => $type,
					'slug' => $slug,
				);
			}
		}
		if ( ! empty( $_POST['core'] ) ) {
			$selection[] = array(
				'type' => 'core',
				'slug' => 'wordpress',
			);
		}
		$run = Plugin::engine()->enqueue( $site_id, $selection, 'manual', array( 'accept_preexisting_defect' => ! empty( $_POST['accept_preexisting_defect'] ) ) );
		if ( is_wp_error( $run ) ) {
			self::back( array( 'tab' => 'sites' ), $run->get_error_message() );
		}
		self::back( array( 'run' => $run['run_id'] ), __( 'Run queued. It starts with the next tick.', 'ms-update-guard' ) );
	}

	/**
	 * Resolve a run.
	 *
	 * @return void
	 */
	public static function handle_resolve() {
		self::require_cap();
		check_admin_referer( 'msug_resolve' );
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$result = Run_Repository::resolve( $run_id, $note );
		self::back( array( 'run' => $run_id ), is_wp_error( $result ) ? $result->get_error_message() : __( 'Resolved; site lock released.', 'ms-update-guard' ) );
	}

	/**
	 * Save global settings.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		self::require_cap();
		check_admin_referer( 'msug_save_settings' );
		$input = array();
		foreach ( array_keys( Settings::defaults() ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$input[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		Settings::save( $input );
		Journal::add( '', 0, 'SETTINGS_CHANGED', array( 'keys' => array_keys( $input ) ) );
		self::back( array( 'tab' => 'settings' ), __( 'Settings saved.', 'ms-update-guard' ) );
	}

	/**
	 * Save a site.
	 *
	 * @return void
	 */
	public static function handle_save_site() {
		self::require_cap();
		check_admin_referer( 'msug_save_site' );
		$site_id = isset( $_POST['site_id'] ) ? absint( $_POST['site_id'] ) : 0;
		$input   = array();
		foreach ( Settings::site_keys() as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$input[ $key ] = is_array( $_POST[ $key ] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST[ $key ] ) ) : sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}
		Settings::save_site( $site_id, $input );
		Journal::add( '', $site_id, 'SITE_SETTINGS_CHANGED', array( 'keys' => array_keys( $input ) ) );
		self::back( array( 'tab' => 'sites' ), __( 'Site saved.', 'ms-update-guard' ) );
	}

	/**
	 * Store a secret.
	 *
	 * @return void
	 */
	public static function handle_save_secret() {
		self::require_cap();
		check_admin_referer( 'msug_save_secret' );
		$name = isset( $_POST['name'] ) ? sanitize_key( wp_unslash( $_POST['name'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Secret is stored verbatim, never output.
		$value = isset( $_POST['value'] ) ? (string) wp_unslash( $_POST['value'] ) : '';
		$ok    = Settings::set_secret( $name, $value );
		Journal::add( '', 0, $ok ? 'SECRET_ROTATED' : 'SECRET_REJECTED', array( 'name' => $name ) );
		self::back( array( 'tab' => 'settings' ), $ok ? __( 'Secret stored.', 'ms-update-guard' ) : __( 'Secret rejected (min. 32 characters).', 'ms-update-guard' ) );
	}

	/**
	 * Capability check for write actions; each handler verifies its nonce right after.
	 *
	 * @return void
	 */
	private static function require_cap() {
		if ( ! current_user_can( self::cap( 'manage' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ms-update-guard' ), 403 );
		}
	}

	/**
	 * Redirect back with a notice.
	 *
	 * @param array  $args   Query args.
	 * @param string $notice Notice text.
	 * @return void
	 */
	private static function back( array $args, $notice ) {
		$args['msug_notice'] = $notice;
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Admin URL of the page.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Text input row.
	 *
	 * @param string $name        Field.
	 * @param string $label       Label.
	 * @param mixed  $value       Value.
	 * @param string $placeholder Placeholder.
	 * @return void
	 */
	private static function text_row( $name, $label, $value, $placeholder ) {
		printf(
			'<tr><th scope="row"><label for="msug-%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="msug-%1$s" name="%1$s" value="%3$s" placeholder="%4$s"></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) $value ),
			esc_attr( (string) $placeholder )
		);
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $name    Field.
	 * @param string $label   Label.
	 * @param bool   $checked Checked.
	 * @return void
	 */
	private static function checkbox_row( $name, $label, $checked ) {
		printf(
			'<tr><th scope="row">%2$s</th><td><input type="hidden" name="%1$s" value="0"><label><input type="checkbox" name="%1$s" value="1" %3$s> %2$s</label></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			checked( (bool) $checked, true, false )
		);
	}
}
