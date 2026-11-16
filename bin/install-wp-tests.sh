#!/usr/bin/env bash
# Prepares WordPress core for integration tests (P1-12).
# Usage: bin/install-wp-tests.sh [wp-version] ; DB settings via WP_TESTS_DB_* env vars.
# Core is fetched from the official GitHub mirror (git), the test library comes from
# the wp-phpunit/wp-phpunit Composer package.
set -euo pipefail

WP_VERSION="${1:-${WP_VERSION:-6.8.3}}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CORE_DIR="${WP_CORE_DIR:-$ROOT/vendor/wordpress/$WP_VERSION}"

if [ ! -f "$CORE_DIR/wp-includes/version.php" ]; then
	mkdir -p "$(dirname "$CORE_DIR")"
	if [ "$WP_VERSION" = "latest" ]; then
		git clone --quiet --depth 1 https://github.com/WordPress/WordPress.git "$CORE_DIR"
	else
		git clone --quiet --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/WordPress.git "$CORE_DIR"
	fi
fi
echo "WordPress core ready: $CORE_DIR"

DB_NAME="${WP_TESTS_DB_NAME:-wp_tests}"
DB_USER="${WP_TESTS_DB_USER:-wp}"
DB_PASS="${WP_TESTS_DB_PASSWORD:-wp}"
DB_HOST="${WP_TESTS_DB_HOST:-localhost}"
if command -v mysql >/dev/null 2>&1; then
	mysql --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`" 2>/dev/null || true
fi
