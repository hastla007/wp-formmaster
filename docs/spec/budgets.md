# Technische Budgets (P0-19, P3-17)

Alle Werte sind Konstanten in `FormCaptain\Support\Limits` und über den Filter `form_captain_limits_v1` **nur verkleinerbar** (größere Werte werden auf das Maximum gekappt).

**Ausnahme Geschäftsgrenzen (SET-FC-34):** `text_max`, `textarea_max`, `token_ttl` und `rate_global_per_ip` kommen aus den Einstellungen (`general.text_max`, `general.textarea_max`, `spam.token_minutes`, `spam.global_per_ip`). Der Filter bekommt diese Werte und darf sie heben oder senken – der Filter gewinnt –, aber nie über die harte Obergrenze `Limits::SETTABLE` (5000 / 50 000 Zeichen, 24 h, 1000). `request_bytes` wächst mit `textarea_max` mit (4 Byte je Zeichen + 16 KB, höchstens 256 KB). Die Log-Aufbewahrung ist kein Budget mehr, sondern die Einstellung `privacy.log_days` (Filter `form_captain_log_days_v1`).

| Budget | Default | Max |
|---|---|---|
| Request-Body öffentliche Submission | 64 KB | 256 KB |
| Felder je Formular | 100 | 100 |
| Optionen je Feld | 200 | 200 |
| Schema-JSON | 256 KB | 256 KB |
| Länge Feldwert text/textarea | 190 / 5000 | 1000 / 10000 (Einstellung, hart 5000 / 50 000) |
| Verschachtelungstiefe Payload | 2 (Feld → Array von Skalaren) | 2 |
| Rate-Limit je Formular + IP-Hash | 5 Einsendungen / 10 min | konfigurierbar 1–100 |
| Rate-Limit global je IP-Hash | 20 / 10 min | Einstellung 1–1000 |
| Mindest-Ausfüllzeit | 3 s | 0–60 s |
| Submission-Token gültig | 2 h | Einstellung 5 min – 24 h |
| Job-Batch je Worker-Lauf | 20 Jobs oder 20 s | – |
| Job-Lock | 5 min | – |
| Max. Versuche | 8 | 20 |
| Mail-/Connector-Timeout | 10 s | 30 s |
| Bereinigungsbatch | 200 Entries / Lauf | – |
| CSV-Export | Streaming in 500er-Batches, kein Speichern auf Disk | – |
| Log-Aufbewahrung | 30 Tage | Einstellung 1–365 Tage (`privacy.log_days`) |

**CSV-Eintragsimport (Admin-Oberfläche)** – Klassenkonstanten in `FormCaptain\Import\EntryImportService`, nicht per Filter änderbar (das Entwickler-API `ImportService::import_entries()` erlaubt 20 MB / 50 000 Zeilen, `GenericCsv::MAX_BYTES`/`MAX_ROWS`):

| Budget | Wert | Konstante |
|---|---|---|
| Dateigröße Upload | 5 MB | `MAX_BYTES` |
| Datenzeilen je Datei (ohne Kopfzeile) | 10 000 | `MAX_ROWS` |
| Zeilen je Import-Schritt (admin-post) | 500 | `BATCH` (= `GenericCsv::BATCH`) |
| Lebensdauer Upload-Datei + Status-Transient | 1 h | `FILE_TTL` |
| Vorschau | 5 Zeilen, Werte auf 40 Zeichen gekürzt | `PREVIEW_ROWS`, `PREVIEW_CHARS` |
| Rücknahme eines Importlaufs möglich | 7 Tage | `REVERT_DAYS` |
| Importprotokoll (`form_captain_entry_import_runs`) | 30 Tage, max. 50 Läufe, je Lauf max. 200 Zeilenbefunde | – |

**Datenbanken:** MySQL 9.7+ und MariaDB 12.3+ (InnoDB, Entscheidung 21; CI-Matrix `mysql:9.7`/`mariadb:12.3`). Ältere Server werden nicht getestet und nicht gesperrt: Systemstatus und Website-Zustand warnen (Filter `form_captain_minimum_db_versions_v1`); MyISAM wird erkannt und im Systemstatus gewarnt.
