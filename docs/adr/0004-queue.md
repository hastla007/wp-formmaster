# ADR 0004 – Queue/Worker (P0-13)

**Status:** vorgeschlagen, Umsetzung erfolgt auf dieser Basis; Bestätigung durch Produktverantwortliche offen.

**Optionen**

| Option | Pro | Contra |
|---|---|---|
| A: Action Scheduler (WooCommerce-Bibliothek) | erprobt, UI | ~1 MB Fremdcode, Versionskonflikte mit Woo/anderen Plugins, eigene Tabellen |
| B: **Eigene Outbox-Tabelle `fc_jobs` + WP-Cron** | volle Kontrolle über Idempotenz, Datenschutz, keine Abhängigkeit | eigener Code zu testen |
| C: Externer Worker (WP-CLI-Daemon) | zuverlässig | auf Shared Hosting nicht verfügbar |

**Entscheidung: B**, mit WP-CLI-Befehl `wp form-captain jobs run` als optionaler Betriebsweg (System-Cron) für C.

- Jobs werden in derselben Transaktion wie der Entry geschrieben (Outbox-Muster).
- Abarbeitung: Cron-Event `form_captain_process_jobs` (Intervall 1 min), zusätzlich nach erfolgreicher Submission ein nicht blockierender `spawn_cron()`-Anstoß.
- Claim: `UPDATE fc_jobs SET status='running', lock_token=%s, locked_until_gmt=… WHERE id=%d AND status IN ('pending','failed') AND run_after_gmt<=NOW AND (locked_until_gmt IS NULL OR locked_until_gmt<NOW)`; nur bei `rows_affected = 1` wird ausgeführt. Damit sind parallele Worker sicher.
- Globaler Lauf-Lock per `add_option('form_captain_worker_lock')` mit Ablauf 60 s verhindert überlappende Batches, ist aber nur Optimierung; Korrektheit kommt vom Zeilen-Claim.
- Deaktiviertes WP-Cron (`DISABLE_WP_CRON`): Systemstatus warnt, wenn der älteste fällige Job > 15 min wartet, und zeigt den WP-CLI-Befehl bzw. System-Cron-Aufruf. Zusätzlich verarbeitet ein Admin-Seitenaufruf (Form-Captain-Seiten) bis zu 5 fällige Jobs.
- Retry, Backoff, Dead-letter: siehe `docs/spec/zustaende.md`.
