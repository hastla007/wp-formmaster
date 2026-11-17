# WordPress.org-Assets erzeugen (P6-12)

Die Dateien in `.wordpress-org/` gehören ins SVN-Verzeichnis `/assets` des Plugins, nicht ins ZIP
(`bin/build-release.sh` arbeitet mit einer Allowlist). Icon und Banner sind eigene Grafiken
(`icon.svg`, `banner.svg`, GPL-2.0-or-later wie das Plugin).

1. Frische Instanz starten: `E2E_PORT=8890 E2E_DIR=$PWD/build/shot-site E2E_DB_NAME=wp_shots bin/e2e-site.sh start`
2. Mail-Senke nur für diese Instanz: `mkdir -p build/shot-site/wp-content/mu-plugins && echo "<?php add_filter( 'pre_wp_mail', '__return_true' );" > build/shot-site/wp-content/mu-plugins/mail-sink.php`
3. Beispieldaten (fiktive Personen, `example.com`): `wp --path=build/shot-site eval-file tools/wporg-assets/seed.php`, danach `wp --path=build/shot-site form-captain jobs run`
4. Screenshots: `node tools/wporg-assets/shots.mjs .wordpress-org` (Playwright, 1280 × 800)
5. Icon/Banner aus SVG: `node tools/wporg-assets/render-assets.mjs .wordpress-org`

Screenshots zeigen die Quelltextsprache Englisch; Reihenfolge und Beschriftung entsprechen `readme.txt` → *Screenshots*.
