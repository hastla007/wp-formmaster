# Form-Workflowvertrag v1 (P8-17 … P8-22)

Der Workflowvertrag führt **keine zusätzlichen Events** ein. Alle Workflow-Zustände werden auf die vorhandenen, nach Commit gefeuerten Events aus [`spec/api-v1.md`](spec/api-v1.md) und auf die Outbox abgebildet. Neue Events kämen nur additiv in einer Minor-Version.

## 1. Abbildung der Workflow-Ereignisse (P8-17)

| Workflow-Ereignis | Vertrags-Event / Mechanismus | Hinweise |
|---|---|---|
| Formular veröffentlicht | `form_captain_form_updated_v1( $form, 'published' )` | Form-DTO mit `version` |
| Formular deaktiviert | `form_captain_form_updated_v1( $form, 'paused' \| 'unpublished' \| 'archived' )` | |
| Entry angenommen | `form_captain_entry_created_v1( $event )` | nur für Nicht-Spam; Payload mit `form_version`, `request_id`, Allowlist-Werten |
| Entry validiert | implizit in `entry_created_v1` | Es gibt nur validierte Entries; ungültige Einsendungen werden nicht gespeichert |
| Entry markiert (gelesen, archiviert, Spam) | `form_captain_entry_status_changed_v1( $id, $old, $new, $form_id )` | „Spam“ = `new === 'spam'` |
| Entry gelöscht / anonymisiert | `form_captain_entry_deleted_v1( $id, $form_id, $reason )` | `reason`: `manual`, `retention`, `erasure`, `anonymized`, `form_deleted` |
| Benachrichtigung fehlgeschlagen | `form_captain_notification_failed_v1( $entry_id, $form_id, $job_type, $error_code )` | nach Dead-letter eines `mail.*`-Jobs |
| Aktion ausgeführt | Outbox-Job `connector.action` (Status, `remote_id`) | kein Event; sichtbar unter Integrationen |

Stabile IDs: `entry_id`, `form_id`, `form_public_id`, `form_version` (Schema-Snapshot), `request_id` (Korrelation). Deprecation: `_deprecated_hook()`, `_v1` bleibt ≥ 12 Monate neben `_v2`.

## 2. Datenumfang (P8-18)

- Event-Werte nur über die Allowlist `form_captain_event_value_allowlist_v1` (Standard leer).
- Aktionen erhalten nur gemappte, validierte, nicht sensible Felder; sensible nur mit `allow_sensitive`; Datei-, Passwort-, Zahlungs-, Signatur- und HTML-Felder nie (`ConnectorRegistry::BLOCKED_FIELD_TYPES`).
- Consent wird als Referenz (`consent`-Feldwert `1`/`0`) übergeben, wenn gemappt; Nachweis bleibt im Kern.

## 3. Sichere Aktionen (P8-19)

Aktionen sind Connector-Code mit Outbox-Ausführung. Der Kern stellt keine Aktionen für Zahlung, Ticket-/Gutscheinausgabe, Partnerfreigabe oder Review-Veröffentlichung bereit. Adapter, die solche Systeme ansprechen, müssen Ergebnisse als „pending/zur Prüfung“ anlegen (Vertragspflicht der Adapter).

## 4. Native Mail bleibt Standard (P8-20)

Admin-Mail und Bestätigung laufen als eigene Jobs (`mail.admin`, `mail.confirmation`). Eine Erweiterung kann eine einzelne Nachricht explizit übernehmen über den internen Filter `form_captain_mail_handoff` (Rückgabe `'handled'`); jede andere Rückgabe → Fallback `wp_mail()`. Dedupe über den Idempotency-Key des Mail-Jobs. Connector-Fehler beeinflussen die native Mail nicht.

## 5. Diagnose (P8-21)

| Situation | Code | Anzeige |
|---|---|---|
| Workflow/Aktion fehlt (Plugin inaktiv) | `action_not_found`, `connector_not_found` | Integrationen, Job-Liste, nächster Schritt |
| Pro-Recht fehlt/abgelaufen | `entitlement_missing` | Editor (`available=false`), Job `dead` |
| Vertrag inkompatibel | `incompatible_contract` | Connector-Karte „Inkompatibel“ |
| Queue-Timeout | `queue_timeout` (Systemstatus, > 15 min) | Systemstatus, Site Health |
| Entry gelöscht | `entry_deleted` / `entry_anonymized` | Job-Liste |
| Consent widerrufen | `consent_withdrawn` (reserviert, v1 ohne eigenen Ablauf) | – |

Speicherung und Spamprüfung sind von Workflows unabhängig: Jobs entstehen erst nach dem Commit, Fehler ändern den Entry nicht.

Alle Codes mit Text und nächstem Schritt: `ConnectorRegistry::diagnostic_codes()`.

## 6. Abnahme (P8-22)

Abgedeckt durch `tests/integration/Contract/*` und `tests/integration/Privacy/*`: Export/Eraser (Löschen und Anonymisieren), Entry-Löschung vor Zustellung, Replay/Duplikat (Signierter Kontext, Idempotency-Key), unbekannte Payload-Felder (Fixtures `tests/fixtures/contract/v1`). **Offen:** Retention-Weitergabe an Fremdsysteme, Review-Löschweitergabe (benötigt Review-Captain-Adapter) und Multisite (in v1 nicht unterstützt).
