# Leistung und Last-/Missbrauchsgrenzen (P6-05)

Automatisiert abgedeckt durch `tests/integration/Load/LoadTest.php`. Messwerte ausgeben:

```bash
FC_LOAD_REPORT=1 vendor/bin/phpunit tests/integration/Load
```

Die Tests prüfen mit großzügigen Schwellen (Größenordnungen, nicht Prozente), damit sie auch auf langsamen CI-Runnern stabil sind. Alle Messungen laufen innerhalb der Test-Transaktion der WordPress-Testsuite (kein Commit, kein Netzwerk, `wp_mail` abgefangen).

## Referenzmessung

Umgebung: PHP 8.3.6 (CLI, ohne OPcache), MariaDB 10.11.14, WordPress-Testsuite, Single Site, Messung vom 16.11.2026.

| Szenario | Gemessen | Grenze im Test |
|---|---|---|
| Formular mit 100 Feldern anlegen / speichern / veröffentlichen | 5,1 / 4,3 / 7,1 ms | je < 2 s |
| 100 Felder rendern (HTML 44 KB) | 4,9 ms | < 2 s |
| Einsendung mit 100 Feldern (Payload 2,8 KB) | 15,3 ms | < 2 s, Payload < `request_bytes` (64 KB) |
| 101. Feld | abgelehnt mit `too_many_fields` | – |
| 2.000 Entries über Repositories anlegen (je 3 Werte) | 870 ms | < 60 s |
| CSV-Export 1.400 aktive Entries (400 KB) | 60 ms, **Spitzenspeicher +2,8 MB** | < 30 s, < 16 MB |
| Entry-Liste Seite 1 / Seite 50 (20 je Seite, inkl. COUNT) | 4,1 / 4,8 ms | < 1 s |
| 50 Einsendungen in Folge von einer IP | 33 ms; 5 angenommen, 45 × `rate_limited` | genau 5 (Einstellung `spam.rate_limit`) |
| Spamwelle: 100 Honeypot-Treffer | 188 ms; 0 Jobs, 0 `entry_created_v1` | 0 Jobs |
| Queue mit 500 Jobs ohne Cron: ein Worker-Lauf | 13 ms, 20 Jobs | = `job_batch` (20), < `job_batch_seconds` |
| Admin-Fallback ohne Cron | 5 Jobs je Seitenaufruf | = 5 |
| Queue leeren mit `job_batch` = 7 | 67 Läufe, 317 ms | nie mehr als ein Batch je Lauf |

## Indexnutzung (EXPLAIN)

| Abfrage | Plan |
|---|---|
| Entry-Liste (Formular + Status, sortiert nach Datum) | `range` über `form_status_created` |
| Zählung (Formular + Status) | `ref` über `form_status_created` |
| Export-Batch (Keyset `id > n`) | `ref` über `form_status_created` |
| Werte einer Listenseite | `range` über `entry_field` |
| Fällige Jobs | `range` über `runnable` |
| Ablaufende Entries (Retention) | `range` über `expires` |

Der Test bricht ab, wenn eine dieser Abfragen zum Full-Table-Scan (`type=ALL`) wird oder der erwartete Index nicht mehr unter `possible_keys` steht.

## Einordnung

- **CSV-Export** streamt in Batches (`csv_batch` = 500) direkt in den Ausgabestrom; der Speicherbedarf hängt von der Batchgröße ab, nicht von der Gesamtzahl. Harte Obergrenze: `CsvExporter::MAX_ROWS`, darüber `export_too_large` (Zeitraum eingrenzen).
- **Rate-Limits:** pro Formular und IP (`spam.rate_limit`, Standard 5 je Zeitfenster `spam.window_minutes` = 10 Minuten), global pro IP über alle Formulare (`spam.global_per_ip`, Standard 20; Filter `form_captain_limits_v1` gewinnt) und pro Formular gesamt (`spam.form_total`, Standard 300 je Fenster). Verteilte Einsendungen von vielen IPs werden angenommen, bis die Formulargrenze greift.
- **Spam** wird gespeichert (Status „Spam“, Grund `honeypot`), aber erzeugt nie Outbox-Jobs (keine Mails, keine Aktionen) und kein öffentliches Event.
- **Ohne funktionierenden WP-Cron** bleibt die Queue erhalten; der Admin-Fallback verarbeitet bei Aufrufen von Form-Captain-Seiten höchstens 5 Jobs. Für hohe Last einen System-Cron mit `wp form-captain jobs run` einrichten.
- Technische Grenzen lassen sich über `form_captain_limits_v1` nur **absenken**; höhere Werte werden auf die Obergrenzen gekappt (z. B. bleibt `job_batch_seconds` bei 20 s). Ausnahme: Textlängen, Token-Dauer und die globale Grenze je IP sind Einstellungen; der Filter darf sie bis zur harten Obergrenze auch anheben (siehe `docs/spec/budgets.md`).

## Nicht automatisiert

Echte parallele HTTP-Requests (mehrere PHP-Prozesse), Messungen mit Objekt-Cache und große Produktivdatenbanken (> 100.000 Entries) sind manuell auf einer Staging-Umgebung zu prüfen, z. B. mit `ab`/`k6` gegen `POST /wp-json/form-captain/v1/forms/{id}/submissions` und `wp form-captain jobs run`.
