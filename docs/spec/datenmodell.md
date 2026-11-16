# Datenmodell v1 (P0-04, P0-18)

Status: verbindlich für Schema-Version `2` (v1 + `fc_entries.consent_withdrawn_gmt`, siehe „Schema-Versionen“). Alle Tabellen tragen das WordPress-Präfix (`$wpdb->prefix`), Engine InnoDB, Zeichensatz `$wpdb->get_charset_collate()`. Alle Zeitstempel sind `DATETIME` in **UTC** (`gmdate('Y-m-d H:i:s')`), Spaltennamen enden auf `_gmt`.

Keine Fremdschlüssel-Constraints auf Datenbankebene (WordPress-`dbDelta` unterstützt sie nicht zuverlässig, MyISAM-Altinstallationen). Referenzielle Integrität wird in den Repositories durchgesetzt; jede Löschung läuft über den `EntryDeletionService` bzw. `FormRepository::delete()`.

## Tabellen

### `fc_forms`

| Spalte | Typ | Hinweis |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT PK | interne ID |
| `public_id` | CHAR(16) NOT NULL UNIQUE | zufällig, `[a-z0-9]`, in Shortcode/Block/REST verwendet |
| `title` | VARCHAR(190) NOT NULL | |
| `status` | VARCHAR(20) NOT NULL | `draft`, `published`, `paused`, `archived` |
| `schema_json` | LONGTEXT NOT NULL | aktueller Arbeitsstand (Entwurf) |
| `schema_version` | INT UNSIGNED NOT NULL | Version des JSON-Formats (`1`) |
| `revision` | INT UNSIGNED NOT NULL DEFAULT 1 | +1 bei jedem Speichern; optimistische Sperre |
| `published_version_id` | BIGINT UNSIGNED NULL | → `fc_form_versions.id` |
| `settings_json` | LONGTEXT NOT NULL | Benachrichtigung, Bestätigung, Darstellung, Retention-Override |
| `created_by` | BIGINT UNSIGNED NOT NULL | WP-User-ID |
| `created_gmt`, `updated_gmt` | DATETIME NOT NULL | |

Indizes: `UNIQUE public_id`, `KEY status (status)`, `KEY updated (updated_gmt)`.

### `fc_form_versions`

Unveränderliche Schnappschüsse; jede Veröffentlichung erzeugt eine Zeile. Entries verweisen darauf, damit historische Antworten lesbar bleiben (P2-16).

| Spalte | Typ |
|---|---|
| `id` | BIGINT UNSIGNED PK |
| `form_id` | BIGINT UNSIGNED NOT NULL |
| `version_no` | INT UNSIGNED NOT NULL (1, 2, …) |
| `schema_json` | LONGTEXT NOT NULL |
| `schema_hash` | CHAR(64) NOT NULL (SHA-256 des kanonischen JSON) |
| `created_gmt` | DATETIME NOT NULL |

Indizes: `UNIQUE form_version (form_id, version_no)`.

### `fc_entries`

| Spalte | Typ | Hinweis |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `form_id` | BIGINT UNSIGNED NOT NULL | |
| `form_version_id` | BIGINT UNSIGNED NOT NULL | Schema zum Zeitpunkt der Einsendung |
| `status` | VARCHAR(20) NOT NULL | `new`, `read`, `archived`, `spam`, `trash` |
| `previous_status` | VARCHAR(20) NULL | für Wiederherstellung aus Papierkorb |
| `request_key` | CHAR(64) NOT NULL UNIQUE | Idempotenz: SHA-256(form_id + Submission-Token-Nonce) |
| `spam_reason` | VARCHAR(40) NULL | `honeypot`, `too_fast`, `blocklist` |
| `source_url` | VARCHAR(2048) NULL | Seite der Einsendung, ohne Query-String |
| `source_post_id` | BIGINT UNSIGNED NULL | |
| `user_id` | BIGINT UNSIGNED NULL | eingeloggter Nutzer |
| `context_json` | TEXT NULL | validierter Suite-Kontext (nur Typ+ID) |
| `consent_json` | TEXT NULL | Consent-Feld-ID, Text-Hash, Version, Zeit |
| `created_gmt` | DATETIME NOT NULL | |
| `updated_gmt` | DATETIME NOT NULL | |
| `trashed_gmt` | DATETIME NULL | |
| `expires_gmt` | DATETIME NULL | Retention-Ablauf, NULL = manuell |
| `anonymized_gmt` | DATETIME NULL | gesetzt, wenn der Eraser im Modus `anonymize` lief |
| `consent_withdrawn_gmt` | DATETIME NULL | Schema v2: Zeitpunkt des erfassten Einwilligungswiderrufs (`ConsentService::withdraw()`); einmal gesetzt, nie überschrieben. Gesetzt ⇒ keine neuen Mail-/Connector-Jobs |

Indizes: `UNIQUE request_key`, `KEY form_status_created (form_id, status, created_gmt)`, `KEY status_created (status, created_gmt)`, `KEY expires (expires_gmt)`, `KEY trashed (trashed_gmt)`, `KEY consent_withdrawn (consent_withdrawn_gmt)` (v2, Listenfilter „Einwilligung widerrufen“).

Keine IP-Adresse, kein User-Agent.

### `fc_entry_values`

| Spalte | Typ |
|---|---|
| `id` | BIGINT UNSIGNED PK |
| `entry_id` | BIGINT UNSIGNED NOT NULL |
| `field_id` | VARCHAR(40) NOT NULL (stabile Feld-ID aus Schema) |
| `value` | LONGTEXT NOT NULL (Skalare als String; Mehrfachwerte als JSON-Array) |
| `is_multi` | TINYINT(1) NOT NULL DEFAULT 0 |

Indizes: `UNIQUE entry_field (entry_id, field_id)`, `KEY field_value (field_id, value(64))` (Privacy-Suche per E-Mail).

### `fc_entry_notes`

| Spalte | Typ |
|---|---|
| `id` | BIGINT UNSIGNED PK |
| `entry_id` | BIGINT UNSIGNED NOT NULL |
| `user_id` | BIGINT UNSIGNED NOT NULL |
| `type` | VARCHAR(20) NOT NULL (`note`, `audit`) |
| `message` | TEXT NOT NULL |
| `created_gmt` | DATETIME NOT NULL |

Audit-Einträge (Statuswechsel, Export, Retry) enthalten nur Aktionscode und Benutzer, keine Feldwerte. Index `KEY entry (entry_id, created_gmt)`.

### `fc_jobs` (Outbox/Queue)

| Spalte | Typ |
|---|---|
| `id` | BIGINT UNSIGNED PK |
| `type` | VARCHAR(60) NOT NULL (`mail.admin`, `mail.confirmation`, `connector.action`, `event.dispatch`, `cleanup.retention`) |
| `entry_id` | BIGINT UNSIGNED NULL |
| `form_id` | BIGINT UNSIGNED NULL |
| `idempotency_key` | VARCHAR(191) NOT NULL UNIQUE |
| `payload_json` | LONGTEXT NOT NULL (nur Referenzen/Konfiguration, **keine Feldwerte**; Werte werden beim Ausführen frisch geladen) |
| `status` | VARCHAR(20) NOT NULL |
| `attempts` | SMALLINT UNSIGNED NOT NULL DEFAULT 0 |
| `max_attempts` | SMALLINT UNSIGNED NOT NULL DEFAULT 8 |
| `run_after_gmt` | DATETIME NOT NULL |
| `locked_until_gmt` | DATETIME NULL |
| `lock_token` | CHAR(32) NULL |
| `last_error_code` | VARCHAR(60) NULL |
| `last_error_message` | VARCHAR(255) NULL (redigiert) |
| `result_json` | TEXT NULL (z. B. Remote-ID) |
| `created_gmt`, `updated_gmt`, `finished_gmt` | DATETIME |

Indizes: `UNIQUE idempotency_key`, `KEY runnable (status, run_after_gmt)`, `KEY entry (entry_id)`.

### `fc_files` (reserviert für Pro-Uploads)

`id`, `entry_id`, `field_id`, `storage_key` VARCHAR(191) (zufälliger Name), `original_name` VARCHAR(255), `mime` VARCHAR(100), `size` BIGINT, `sha256` CHAR(64), `status` (`quarantine`, `clean`, `rejected`, `deleted`), `created_gmt`, `expires_gmt`. Index `KEY entry (entry_id)`. Der Kern legt die Tabelle an und löscht referenzierte Dateien über den Filter `form_captain_delete_entry_files_v1`, damit Erasure und Retention auch ohne aktives Pro konsistent sind.

### `fc_rate_limits`

`bucket` CHAR(64) PK (HMAC aus Formular + IP + Tagessalt), `form_id`, `hits` INT, `window_start_gmt` DATETIME. Index `KEY window (window_start_gmt)`. Bereinigung stündlich.

### `fc_log`

`id`, `level` (`debug`, `info`, `warning`, `error`), `code` VARCHAR(60), `correlation_id` CHAR(32), `form_id`, `entry_id`, `job_id`, `message` VARCHAR(255) (redigiert), `created_gmt`. Index `KEY created (created_gmt)`, `KEY correlation (correlation_id)`. Aufbewahrung 30 Tage.

## Schema-Versionen (`Migrator::TARGET_VERSION`)

| Version | Schritt | Inhalt | Verify |
|---|---|---|---|
| 1 | `install_v1()` | alle Tabellen per `dbDelta()` | alle Tabellen aus `Tables::ALL` vorhanden |
| 2 | `migrate_v2()` | `fc_entries.consent_withdrawn_gmt DATETIME NULL` + `KEY consent_withdrawn` per `dbDelta()` (idempotent) | zusätzlich Spalte `consent_withdrawn_gmt` vorhanden |

`Schema::definitions()` beschreibt immer den aktuellen Stand; eine Neuinstallation (Schritte 1+2) und ein Upgrade von v1 ergeben dasselbe Schema (Test `tests/integration/Privacy/ConsentWithdrawalTest.php`). Downgrade-Schutz unverändert: `form_captain_db_version > TARGET_VERSION` → `schema_newer_than_plugin`, keine Änderung.

## Optionen (`wp_options`)

| Option | Inhalt | autoload |
|---|---|---|
| `form_captain_db_version` | installierte Schema-Version (int) | yes |
| `form_captain_migration_state` | `{target, step, started_gmt, error}` während laufender Migration | no |
| `form_captain_settings` | globale Einstellungen (Schema siehe `docs/spec/einstellungen.md`) | yes |
| `form_captain_secret` | 64-Byte-Zufallsschlüssel (hex) für HMAC; nie ausgegeben | no |
| `form_captain_connectors` | Opt-in-Zustand je Connector-Key | no |

## Integrität und Löschsemantik (P0-18)

- **Eindeutige Schlüssel:** `fc_forms.public_id`, `fc_entries.request_key`, `fc_entry_values(entry_id, field_id)`, `fc_jobs.idempotency_key`, `fc_form_versions(form_id, version_no)`.
- **Transaktionen:** Submission schreibt `fc_entries`, `fc_entry_values` und die Outbox-Jobs in **einer** Transaktion (`START TRANSACTION … COMMIT`). Ohne Transaktionsunterstützung (MyISAM): Reihenfolge Entry → Werte → Jobs; bei Fehler werden bereits geschriebene Zeilen kompensierend gelöscht; ein Integritätscheck im Systemstatus meldet Entries ohne Werte. Öffentliche Events (`form_captain_entry_created_v1`) werden erst **nach** Commit über den Job `event.dispatch` bzw. direkt nach `COMMIT` ausgelöst.
- **Soft-Delete:** Entry-Status `trash` + `trashed_gmt`; `trashed_gmt` wird auch beim Wechsel nach `spam` gesetzt (Beginn der 30-Tage-Löschfrist für Spam); Wiederherstellung innerhalb 30 Tagen (`previous_status`). Danach endgültige Löschung durch den Bereinigungsjob.
- **Einwilligungswiderruf:** `consent_withdrawn_gmt` setzen → offene `mail.*`-/`connector.*`-Jobs → `cancelled` (Code `consent_withdrawn`) → Audit-Notiz `consent_withdrawn_{source}` → Event `form_captain_consent_withdrawn_v1` → optional Anonymisierung oder Hard-Delete (Grund `consent_withdrawn`).
- **Hard-Delete Entry:** Reihenfolge: offene Jobs des Entries → `cancelled` (P4-18) → Dateien (`fc_files` + Filter) → `fc_entry_values` → `fc_entry_notes` → `fc_entries` → Event `form_captain_entry_deleted_v1` (nur IDs).
- **Formular löschen:** nur möglich, wenn der Nutzer `form_captain_manage_forms` **und** `form_captain_delete_entries` hat oder das Formular keine Entries hat. Sonst Archivieren. Formularlöschung löscht Entries nach obiger Regel batchweise.
- **Teilweiser DB-Ausfall:** Fehlgeschlagene Submission → Rollback, Antwort `storage_failed` (HTTP 503), Besucher sieht neutrale Meldung, Eingaben bleiben im Formular erhalten. Keine Mail ohne gespeicherten Entry.
- **Datenexport/-import** von Formularen enthält niemals Entries, Dateien oder Secrets.
