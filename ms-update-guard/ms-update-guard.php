<?php
/**
 * Plugin Name:       MS Update Guard
 * Description:       Backup-gated, journaled updates for MainWP child sites. Runs on the MainWP Dashboard only.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Requires Plugins:  mainwp
 * Author:            Marius Sonnentag
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ms-update-guard
 *
 * @package MSUpdateGuard
 */

defined( 'ABSPATH' ) || exit;

define( 'MSUG_VERSION', '0.1.0' );
define( 'MSUG_DB_VERSION', '1' );
define( 'MSUG_FILE', __FILE__ );
define( 'MSUG_DIR', plugin_dir_path( __FILE__ ) );

require_once MSUG_DIR . 'includes/class-util.php';
require_once MSUG_DIR . 'includes/class-states.php';
require_once MSUG_DIR . 'includes/class-schema.php';
require_once MSUG_DIR . 'includes/class-settings.php';
require_once MSUG_DIR . 'includes/class-signature.php';
require_once MSUG_DIR . 'includes/class-url-policy.php';
require_once MSUG_DIR . 'includes/class-journal.php';
require_once MSUG_DIR . 'includes/class-run-repository.php';
require_once MSUG_DIR . 'includes/class-backup-evidence.php';
require_once MSUG_DIR . 'includes/class-outcome.php';
require_once MSUG_DIR . 'includes/class-alerts.php';
require_once MSUG_DIR . 'includes/class-heartbeat.php';
require_once MSUG_DIR . 'includes/adapters/interfaces.php';
require_once MSUG_DIR . 'includes/adapters/class-mainwp-gateway.php';
require_once MSUG_DIR . 'includes/adapters/class-mainwp-update-inventory.php';
require_once MSUG_DIR . 'includes/adapters/class-mainwp-update-executor.php';
require_once MSUG_DIR . 'includes/adapters/class-mainwp-version-verifier.php';
require_once MSUG_DIR . 'includes/adapters/class-wptc-backup-provider.php';
require_once MSUG_DIR . 'includes/adapters/class-http-test-runner.php';
require_once MSUG_DIR . 'includes/adapters/class-regression-status.php';
require_once MSUG_DIR . 'includes/class-engine.php';
require_once MSUG_DIR . 'includes/class-update-path-guard.php';
require_once MSUG_DIR . 'includes/class-rest-controller.php';
require_once MSUG_DIR . 'includes/admin/class-admin.php';
require_once MSUG_DIR . 'includes/class-plugin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once MSUG_DIR . 'includes/class-cli.php';
}

register_activation_hook( __FILE__, array( 'MSUpdateGuard\\Schema', 'install' ) );

add_action( 'plugins_loaded', array( 'MSUpdateGuard\\Plugin', 'boot' ), 20 );
