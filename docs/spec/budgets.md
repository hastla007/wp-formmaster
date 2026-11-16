# Technische Budgets (P0-19, P3-17)

Alle Werte sind Konstanten in `FormCaptain\Support\Limits` und über den Filter `form_captain_limits_v1` **nur verkleinerbar** (größere Werte werden auf das Maximum gekappt).

| Budget | Default | Max |
|---|---|---|
| Request-Body öffentliche Submission | 64 KB | 256 KB |
| Felder je Formular | 100 | 100 |
| Optionen je Feld | 200 | 200 |
| Schema-JSON | 256 KB | 256 KB |
| Länge Feldwert text/textarea | 190 / 5000 | 1000 / 10000 |
| Verschachtelungstiefe Payload | 2 (Feld → Array von Skalaren) | 2 |
| Rate-Limit je Formular + IP-Hash | 5 Einsendungen / 10 min | konfigurierbar 1–100 |
| Rate-Limit global je IP-Hash | 20 / 10 min | – |
| Mindest-Ausfüllzeit | 3 s | 0–60 s |
| Submission-Token gültig | 2 h | 24 h |
| Job-Batch je Worker-Lauf | 20 Jobs oder 20 s | – |
| Job-Lock | 5 min | – |
| Max. Versuche | 8 | 20 |
| Mail-/Connector-Timeout | 10 s | 30 s |
| Bereinigungsbatch | 200 Entries / Lauf | – |
| CSV-Export | Streaming in 500er-Batches, kein Speichern auf Disk | – |
| Log-Aufbewahrung | 30 Tage | – |

**Datenbanken:** MySQL 8.0+ und MariaDB 10.6+ (InnoDB). Ältere Versionen werden nicht getestet; MyISAM wird erkannt und im Systemstatus gewarnt.
