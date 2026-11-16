#!/usr/bin/env bash
# Technical release ZIP check (P6-18): file list, permissions, PHP syntax at the
# minimum version, text domain usage, no tests/secrets/dev files, installability.
set -euo pipefail
cd "$(dirname "$0")/.."
ZIP="${1:-$(ls -t dist/form-captain-*.zip | head -1)}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
unzip -q "$ZIP" -d "$TMP"
PLUGIN="$TMP/form-captain"
fail=0
err() { echo "FAIL: $*" >&2; fail=1; }

[ -f "$PLUGIN/form-captain.php" ] || err "main plugin file missing"
[ -f "$PLUGIN/readme.txt" ] || err "readme.txt missing"
[ -f "$PLUGIN/uninstall.php" ] || err "uninstall.php missing"

# Forbidden content.
for pattern in 'tests/' 'node_modules/' 'vendor/' '.git' 'phpunit' 'composer.lock' 'package.json' '.env' 'docs/' 'connectors/'; do
	if (cd "$TMP" && find form-captain -path "*$pattern*" | grep -q .); then
		err "forbidden path in release: $pattern"
	fi
done

# Permissions.
if find "$PLUGIN" -type f ! -perm 644 | grep -q .; then err "files must be 0644"; fi
if find "$PLUGIN" -type d ! -perm 755 | grep -q .; then err "directories must be 0755"; fi

# PHP syntax.
while IFS= read -r -d '' f; do
	php -l "$f" >/dev/null 2>&1 || err "syntax error in ${f#$TMP/}"
done < <(find "$PLUGIN" -name '*.php' -print0)

# Text domain: every translation call uses 'form-captain'.
if grep -rhoE "__\(|_e\(|esc_html__\(|esc_attr__\(|_x\(|_n\(" "$PLUGIN/src" >/dev/null; then
	bad=$(grep -rnE "(__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e|_x|_n)\([^)]*'(default|formcaptain|form_captain)'\)" "$PLUGIN" || true)
	[ -z "$bad" ] || err "wrong text domain: $bad"
fi

# Header sanity.
grep -q 'Text Domain:       form-captain' "$PLUGIN/form-captain.php" || err "text domain header"
grep -q 'Requires PHP:      8.3' "$PLUGIN/form-captain.php" || err "requires PHP header"
grep -q 'License:           GPL-2.0-or-later' "$PLUGIN/form-captain.php" || err "license header"

# Version consistency: plugin header, constant and readme Stable tag must match for releases.
HEADER_VERSION=$(sed -n 's/^ \* Version: *//p' "$PLUGIN/form-captain.php" | head -1 | tr -d '[:space:]')
CONST_VERSION=$(sed -n "s/^define( 'FORM_CAPTAIN_VERSION', '\(.*\)' );/\1/p" "$PLUGIN/form-captain.php")
STABLE_TAG=$(sed -n 's/^Stable tag: *//p' "$PLUGIN/readme.txt" | tr -d '[:space:]')
[ "$HEADER_VERSION" = "$CONST_VERSION" ] || err "version header ($HEADER_VERSION) != FORM_CAPTAIN_VERSION ($CONST_VERSION)"
case "$HEADER_VERSION" in
	*-dev*) echo "Note: development version $HEADER_VERSION (Stable tag $STABLE_TAG) – not a release build" ;;
	*) [ "$HEADER_VERSION" = "$STABLE_TAG" ] || err "version $HEADER_VERSION != readme Stable tag $STABLE_TAG" ;;
esac
grep -q "^= $STABLE_TAG =" "$PLUGIN/readme.txt" || err "no changelog entry for $STABLE_TAG"

# Secrets.
if grep -rnIE -- '-----BEGIN [A-Z ]*PRIVATE KEY-----|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{36}' "$PLUGIN"; then err "secret-like string found"; fi

# Checksum.
if [ -f "$ZIP.sha256" ]; then (cd "$(dirname "$ZIP")" && sha256sum -c "$(basename "$ZIP").sha256" >/dev/null) || err "checksum mismatch"; fi

[ $fail -eq 0 ] && echo "Release check OK: $ZIP ($(cd "$TMP" && find form-captain -type f | wc -l) files)"
exit $fail
