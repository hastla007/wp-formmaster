# Produktentscheidungen – 16.11.2026

Getroffen von der Produktverantwortung nach Vorlage mit Empfehlungen. Diese Datei ist die verbindliche Referenz; abweichende ältere Angaben in Specs/Konzept sind entsprechend angepasst.

| # | Thema | Entscheidung | Umsetzung |
|---|---|---|---|
| 1 | Queue/Worker (ADR 0004) | Eigene Outbox-Tabelle + WP-Cron, Fallback ohne Cron, WP-CLI | ADR 0004 auf „angenommen“ |
| 2 | Standard des Privacy-Erasers | Vollständig löschen (anonymisieren bleibt als Admin-Option) | Default `privacy.erasure_mode = delete` |
| 3 | Interne Notizen im Auskunftsexport | Ja, mit Hinweis an Admins | Exporter enthält Notizen (keine Audit-Einträge); Hinweis am Notizfeld |
| 4 | Einwilligungswiderruf | Strukturierter Ablauf schon in v1 | Schema v2 (`consent_withdrawn_gmt`), ConsentService, Event `form_captain_consent_withdrawn_v1` |
| 5 | Spam-Löschfrist | 30 Tage | wie umgesetzt |
| 6 | Schonfrist bei verkürzter Aufbewahrung | 14 Tage mit Vorwarnung | wie umgesetzt |
| 7 | Antwort an Besucher bei Spam | Normale Erfolgsmeldung | wie umgesetzt |
| 8 | Deaktivierter Connector | Offene Aufträge pausieren, bei Reaktivierung fortsetzen | Job-Status `paused` |
| 9 | Pro-Preise zum Start | Solo 59 €/1 Site, Studio 129 €/5 Sites, Agency 249 €/25 Sites (jährlich, zzgl. USt.) | konzept.md, TODO.md |
| 10 | WordPress.org | Ja, nach externem Security-Review | docs/WORDPRESS-ORG.md |
| 11 | Affiliate-Captain-Adapter | Bauen (Partner bleibt `pending`, nie automatische Freigabe/Provision) | wartet auf Lesezugriff auf das Affiliate-Captain-Repository |
| 12 | Support | Free: WordPress.org-Forum; Pro: Ticket per E-Mail mit tarifabhängiger Antwortzeit | readme.txt, docs/BETRIEB.md |
| 13 | Gelöschte Daten nach Backup-Restore | Warnung im Systemstatus, Nacharbeit ist Betreiberpflicht; keine Löschliste | docs/BETRIEB.md |
| 14 | Importer | Formular-Import und Eintragsimport mit Oberfläche in v1 | Import-UI |
| 15 | Sicherheitskontakt | Eigene Adresse `security@…` | Adresse noch offen (Platzhalter `[SECURITY-KONTAKT]`) |
| 16 | Datenschutz-/Rechtsfreigabe | Später entscheiden; Prüfmappe vorbereiten | docs/RECHTSPRUEFUNG.md |
