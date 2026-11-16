# ADR 0001 – Code-Struktur (P0-16, P1-01)

**Entscheidung**

- Repository-Root ist der Plugin-Root `form-captain/`; Hauptdatei `form-captain.php`.
- Namespace `FormCaptain\`, PSR-4 auf `src/`. Composer-Autoloader (`vendor/autoload.php`) mit Fallback-Autoloader in `src/Autoloader.php`, damit das Release ohne `vendor/` lauffähig bleibt (keine Produktionsabhängigkeiten).
- Öffentliche Funktionen (`form_captain_*_v1`) in `includes/functions.php`.
- Verzeichnisse: `src/` (Klassen), `includes/` (öffentliche Funktionen), `assets/src`/`assets/build`, `blocks/`, `templates/` (überschreibbar nicht vorgesehen in v1), `languages/`, `schema/`, `tests/unit`, `tests/integration`, `tests/e2e`, `bin/`, `docs/`, `connectors/` (Beispiel-Plugins, nicht im Release).
- Schichten: `Domain` (reine Logik: Schema, Validierung, Bedingungen, CSV-Bereinigung) ← `Repository` ($wpdb) ← `Service` (Submission, Entries, Jobs, Privacy) ← `Http`/`Admin`/`Frontend`. Handler enthalten keine Geschäftslogik.
- Ein kleiner DI-Container (`FormCaptain\Plugin`) erzeugt Services lazy; keine Singletons außer dem Plugin-Objekt.
- Abhängigkeitsregel: keine Produktionsabhängigkeiten von Drittpaketen in v1. Dev: PHPUnit, WPCS, PHPStan, wp-phpunit.
- Coding Standard: WordPress Coding Standards mit PSR-4-Dateinamen (Ausnahme dokumentiert in `phpcs.xml.dist`), `declare(strict_types=1)`.
- Datenzugriff: ausschließlich `$wpdb->prepare()`, Tabellennamen aus `FormCaptain\Database\Tables`.
