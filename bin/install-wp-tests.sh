#!/usr/bin/env bash
# Prepares WordPress core for integration tests (P1-12).
# Usage: bin/install-wp-tests.sh [wp-version] ; DB settings via WP_TESTS_DB_* env vars.
# Core is fetched from the official GitHub mirror (git), the test library comes from
# the wp-phpunit/wp-phpunit Composer package.
set -euo pipefail

WP_VERSION="${1:-${WP_VERSION:-7.0.8}}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# "latest" means the newest stable release tag (x.y or x.y.z), not the development branch.
if [ "$WP_VERSION" = "latest" ]; then
	WP_VERSION="$(git ls-remote --tags https://github.com/WordPress/WordPress.git \
		| sed -n 's#.*refs/tags/\([0-9]*\.[0-9]*\(\.[0-9]*\)\{0,1\}\)$#\1#p' | sort -V | tail -1)"
	[ -n "$WP_VERSION" ] || { echo "Could not resolve the latest WordPress release" >&2; exit 1; }
fi
CORE_DIR="${WP_CORE_DIR:-$ROOT/vendor/wordpress/$WP_VERSION}"

if [ ! -f "$CORE_DIR/wp-includes/version.php" ]; then
	mkdir -p "$(dirname "$CORE_DIR")"
	git clone --quiet --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/WordPress.git "$CORE_DIR"
fi
echo "WordPress core ready: $CORE_DIR"
# Expose the resolved core path to later CI steps (tests/wp-tests-config.php reads WP_CORE_DIR).
if [ -n "${GITHUB_ENV:-}" ]; then
	echo "WP_CORE_DIR=$CORE_DIR" >> "$GITHUB_ENV"
fi

DB_NAME="${WP_TESTS_DB_NAME:-wp_tests}"
DB_USER="${WP_TESTS_DB_USER:-wp}"
DB_PASS="${WP_TESTS_DB_PASSWORD:-wp}"
DB_HOST="${WP_TESTS_DB_HOST:-localhost}"
if command -v mysql >/dev/null 2>&1; then
	mysql --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`" 2>/dev/null || true
fi
