# Form-Workflowvertrag v1 (P8-17 … P8-22) — zurückgezogen, nicht normativ

> **Zurückgezogen (Inhaberentscheidung F-12 vom 16.11.2026, analog D13 für Voucher/Affiliate).** Dieses Dokument ist **kein Vertrag** und nicht normativ. Normativ für Workflows der Suite ist allein die Suite-Spezifikation `docs/SPEC-suite-events.md` im Repository `wp-workflowmaster`; der Katalog `form.*` kommt dort als MINOR-Version der Spezifikation vor dem ersten Release. Bis dahin veröffentlicht Form Captain keinen eigenen Suite-/Workflow-Vertrag.
>
> - Die Übergabe von Mails an einen Workflow (Filter `form_captain_mail_handoff`, ehemals §4) ist **entfernt** (D10: Kerne behalten ihre Mails, keine Übergabe). Form Captain versendet Admin-Mail und Bestätigung immer selbst.
> - Verbindlich bleiben unabhängig davon der öffentliche Form-Captain-Vertrag in [`spec/api-v1.md`](spec/api-v1.md) (Events, Read-API, `FORM_CAPTAIN_CONTRACT_VERSION`) und der Connector-Vertrag in [`CONNECTORS.md`](CONNECTORS.md). Die folgenden Abschnitte beschreiben nur noch, wie der Bestand darauf abgebildet ist (Hintergrund).

Der Workflowvertrag führt außer `form_captain_consent_withdrawn_v1` (additiv, Produktentscheidung #4) **keine zusätzlichen Events** ein. Alle Workflow-Zustände werden auf die vorhandenen, nach Commit gefeuerten Events aus [`spec/api-v1.md`](spec/api-v1.md) und auf die Outbox abgebildet. Neue Events kämen nur additiv in einer Minor-Version.

## 1. Abbildung der Workflow-Ereignisse (P8-17)

| Workflow-Ereignis | Vertrags-Event / Mechanismus | Hinweise |
|---|---|---|
| Formular veröffentlicht | `form_captain_form_updated_v1( $form, 'published' )` | Form-DTO mit `version` |
| Formular deaktiviert | `form_captain_form_updated_v1( $form, 'paused' \| 'unpublished' \| 'archived' )` | |
| Entry angenommen | `form_captain_entry_created_v1( $event )` | nur für Nicht-Spam; Payload mit `form_version`, `request_id`, Allowlist-Werten |
| Entry validiert | implizit in `entry_created_v1` | Es gibt nur validierte Entries; ungültige Einsendungen werden nicht gespeichert |
| Entry markiert (gelesen, archiviert, Spam) | `form_captain_entry_status_changed_v1( $id, $old, $new, $form_id )` | „Spam“ = `new === 'spam'` |
| Entry gelöscht / anonymisiert | `form_captain_entry_deleted_v1( $id, $form_id, $reason )` | `reason`: `manual`, `retention`, `erasure`, `anonymized`, `form_deleted`, `consent_withdrawn` |
| Einwilligung widerrufen | `form_captain_consent_withdrawn_v1( $id, $form_id, $source, $event )` | additiv (Minor); Payload nur IDs, `withdrawn_gmt`, `source`, `then` – keine Werte (api-v1.md); danach keine neuen `mail.*`-/`connector.*`-Jobs |
| Benachrichtigung fehlgeschlagen | `form_captain_notification_failed_v1( $entry_id, $form_id, $job_type, $error_code )` | nach Dead-letter eines `mail.*`-Jobs |
| Aktion ausgeführt | Outbox-Job `connector.action` (Status, `remote_id`) | kein Event; sichtbar unter Integrationen; bei deaktiviertem Connector Status `paused` |

Stabile IDs: `entry_id`, `form_id`, `form_public_id`, `form_version` (Schema-Snapshot), `request_id` (Korrelation). Deprecation: `_deprecated_hook()`, `_v1` bleibt ≥ 12 Monate neben `_v2`.

## 2. Datenumfang (P8-18)

- Event-Werte nur über die Allowlist `form_captain_event_value_allowlist_v1` (Standard leer).
- Aktionen erhalten nur gemappte, validierte, nicht sensible Felder; sensible nur mit `allow_sensitive`; Datei-, Passwort-, Zahlungs-, Signatur- und HTML-Felder nie (`ConnectorRegistry::BLOCKED_FIELD_TYPES`).
- Consent wird als Referenz (`consent`-Feldwert `1`/`0`) übergeben, wenn gemappt; Nachweis bleibt im Kern.

## 3. Sichere Aktionen (P8-19)

Aktionen sind Connector-Code mit Outbox-Ausführung. Der Kern stellt keine Aktionen für Zahlung, Ticket-/Gutscheinausgabe, Partnerfreigabe oder Review-Veröffentlichung bereit. Adapter, die solche Systeme ansprechen, müssen Ergebnisse als „pending/zur Prüfung“ anlegen (Vertragspflicht der Adapter).

### Eingebaute sichere Aktionen

Der Kern bringt vier Aktionen im reservierten Namensraum `core.*` mit (`src/Integrations/CoreActions.php`). Sie laufen über dieselbe Registry (`ConnectorRegistry`) und Outbox (`connector.action`) wie Connector-Aktionen, haben aber `connector = null`: Der interne Kern-„Connector“ `core` ist immer aktiv und braucht **kein** site-weites Opt-in. Jede Aktion muss trotzdem pro Formular hinzugefügt und aktiviert werden. Der Connector-Key `core` und Aktions-Keys `core.*` sind für Dritte gesperrt (`invalid_request`).

| Aktion | Einstellungen | Verhalten | Ergebniscodes |
|---|---|---|---|
| `core.notify_admin` | `to` (eine feste Adresse), `subject` (optional, Platzhalter wie Admin-Mail) | Zusätzliche Admin-Benachrichtigung über `MailBuilder` + `wp_mail()`. Sensible Felder und Datei-/Passwort-/Zahlungs-/Signatur-/HTML-Felder werden weder im Text noch in Betreff-Platzhaltern verwendet. Kein Reply-To. | `sent`, `invalid_recipient`, `mail_failed` (retry) |
| `core.set_status` | `status`: `read` oder `archived` | Statuswechsel über `EntryService::transition()` (nur erlaubte Übergänge, Audit-Notiz, Event `entry_status_changed_v1`). `spam`/`trash` sind nie Ziel. | `status_*`, `unchanged`, `transition_not_allowed`, `invalid_status` |
| `core.anonymize_after` | `days` (1–3650) | Plant einen verzögerten Job `core.anonymize` (Idempotency-Key `form-captain:{site}:{entry}:core.anonymize:{instance}`), der nach Ablauf `PrivacyIntegration::anonymize()` aufruft (wie Eraser-Anonymisierung, Event `entry_deleted_v1` mit `anonymized`). Die Anonymisierung bricht alle offenen Jobs des Entries ab, der Job selbst endet daher als `cancelled`/`entry_anonymized`. | `scheduled` (remote_id = Job-ID), `invalid_days` |
| `core.handoff` | `target_instance` (Instanz-ID einer anderen, aktivierten Nicht-`core`-Aktion desselben Formulars) | Übergabe an eine Suite-/Connector-Aktion nur nach manueller Bestätigung: Die Zielaktion wird vom `OutboxPlanner` **nicht** automatisch eingeplant. Der Handoff-Job schreibt die Entry-Notiz „Übergabe ausstehend“; im Entry-Detail erscheint der Abschnitt „Ausstehende Übergaben“ mit Bestätigen-Button (Nonce, Rechte `read_entries` + `edit_entries` + `manage_integrations`). Erst dann wird der Ziel-Job `connector.action` mit dem regulären Idempotency-Key eingereiht (Audit `handoff_confirmed`). | `handoff_pending`, `handoff_target_invalid`, `handoff_target_missing`, `action_disabled` |

Testlauf (`test_mode`) aller Kernaktionen ist ohne Nebenwirkungen. Bewusst **nicht** vorhanden: automatische Zahlung, Ticket-/Gutscheinausgabe, Partnerfreigabe, Review-Veröffentlichung. Wird ein solcher Dienst per `core.handoff` angebunden, bleibt die Vertragspflicht des Adapters („pending/zur Prüfung“) bestehen.

Tests: `tests/integration/Workflow/CoreActionsTest.php`.

## 4. Native Mail bleibt Standard (P8-20, geändert durch F-12)

Admin-Mail und Bestätigung laufen als eigene Jobs (`mail.admin`, `mail.confirmation`) und werden immer vom Kern über `wp_mail()` versendet. Eine Übergabe einzelner Nachrichten an einen Workflow gibt es **nicht** (der frühere interne Filter `form_captain_mail_handoff` ist entfernt, F-12/D10). Dedupe über den Idempotency-Key des Mail-Jobs. Connector-Fehler beeinflussen die native Mail nicht.

## 5. Diagnose (P8-21)

| Situation | Code | Anzeige |
|---|---|---|
| Workflow/Aktion fehlt (Plugin inaktiv) | `action_not_found`, `connector_not_found` | Integrationen, Job-Liste, nächster Schritt; bereits pausierte Jobs bleiben `paused` („Connector fehlt“) |
| Connector deaktiviert | `connector_disabled` | Jobs `paused` (kein Versuch, kein Verfall), Fortsetzung bei Aktivierung; Integrationen, Eintragsdetail, Systemstatus (`queue.paused`) – siehe [`CONNECTORS.md`](CONNECTORS.md) §6a |
| Pro-Recht fehlt/abgelaufen | `entitlement_missing` | Editor (`available=false`), Job `dead` |
| Vertrag inkompatibel | `incompatible_contract` | Connector-Karte „Inkompatibel“ |
| Queue-Timeout | `queue_timeout` (Systemstatus, > 15 min) | Systemstatus, Site Health |
| Entry gelöscht | `entry_deleted` / `entry_anonymized` | Job-Liste |
| Consent widerrufen | `consent_withdrawn` – offene Jobs werden damit storniert, später eingereihte Jobs enden `dead` (`MailJobHandler`, `ConnectorRegistry::handle_job()`) | Job-Liste, Versandstatus im Entry, Badge „Einwilligung widerrufen am …“ |

Speicherung und Spamprüfung sind von Workflows unabhängig: Jobs entstehen erst nach dem Commit, Fehler ändern den Entry nicht.

Alle Codes mit Text und nächstem Schritt: `ConnectorRegistry::diagnostic_codes()`.

## 6. Abnahme (P8-22)

Abgedeckt durch `tests/integration/Contract/*` und `tests/integration/Privacy/*`: Export/Eraser (Löschen und Anonymisieren), Entry-Löschung vor Zustellung, Replay/Duplikat (Signierter Kontext, Idempotency-Key), unbekannte Payload-Felder (Fixtures `tests/fixtures/contract/v1`). **Offen:** Retention-Weitergabe an Fremdsysteme, Review-Löschweitergabe (benötigt Review-Captain-Adapter) und Multisite (in v1 nicht unterstützt).
