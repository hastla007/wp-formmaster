#!/usr/bin/env bash
# Starts a disposable WordPress site with Form Captain for Playwright E2E tests (P6-13).
# Usage: bin/e2e-site.sh [start|stop|reset]   Env: E2E_PORT (8889), E2E_DIR, WP_VERSION, E2E_DB_*
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CMD="${1:-start}"
WP_VERSION="${WP_VERSION:-6.8.3}"
PORT="${E2E_PORT:-8889}"
DIR="${E2E_DIR:-$ROOT/build/e2e-site}"
DB_NAME="${E2E_DB_NAME:-wp_e2e}"
DB_USER="${E2E_DB_USER:-wp}"
DB_PASS="${E2E_DB_PASSWORD:-wp}"
DB_HOST="${E2E_DB_HOST:-localhost}"
URL="http://127.0.0.1:$PORT"
PIDFILE="$DIR/.server.pid"

stop() {
	if [ -f "$PIDFILE" ]; then kill "$(cat "$PIDFILE")" 2>/dev/null || true; rm -f "$PIDFILE"; fi
}

if [ "$CMD" = "stop" ]; then stop; exit 0; fi
if [ "$CMD" = "reset" ]; then stop; rm -rf "$DIR"; fi

"$ROOT/bin/install-wp-tests.sh" "$WP_VERSION" >/dev/null
CORE="${WP_CORE_DIR:-$ROOT/vendor/wordpress/$WP_VERSION}"

if [ ! -f "$DIR/wp-config.php" ]; then
	mkdir -p "$DIR"
	cp -R "$CORE/." "$DIR/"
	rm -rf "$DIR/.git"
	mkdir -p "$DIR/wp-content/plugins"
	ln -sfn "$ROOT" "$DIR/wp-content/plugins/form-captain"
	cat > "$DIR/wp-config.php" <<PHP
<?php
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as \$k ) { define( \$k, 'e2e-' . \$k ); }
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
define( 'WP_HOME', '$URL' );
define( 'WP_SITEURL', '$URL' );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP
	mysql --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\`" 2>/dev/null || mysql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\`; GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
	php -d display_errors=0 -r "
		define( 'WP_INSTALLING', true );
		\$_SERVER['HTTP_HOST'] = '127.0.0.1:$PORT';
		require '$DIR/wp-load.php';
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		wp_install( 'Form Captain E2E', 'admin', 'admin@example.org', false, '', 'admin-password' );
		update_option( 'permalink_structure', '/%postname%/' );
	"
	php -d display_errors=0 -r "
		\$_SERVER['HTTP_HOST'] = '127.0.0.1:$PORT';
		require '$DIR/wp-load.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		\$r = activate_plugin( 'form-captain/form-captain.php' );
		if ( is_wp_error( \$r ) ) { fwrite( STDERR, \$r->get_error_message() ); exit( 1 ); }
		delete_option( 'form_captain_activation_redirect' );
	"
	cat > "$DIR/router.php" <<'PHP'
<?php
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = __DIR__ . $path;
if ( $path !== '/' && is_file( $file ) && ! str_ends_with( $file, '.php' ) ) { return false; }
if ( is_file( $file ) && str_ends_with( $file, '.php' ) ) { $_SERVER['SCRIPT_NAME'] = $path; require $file; return; }
if ( is_dir( $file ) && is_file( rtrim( $file, '/' ) . '/index.php' ) ) { $_SERVER['SCRIPT_NAME'] = rtrim( $path, '/' ) . '/index.php'; require rtrim( $file, '/' ) . '/index.php'; return; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
PHP
fi

stop
( cd "$DIR" && setsid nohup php -S "127.0.0.1:$PORT" router.php < /dev/null > "$DIR/server.log" 2>&1 & echo $! > "$PIDFILE" )
for _ in $(seq 1 30); do
	curl -s -o /dev/null "$URL/wp-login.php" && break
	sleep 0.5
done
echo "WordPress running at $URL (admin / admin-password)"
