#!/usr/bin/env bash
# Reproducible release ZIP (P6-10). Usage: bin/build-release.sh [version]
# Produces dist/form-captain-<version>.zip and .sha256 plus release notes excerpt.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"
VERSION="${1:-$(sed -n 's/^ \* Version: *//p' form-captain.php | head -1 | tr -d '[:space:]')}"
STAGE="$ROOT/build/release/form-captain"
DIST="$ROOT/dist"

rm -rf "$ROOT/build/release" && mkdir -p "$STAGE" "$DIST"

# Shipped files only (allowlist). Tests, docs, connectors, dev tooling, sources of
# build tools and vendor are excluded – the plugin has no production dependencies.
include=(
	form-captain.php
	uninstall.php
	readme.txt
	LICENSE
	includes
	src
	templates
	blocks
	languages
	schema
	assets/build
	assets/src
)
for item in "${include[@]}"; do
	if [ -e "$item" ]; then
		mkdir -p "$STAGE/$(dirname "$item")"
		cp -R "$item" "$STAGE/$item"
	fi
done

# Normalize permissions and timestamps for reproducible archives.
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct 2>/dev/null || date +%s)}"
find "$STAGE" -exec touch -h -d "@$SOURCE_DATE_EPOCH" {} +

ZIP="$DIST/form-captain-$VERSION.zip"
rm -f "$ZIP"
( cd "$ROOT/build/release" && find form-captain -print | LC_ALL=C sort | zip -X -q "$ZIP" -@ )
( cd "$DIST" && sha256sum "$(basename "$ZIP")" > "$(basename "$ZIP").sha256" )

# Release notes: section of the current version from the changelog in readme.txt.
if [ -f readme.txt ]; then
	awk -v v="= $VERSION =" 'index($0,v)==1{p=1;next} /^= /{if(p)exit} p' readme.txt > "$DIST/RELEASE-NOTES-$VERSION.md" || true
fi

echo "Built $ZIP"
cat "$ZIP.sha256"
