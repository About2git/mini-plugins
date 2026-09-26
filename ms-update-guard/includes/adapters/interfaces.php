<?php
/**
 * Adapter contracts (spec section 5). Implementations are replaceable via the
 * "msug_adapters" filter, e.g. for staging doubles or a future vendor API.
 *
 * @package MSUpdateGuard
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/interface-update-inventory.php';
require_once __DIR__ . '/interface-backup-provider.php';
require_once __DIR__ . '/interface-update-executor.php';
require_once __DIR__ . '/interface-version-verifier.php';
require_once __DIR__ . '/interface-external-test-runner.php';
require_once __DIR__ . '/interface-regression-status.php';
