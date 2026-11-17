# ADR 0005 – Entwicklungs- und CI-Umgebung (P0-12, P1-12)

| Bereich | Festlegung |
|---|---|
| PHP | 8.5 (Minimum, Entscheidung 21) in CI |
| WordPress | 6.8 (Minimum) und aktuelle stabile Version |
| Datenbank | MariaDB 12.3, MySQL 9.7 (Minimum, Entscheidung 21) |
| Testframework | PHPUnit 9.6 + `wp-phpunit/wp-phpunit` + `yoast/phpunit-polyfills` |
| Statische Analyse | PHPStan (Level 6) mit `szepeviktor/phpstan-wordpress`, WPCS 3 |
| JS-Build | Node 20+, `esbuild` |
| E2E | Playwright (Chromium; Firefox/WebKit in nächtlicher Matrix) gegen `@wordpress/env` oder lokales WP |
| Browser (Support) | aktuelle und vorherige Version von Chrome, Firefox, Safari, Edge; iOS Safari, Android Chrome |

**Befehle**

```bash
composer install
composer lint        # PHP-Syntax (php -l) über alle Dateien
composer phpcs
composer phpstan
bin/install-wp-tests.sh 6.8.3   # einmalig: WordPress-Core per Git nach vendor/wordpress/6.8.3
composer test:integration
npm ci && npm run build
npm run e2e
bin/build-release.sh # Release-ZIP + SHA-256
```

`tests/integration` nutzt die WordPress-Test-Suite aus `wp-phpunit/wp-phpunit` (Composer) mit dem WordPress-Core aus dem offiziellen GitHub-Spiegel (Git-Klon über `bin/install-wp-tests.sh`, keine SVN- oder wordpress.org-Abhängigkeit). PHPStan wird in CI global installiert (`composer global require`), lokal optional. Konfiguration über Umgebungsvariablen `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD`, `WP_TESTS_DB_HOST`.
