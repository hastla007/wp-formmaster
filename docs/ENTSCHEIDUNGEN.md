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
| 17 | Lizenz der Projekt-Connectoren BoatingSchool und LearnBoating | **Endgültig entschieden vom Inhaber (16.11.2026, zweite Bestätigung): Jeder Connector ist ein eigenes Produkt mit eigenem Präfix.** Eigenes, nicht verkauftes Projektprodukt auf dem Lizenzserver des Inhabers (eigener Produktschlüssel = Ordner = Textdomain, eigenes Präfix, eigene Katalogdatei `wplc-catalog.json`), separat installiert, Code im Projekt-Repository, ohne gültige Lizenz keine neue Übergabe (fail-closed; Entry bleibt, Auftrag `retry`/pausiert), Paket und Updates nur für das jeweilige Projekt. **Nicht Teil von Form Captain Pro, keine Form-Captain-Pro-Lizenz nötig.** Vorschläge: `boatingschool-form-connector` / `boatingschool_form.leads`, `learnboating-form-connector` / `learnboating_form.course_questions` (TODO P8-88, P8-89). *Überholte Zwischenfassung vom selben Tag:* „Die Connectoren sind in PRO enthalten“ (Lizenzauskunft von Form Captain Pro, Recht `form.*`, P8-90) gilt nicht. *Ursprüngliche Fassung (inhaltlich wieder gültig):* Eigenes Projektprodukt je Connector auf dem eigenen Lizenzserver (WP License Captain), nicht verkauft, ohne Kundennamen im Katalog; separat installiertes, proprietäres Plugin im Projekt-Repository; ohne gültige Lizenz keine neue Übergabe (fail-closed). Nicht Teil von Form Captain Pro, keine Form-Captain-Pro-Lizenz nötig. Muster wie `learnboating-voucher-connector` (captain-pro TODO Abschnitt 13). Ergänzt (Inhaber, 16.11.2026): gilt für **alle** Connectoren beider Projekte (Form, Voucher, künftige); Paket und Updates erhält nur das jeweilige Projekt; **jeder Connector ein eigenes Präfix** (ein Präfix = ein Produkt im Lizenzserver); Anmeldung am Lizenzserver wie bei den Suite-Produkten über eine Katalogdatei | konzept.md, TODO.md P8-88, P8-89 (P8-90 überholt) |
| 18 | Preisangabe der Pro-Tarife | Netto: 59/129/249 € **zzgl. USt.** (die Notiz „brutto“ in wp-license ist zu korrigieren) | an die wp-license-Sitzung gemeldet |
