# Gesamtabnahme Form Captain – Stand 16.11.2026

Geprüft auf Branch `claude/youthful-babbage-wkrqyv` gegen die Gesamt-Abnahmekriterien aus TODO.md.

| # | Kriterium | Ergebnis | Nachweis |
|---|---|---|---|
| 1 | Free v1.0 installier-, einricht- und aktualisierbar; Formulare bauen, veröffentlichen, absenden, speichern, verwalten, exportieren | **erfüllt** | 351 Integrationstests grün (WP 6.8.3, MariaDB 10.11, PHP 8.3); Browser-E2E `tests/e2e/main-flow.mjs` 11/11 Schritte (Chromium); Migrations-/Upgrade-Tests in `tests/integration/InstallTest.php` |
| 2 | Datenschutz-, Berechtigungs-, Spam-, E-Mail-, CSV-, Migrations- und Löschpfade abgenommen | **erfüllt** (technisch) | Tests unter `tests/integration/{Security,Privacy,Entries,Frontend,Settings}`; Sicherheitsreview `docs/SECURITY-REVIEW.md` (8 Befunde behoben); Accessibility-Audit `tests/e2e/a11y.mjs` ohne blockierende Befunde. Rechtliche Freigabe (P9-10) steht aus |
| 3 | WordPress.org-fähig, vollständige Doku, reproduzierbarer Build | **erfüllt** (technisch) | `bin/build-release.sh` deterministisch (gleiche SHA-256), `bin/check-release.sh` OK, readme.txt, Handbuch `docs/handbuch/`, de_DE/de_AT vollständig, phpcs 0 Fehler. Einreichung selbst braucht Konto/Assets (P6-12, docs/WORDPRESS-ORG.md) |
| 4 | Pro v1 separates, korrekt lizenziertes Add-on; Pro-Ablauf löscht keine Free-Daten | **offen** | Kern liefert neutrale Hooks (P7-03), keine Lizenzprüfung im Free; Add-on selbst liegt im Repository captain-pro (kein Zugriff) |
| 5 | Suite-Adapter nur über freigegebene Verträge, idempotent, Datenbesitz-/Einladungsgrenzen | **teilweise** | Kernseite vollständig: Contract v1, Connector-Registry, Opt-in, Outbox, Contract-Testkit, Referenz-Connector, Zwei-Plugin-Tests. Konkrete Adapter (Pass, Voucher, Review, Affiliate) und Kunden-Connectoren liegen in fremden Repositories |
| 6 | Phase-9-Erweiterungen mit Releaseplan oder bewusst ausgeschlossen | **erfüllt** | `docs/planung/*.md`, Importer umgesetzt (P9-06), Multisite/Mehrsprachigkeit/Zahlungen mit Prüfraster |

## Offene Entscheidungen (Produktverantwortung)

- ADR 0004 Queue (eigene Outbox + WP-Cron) bestätigen.
- Eraser-Standard löschen vs. anonymisieren; interne Notizen im Auskunftsexport; Consent-Widerruf in v1; Spam-Löschfrist 30 Tage (docs/spec/datenschutz.md §10).
- WordPress.org-Konto, Slug, Supportkanal, Security-Kontakt (docs/WORDPRESS-ORG.md, docs/BETRIEB.md).
- Preise/Tarife final (P9-01), Datenschutz-/Rechtsfreigabe (P9-10).

## Manuelle Prüfungen vor dem ersten Release

- Firefox/WebKit-E2E und echte Yoast-Free/Premium-Matrix (in CI vorgesehen bzw. manuell).
- Mailzustellung auf typischen Hostern, Release-/Rollback-Übung auf Staging (docs/RELEASE.md, docs/BETRIEB.md).
- Externes Sicherheitsreview (Empfehlungen in docs/SECURITY-REVIEW.md, Kapitel 5).
