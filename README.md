# Form Captain (wp-formmaster)

Form Captain ist ein WordPress-natives Formular- und Einsendungs-Plugin: Formulare visuell erstellen, Einsendungen sicher in eigenen Tabellen der eigenen WordPress-Installation verwalten und optional über einen versionierten öffentlichen Vertrag mit der Captain Suite und eigenen Connector-Plugins verbinden.

- **Form Captain (Free)** – dieses Repository, Slug `form-captain`, für WordPress.org vorgesehen. Keine Lizenzprüfung, keine künstlichen Grenzen.
- **Form Captain Pro** – separates Add-on `form-captain-pro` (eigenes Repository), nutzt nur die öffentlichen Erweiterungspunkte dieses Kerns.

## Dokumentation

| Thema | Datei |
|---|---|
| Produktkonzept | [konzept.md](konzept.md) |
| Umsetzungsliste | [TODO.md](TODO.md) |
| Spezifikationen (Datenmodell, Schema, API, Datenschutz, Sicherheit …) | [docs/spec/](docs/spec/) |
| Architekturentscheidungen | [docs/adr/](docs/adr/) |
| Handbuch für Websitebetreiber | [docs/handbuch/](docs/handbuch/) |
| Connector-Entwicklung | [docs/CONNECTORS.md](docs/CONNECTORS.md) |
| Release und Betrieb | [docs/RELEASE.md](docs/RELEASE.md), [docs/BETRIEB.md](docs/BETRIEB.md) |

## Voraussetzungen

WordPress 7.0+, PHP 8.5+, MySQL 9.7+ oder MariaDB 12.3+ (InnoDB). WooCommerce ist optional (ab 11.2). Ältere Datenbankserver werden nicht gesperrt, der Systemstatus warnt. Multisite wird in v1 nicht unterstützt.

## Entwicklung

```bash
composer install
npm ci
```

```bash
bin/install-wp-tests.sh 7.0.8
```

```bash
composer lint && composer phpcs && composer test:integration
```

```bash
npm run build
```

```bash
bin/e2e-site.sh start && npm run e2e
```

```bash
bin/build-release.sh && bin/check-release.sh
```

Integrationstests erwarten eine MySQL/MariaDB-Datenbank; Zugangsdaten über `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD`, `WP_TESTS_DB_HOST` (Standard `wp_tests` / `wp` / `wp` / `localhost`). WordPress-Core wird per Git aus dem offiziellen GitHub-Spiegel nach `vendor/wordpress/<version>` geladen.

## Struktur

```
form-captain.php      Bootstrap
includes/             öffentliche Contract-Funktionen (form_captain_*_v1)
src/                  Klassen (Namespace FormCaptain\, PSR-4)
assets/src|build      JavaScript/CSS-Quellen und reproduzierbarer Build
blocks/form           Gutenberg-Block
templates/forms       Formularvorlagen
schema/               JSON-Schema des Formularformats
connectors/           Referenz-Connector (nicht im Release-ZIP)
tests/                Integrations- und E2E-Tests
```

## Lizenz

GPL-2.0-or-later.
