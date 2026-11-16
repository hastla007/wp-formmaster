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
| 11 | Affiliate-Captain-Adapter | Bauen (Partner bleibt `pending`, nie automatische Freigabe/Provision) | an die Affiliate-Captain-Sitzung übergeben (Schnittstelle `wpac_submit_application` + Connector im Affiliate-Repo); Review durch Form Captain |
| 12 | Support | Free: WordPress.org-Forum; Pro: Ticket per E-Mail mit tarifabhängiger Antwortzeit | readme.txt, docs/BETRIEB.md |
| 13 | Gelöschte Daten nach Backup-Restore | Warnung im Systemstatus, Nacharbeit ist Betreiberpflicht; keine Löschliste | docs/BETRIEB.md |
| 14 | Importer | Formular-Import und Eintragsimport mit Oberfläche in v1 | Import-UI |
| 15 | Sicherheitskontakt | Eigene Adresse `security@…` | Adresse noch offen (Platzhalter `[SECURITY-KONTAKT]`) |
| 16 | Datenschutz-/Rechtsfreigabe | Später entscheiden; Prüfmappe vorbereiten | docs/RECHTSPRUEFUNG.md |
| 17 | Lizenz der Projekt-Connectoren BoatingSchool und LearnBoating | **Geändert vom Inhaber (16.11.2026): Die Connectoren sind in PRO enthalten.** Sie bleiben separat installierte Plugins, laufen aber mit der Form-Captain-Pro-Lizenz (fail-closed) statt mit einem eigenen Projektprodukt. Der Connector fragt das installierte Form Captain Pro, ob dessen Lizenz sein Recht gewährt (Lizenzauskunft des Kernels für Begleit-Plugins, captain-pro TODO 13 K-01, F-06 in wp-workflowmaster). Fragen an den Inhaber: O1 Ort des Codes (Projekt-Repository oder captain-pro), O2 nur Projektlizenzen oder jede Pro-Lizenz, O3 Update-Weg. Antworten des Inhabers (16.11.2026): O1 (a): Der Code bleibt im Projekt-Repository. O2 (a): Das Recht gilt nur für die Lizenzen der eigenen Projekte und steht nicht in den öffentlichen Tarifen. O3 (b): Updates kommen über den Lizenzserver als weiteres Paket der Pro-Lizenz, über den Updater des Pro-Add-ons (captain-pro K-04, wp-license PC-3). *Bisherige Fassung, überholt:* Eigenes Projektprodukt je Connector auf dem eigenen Lizenzserver (WP License Captain), nicht verkauft, ohne Kundennamen im Katalog; separat installiertes, proprietäres Plugin im Projekt-Repository; ohne gültige Lizenz keine neue Übergabe (fail-closed). Nicht Teil von Form Captain Pro, keine Form-Captain-Pro-Lizenz nötig. Muster wie `learnboating-voucher-connector` (captain-pro TODO Abschnitt 13). Ergänzt (Inhaber, 16.11.2026): gilt für **alle** Connectoren beider Projekte (Form, Voucher, künftige); Paket und Updates erhält nur das jeweilige Projekt; **jeder Connector ein eigenes Präfix** (ein Präfix = ein Produkt im Lizenzserver); Anmeldung am Lizenzserver wie bei den Suite-Produkten über eine Katalogdatei | konzept.md, TODO.md P8-90 (ersetzt P8-88/P8-89) |
| 18 | Preisangabe der Pro-Tarife | Netto: 59/129/249 € **zzgl. USt.** (die Notiz „brutto“ in wp-license ist zu korrigieren) | an die wp-license-Sitzung gemeldet |
