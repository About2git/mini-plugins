#!/usr/bin/env bash
# Local end-to-end acceptance run (spec section 8) on one machine:
#   MariaDB + WordPress Dashboard with MainWP + WordPress child with MainWP Child, WP Time Capsule
#   and the evidence probe + the Node runner with Chromium.
#
# Requirements: php (mysqli, curl, zip), mariadb-server, node >= 20, git, curl, zip, openssl,
# a Chromium binary (MSUG_CHROMIUM_PATH). Everything is created below $WORKDIR.
#
# Usage: WORKDIR=/tmp/msug-e2e MSUG_CHROMIUM_PATH=/path/to/chrome tests/integration/run-local.sh
#
# This is a test harness. It defines MSUG_INSECURE_LOCAL_TEST_MODE (http on 127.0.0.1) in the
# throw-away Dashboard; never copy that constant to a real Dashboard.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
GUARD="$(cd "$HERE/../.." && pwd)"
WORKDIR="${WORKDIR:-/tmp/msug-e2e}"
DASH_PORT="${DASH_PORT:-8899}"
CHILD_PORT="${CHILD_PORT:-8898}"
RUNNER_PORT="${RUNNER_PORT:-8787}"
DB_PREFIX="${DB_PREFIX:-msug_e2e}"
: "${MSUG_CHROMIUM_PATH:?set MSUG_CHROMIUM_PATH to a Chromium/headless_shell binary}"

mkdir -p "$WORKDIR"
cd "$WORKDIR"
log() { printf '\n== %s\n' "$*"; }

log "MariaDB"
if ! mysqladmin ping >/dev/null 2>&1; then
  mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
  (mysqld_safe --user=mysql >"$WORKDIR/mysqld.log" 2>&1 &)
  for _ in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
fi
mysql -e "DROP DATABASE IF EXISTS ${DB_PREFIX}_dash; DROP DATABASE IF EXISTS ${DB_PREFIX}_child;
  CREATE DATABASE ${DB_PREFIX}_dash; CREATE DATABASE ${DB_PREFIX}_child;
  CREATE USER IF NOT EXISTS 'msug'@'localhost' IDENTIFIED BY 'msug';
  GRANT ALL ON ${DB_PREFIX}_dash.* TO 'msug'@'localhost'; GRANT ALL ON ${DB_PREFIX}_child.* TO 'msug'@'localhost'; FLUSH PRIVILEGES;"

log "Sources (WordPress, MainWP Dashboard, MainWP Child, WP Time Capsule, WP-CLI)"
[ -d src/wordpress ] || git clone -q --depth 1 https://github.com/WordPress/WordPress.git src/wordpress
[ -d src/mainwp ] || git clone -q --depth 1 https://github.com/mainwp/mainwp.git src/mainwp
[ -d src/mainwp-child ] || git clone -q --depth 1 https://github.com/mainwp/mainwp-child.git src/mainwp-child
[ -d src/wp-time-capsule ] || git clone -q --depth 1 https://github.com/revmakx/wp-time-capsule.git src/wp-time-capsule
[ -f wp-cli.phar ] || curl -sSL -o wp-cli.phar https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar
WPCLI="php $WORKDIR/wp-cli.phar --allow-root"
DASH="$WORKDIR/dash"
CHILD="$WORKDIR/child"
W="$WPCLI --path=$DASH"
C="$WPCLI --path=$CHILD"

install_wp() { # dir db url title
  rm -rf "$1" && mkdir -p "$1"
  (cd src/wordpress && git archive HEAD) | tar -x -C "$1"
  $WPCLI --path="$1" config create --dbname="$2" --dbuser=msug --dbpass=msug --dbhost=127.0.0.1 --skip-check --quiet \
    --extra-php <<'PHP'
define( 'DISABLE_WP_CRON', true );
define( 'WP_DEBUG_LOG', true );
PHP
  $WPCLI --path="$1" core install --url="$3" --title="$4" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
  $WPCLI --path="$1" option update home "$3" --quiet
  $WPCLI --path="$1" option update siteurl "$3" --quiet
  $WPCLI --path="$1" rewrite structure '/%postname%/' --quiet
}

log "Dashboard"
install_wp "$DASH" "${DB_PREFIX}_dash" "http://127.0.0.1:$DASH_PORT" "Dashboard"
$W config set MSUG_INSECURE_LOCAL_TEST_MODE true --raw --quiet
ln -sfn "$WORKDIR/src/mainwp" "$DASH/wp-content/plugins/mainwp"
ln -sfn "$GUARD" "$DASH/wp-content/plugins/ms-update-guard"
mkdir -p "$DASH/wp-content/mu-plugins"
ln -sfn "$HERE/fixtures/dashboard-backup-double.php" "$DASH/wp-content/mu-plugins/dashboard-backup-double.php"
$W plugin activate mainwp ms-update-guard --quiet

log "Child"
install_wp "$CHILD" "${DB_PREFIX}_child" "http://127.0.0.1:$CHILD_PORT" "Child"
ln -sfn "$WORKDIR/src/mainwp-child" "$CHILD/wp-content/plugins/mainwp-child"
ln -sfn "$WORKDIR/src/wp-time-capsule" "$CHILD/wp-content/plugins/wp-time-capsule"
mkdir -p "$CHILD/wp-content/mu-plugins"
ln -sfn "$GUARD/child-probe/msug-wptc-evidence-probe.php" "$CHILD/wp-content/mu-plugins/msug-wptc-evidence-probe.php"
ln -sfn "$HERE/fixtures/child-dummy-updates.php" "$CHILD/wp-content/mu-plugins/child-dummy-updates.php"

log "Dummy plugin packages"
B="$WORKDIR/dummy-build"; rm -rf "$B"
mk() { # zip-version header-version code
  local d="$B/$1/msug-dummy"; mkdir -p "$d"
  printf '<?php\n/**\n * Plugin Name: MSUG Dummy\n * Version: %s\n */\n%s\n' "$2" "$3" > "$d/msug-dummy.php"
  (cd "$B/$1" && rm -f "$CHILD/msug-dummy-$1.zip" && zip -qr "$CHILD/msug-dummy-$1.zip" msug-dummy)
}
mk 1.0.0 1.0.0 ''
mk 1.1.0 1.1.0 ''
mk 1.2.0 1.2.0 "add_action( 'template_redirect', function () { msug_undefined_function(); } );"
mk 1.3.0 1.1.0 ''   # mislabelled: package 1.3.0 still contains 1.1.0
mk 1.4.0 1.4.0 ''
cp -r "$B/1.0.0/msug-dummy" "$CHILD/wp-content/plugins/"
$C plugin activate mainwp-child wp-time-capsule msug-dummy --quiet

log "Web servers"
pkill -f "^php -S 127.0.0.1:$DASH_PORT" || true
pkill -f "^php -S 127.0.0.1:$CHILD_PORT" || true
(cd "$DASH" && PHP_CLI_SERVER_WORKERS=4 nohup php -S "127.0.0.1:$DASH_PORT" >"$WORKDIR/dash-server.log" 2>&1 &)
(cd "$CHILD" && PHP_CLI_SERVER_WORKERS=4 nohup php -S "127.0.0.1:$CHILD_PORT" >"$WORKDIR/child-server.log" 2>&1 &)
for _ in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:$CHILD_PORT/" && break; sleep 0.5; done

log "Runner"
(cd "$GUARD/runner" && [ -d node_modules ] || npm install --no-audit --no-fund --silent)
cat > "$WORKDIR/runner.json" <<JSON
{
  "listen": { "host": "127.0.0.1", "port": $RUNNER_PORT },
  "concurrency": 1,
  "state_dir": "$WORKDIR/runner-state",
  "artifacts_dir": "$WORKDIR/runner-artifacts",
  "parent": { "callback_url": "http://127.0.0.1:$DASH_PORT/wp-json/ms-update-guard/v1/runner-result" },
  "http": { "initial_delay_seconds": 0, "attempts": 2, "retry_delay_seconds": 2, "timeout_seconds": 10 },
  "sites": {
    "1": {
      "base_url": "http://127.0.0.1:$CHILD_PORT",
      "profiles": ["corporate-basic", "http-only"],
      "http": { "pages": [{ "name": "home", "path": "/", "marker": "Child" }] },
      "corporate": { "pages": [{ "name": "home", "path": "/", "expect_selector": "header" }] }
    }
  }
}
JSON
TRIGGER="$(openssl rand -hex 24)"; STATUS="$(openssl rand -hex 24)"; CALLBACK="$(openssl rand -hex 24)"
pkill -f "^node src/server.js" || true
rm -rf "$WORKDIR/runner-state"
(cd "$GUARD/runner" && MSUG_RUNNER_CONFIG="$WORKDIR/runner.json" MSUG_TRIGGER_KEYS="k1:$TRIGGER" MSUG_STATUS_KEYS="k1:$STATUS" \
  MSUG_CALLBACK_SECRET="$CALLBACK" MSUG_ALLOW_HTTP_FOR_TESTS=1 MSUG_CHROMIUM_PATH="$MSUG_CHROMIUM_PATH" \
  nohup node src/server.js >"$WORKDIR/runner.log" 2>&1 &)
for _ in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:$RUNNER_PORT/healthz" && break; sleep 0.5; done

log "Connect child to MainWP and configure the Guard"
$W eval "wp_set_current_user( 1 );
\$r = wp_get_ability( 'mainwp/add-site-v1' )->execute( array( 'url' => 'http://127.0.0.1:$CHILD_PORT/', 'name' => 'Child Test', 'admin_username' => 'admin', 'adminpassword' => 'admin', 'verify_certificate' => 0 ) );
if ( is_wp_error( \$r ) || 1 !== (int) \$r['id'] ) { WP_CLI::error( 'connect failed: ' . wp_json_encode( is_wp_error( \$r ) ? \$r->get_error_message() : \$r ) ); }
use MSUpdateGuard\\Settings;
Settings::save( array( 'service_user_id' => 1, 'runner_url' => 'http://127.0.0.1:$RUNNER_PORT', 'alert_emails' => 'ops@example.com', 'key_id' => 'k1' ) );
Settings::set_secret( 'trigger', '$TRIGGER' ); Settings::set_secret( 'status', '$STATUS' ); Settings::set_secret( 'callback', '$CALLBACK' );
Settings::save_site( 1, array( 'enabled' => 1, 'profile_id' => 'corporate-basic', 'settle_seconds' => 1, 'test_timeout_min' => 3, 'auto_types' => array( 'plugin' ) ) );"
$W msug doctor || true

log "Acceptance scenarios"
$W eval-file "$HERE/e2e-driver.php" "$C"
