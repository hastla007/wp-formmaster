# Gesamtabnahme Form Captain – Stand 16.11.2026

Geprüft auf Branch `claude/youthful-babbage-wkrqyv` gegen die Gesamt-Abnahmekriterien aus TODO.md. **Nachprüfung 16.11.2026 auf `claude/sleepy-ptolemy-n32guj`:** 392 Integrationstests und 11 Zwei-Plugin-Tests grün (WP 6.8.3, MariaDB, PHP 8.3), PHPCS 0 Fehler, Browser-E2E 11/11 und Accessibility-Audit ohne Befund (Chromium), Release-Build und Release-Check OK.

| # | Kriterium | Ergebnis | Nachweis |
|---|---|---|---|
| 1 | Free v1.0 installier-, einricht- und aktualisierbar; Formulare bauen, veröffentlichen, absenden, speichern, verwalten, exportieren | **erfüllt** | 351 Integrationstests grün (Nachprüfung: 392) (WP 6.8.3, MariaDB 10.11, PHP 8.3); Browser-E2E `tests/e2e/main-flow.mjs` 11/11 Schritte (Chromium); Migrations-/Upgrade-Tests in `tests/integration/InstallTest.php` |
| 2 | Datenschutz-, Berechtigungs-, Spam-, E-Mail-, CSV-, Migrations- und Löschpfade abgenommen | **erfüllt** (technisch) | Tests unter `tests/integration/{Security,Privacy,Entries,Frontend,Settings}`; Sicherheitsreview `docs/SECURITY-REVIEW.md` (8 Befunde behoben); Accessibility-Audit `tests/e2e/a11y.mjs` ohne blockierende Befunde. Rechtliche Freigabe (P9-10) steht aus |
| 3 | WordPress.org-fähig, vollständige Doku, reproduzierbarer Build | **erfüllt** (technisch) | `bin/build-release.sh` deterministisch (gleiche SHA-256), `bin/check-release.sh` OK, readme.txt, Handbuch `docs/handbuch/`, de_DE/de_AT vollständig, phpcs 0 Fehler. Einreichung selbst braucht Konto/Assets (P6-12, docs/WORDPRESS-ORG.md) |
| 4 | Pro v1 separates, korrekt lizenziertes Add-on; Pro-Ablauf löscht keine Free-Daten | **technisch erfüllt, Freigabe offen** | Add-on `form-captain-pro` in captain-pro PR #9 mit fünf getrennt lizenzierten Funktionen (P7-05 bis P7-18). Lizenzzustände mit den echten Feature-Klassen gegen den signierenden Test-Lizenzserver geprüft (P7-06, `tests/Addons/FormLicenseStatesTest.php`): ohne, abgelaufene, gesperrte oder widerrufene Lizenz, offline, nach Domainwechsel und nach Deaktivierung bleiben Formulare, Einstellungen, Dateien und Einträge erhalten. Offen: E2E gegen einen echten License-Captain-Server (P7-13, braucht WooCommerce) und Freigabe der Rechteschlüssel durch den Inhaber (P7-01). |
| 5 | Suite-Adapter nur über freigegebene Verträge, idempotent, Datenbesitz-/Einladungsgrenzen | **teilweise** | Kernseite vollständig: Contract v1, Connector-Registry, Opt-in, Outbox, Contract-Testkit, Referenz-Connector, Zwei-Plugin-Tests. Konkrete Adapter (Pass, Voucher, Review, Affiliate) und Kunden-Connectoren liegen in fremden Repositories |
| 6 | Phase-9-Erweiterungen mit Releaseplan oder bewusst ausgeschlossen | **erfüllt** | `docs/planung/*.md`, Importer umgesetzt (P9-06), Multisite/Mehrsprachigkeit/Zahlungen mit Prüfraster |

## Suite-E2E mit dem Referenz-Connector (P8-12, 16.11.2026)

Die Suite-Adapter für Pass und Voucher gehören in deren Repositories und sind dort noch nicht gebaut (Schritte im jeweiligen TODO, siehe P8-05 und P8-06). Den Ablauf auf Seite von Form Captain prüft der Referenz-Connector (`connectors/form-captain-reference-connector`) in echtem WordPress. Lauf am 16.11.2026: 51 Tests, 489 Prüfungen, alle grün (`--filter 'Connector|ReferenceConnector|ContractApi'`).

| Szenario aus P8-12 | Test |
| --- | --- |
| Formulareinsendung und Feldmapping | `ConnectorJobTest::test_job_receives_only_mapped_values`, `…sensitive_field_is_blocked_by_default`, `…missing_required_mapping_and_type_mismatch` |
| Doppelzustellung | `ReferenceConnectorTest::test_successful_delivery_and_duplicate_delivery`, `…timeout_after_success_does_not_create_a_second_ticket` |
| Plugin-Deaktivierung | `ConnectorPauseTest::test_deactivation_pauses_exactly_the_jobs_of_this_connector`, `…reactivation_resumes_and_job_succeeds_with_attempts_kept`, `ReferenceConnectorTest::test_deactivated_connector_does_not_deliver` |
| API-Ausfall | `ReferenceConnectorTest::test_temporary_outage_is_retried`, `…permanent_failure_goes_to_dead_letter_and_entry_stays` |
| Entry-Löschung | `ConnectorPauseTest::test_entry_deletion_cancels_paused_jobs`, `ReferenceConnectorTest::test_deleted_entry_is_not_delivered`, `ConnectorJobTest::test_deleted_entry_fails_without_calling_connector` |
| Inkompatible Version | `ConnectorRegistryTest::test_incompatible_major_is_visible_and_cannot_be_activated`, `ReferenceConnectorTest::test_incompatible_version_of_a_connector_is_reported` |

Noch zu wiederholen, sobald es sie gibt: dieselben Szenarien mit den echten Adaptern von Pass Captain, Voucher Captain und Review Captain (P8-08).

## Offene Entscheidungen (Produktverantwortung)

Entschieden am 16.11.2026 und umgesetzt (docs/ENTSCHEIDUNGEN.md Nr. 1–18): ADR 0004 Queue, Eraser-Standard „löschen“, Notizen im Auskunftsexport, Einwilligungswiderruf in v1 (Schema v2), Spam-Löschfrist 30 Tage, Pro-Preise 59/129/249 € netto, Supportkanäle, WordPress.org nach externem Sicherheitsreview, Lizenz der Projekt-Connectoren (Nr. 17, geändert).

Noch offen:

- Konkrete Adresse des Sicherheitskontakts (`[SECURITY-KONTAKT]`, Entscheidung Nr. 15).
- WordPress.org-Konto, Slug-Prüfung, Markenprüfung, Contributors, Plugin-/Author-URI (docs/WORDPRESS-ORG.md E-1 bis E-3, E-5, E-6).
- Datenschutz-/Rechtsfreigabe (P9-10, Entscheidung Nr. 16: später; Prüfmappe docs/RECHTSPRUEFUNG.md).

## Manuelle Prüfungen vor dem ersten Release

- Firefox/WebKit-E2E und echte Yoast-Free/Premium-Matrix (in CI vorgesehen bzw. manuell).
- Mailzustellung auf typischen Hostern, Release-/Rollback-Übung auf Staging (docs/RELEASE.md, docs/BETRIEB.md).
- Externes Sicherheitsreview (Empfehlungen in docs/SECURITY-REVIEW.md, Kapitel 5).
