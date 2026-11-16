#!/usr/bin/env bash
# Lightweight secret scan for committed files (P1-15). CI additionally runs gitleaks.
set -euo pipefail
cd "$(dirname "$0")/.."
patterns=(
	'-----BEGIN (RSA|EC|OPENSSH|DSA|PGP) PRIVATE KEY-----'
	'AKIA[0-9A-Z]{16}'
	'ghp_[A-Za-z0-9]{36}'
	'github_pat_[A-Za-z0-9_]{40,}'
	'xox[baprs]-[A-Za-z0-9-]{10,}'
	'sk_live_[A-Za-z0-9]{20,}'
	'AIza[0-9A-Za-z_\-]{35}'
)
found=0
for p in "${patterns[@]}"; do
	if git ls-files -z | xargs -0 grep -nIE -- "$p" 2>/dev/null; then
		found=1
	fi
done
if [ $found -ne 0 ]; then
	echo "Potential secret found. Remove it and rotate the credential." >&2
	exit 1
fi
echo "Secret scan OK"
