#!/usr/bin/env bash
# PHP syntax check of all shipped and test PHP files (P1-02, P6-18).
set -euo pipefail
cd "$(dirname "$0")/.."
status=0
while IFS= read -r -d '' file; do
	if ! out=$(php -l "$file" 2>&1); then
		echo "$out"
		status=1
	fi
done < <(find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -not -path './build/*' -print0)
[ $status -eq 0 ] && echo "PHP lint OK"
exit $status
