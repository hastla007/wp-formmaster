# Prüfmappe Datenschutz und Recht (P9-10)

**Zweck:** Grundlage für die fachliche/rechtliche Freigabe vor dem Verkauf von Form Captain Pro und der Veröffentlichung von Form Captain Free. **Wer prüft und wann, ist noch nicht entschieden** (docs/ENTSCHEIDUNGEN.md #16). Diese Mappe enthält alles, was eine prüfende Person (Anwalt/Datenschutzbeauftragte) braucht; sie ersetzt keine Rechtsberatung.

## 1. Rollenverteilung

| Rolle | Wer | Begründung |
|---|---|---|
| Verantwortlicher für Formulardaten | Websitebetreiber (Kunde) | Form Captain speichert ausschließlich in der WordPress-Datenbank des Kunden |
| Hersteller (Captain Suite) | kein Empfänger von Formulardaten | Free sendet nichts an den Hersteller; Pro kontaktiert nur den Lizenzserver (Lizenzschlüssel, Domain, Plugin-Version – keine Formulardaten) |
| Auftragsverarbeitung | nur, wenn der Hersteller Support mit Zugriff auf Kundendaten leistet | Supportprozess sieht Diagnoseberichte **ohne** PII vor (docs/BETRIEB.md 2.2/2.5) |

**Prüffrage R-1:** Ist für Pro-Support ohne Datenzugriff ein AVV nötig? Ist für den Lizenzserver-Kontakt (Domain/IP des Servers) ein Hinweis in Shop-AGB/Datenschutzerklärung nötig?

## 2. Datenflüsse

Vollständige Darstellung: [docs/spec/datenschutz.md](spec/datenschutz.md) (Mermaid-Diagramm, Abschnitt 1) und [docs/spec/bedrohungsmodell.md](spec/bedrohungsmodell.md).

| Datenart | Speicherort | Dauer | Empfänger |
|---|---|---|---|
| Formularwerte | `fc_entry_values` | neue Installation: bis zum manuellen Löschen (S9, Site-Health-Hinweis); bestehende Installationen behalten ihren Wert (frühere Vorgabe 12 Monate); Länderprofile schlagen 12 Monate vor (E6); wählbar 1 Tag bis 30 Jahre oder manuell | nur berechtigte Admin-Rollen; optional Connector-Aktionen nach Opt-in mit Feld-Freigabe |
| E-Mail-Benachrichtigungen | Mailserver des Kunden | außerhalb des Plugins | konfigurierte Empfänger |
| IP-Adresse | **nicht gespeichert**; nur kurzlebiger HMAC-Hash für Rate-Limit (Tagessalt) | max. 24 h | – |
| Einwilligungsnachweis | `consent_json` (Feld, Textversion/Revision, Hash, Zeitpunkt) | wie Eintrag | – |
| Widerruf | `consent_withdrawn_gmt` + Ereignis an Connectoren | wie Eintrag | Connectoren (nur IDs) |
| Double-Opt-in (je Formular, Free) | Status `pending`/`confirmed`, Bestätigungszeitpunkt `confirmed_gmt` (keine IP), nur Hash des Link-Tokens | Link 48 h, unbestätigte Einsendung 7 Tage (je Formular 1–720 h bzw. 1–90 Tage) | Bestätigungsmail nur an das gewählte E-Mail-Feld; Benachrichtigungen/Connectoren erst nach Bestätigung |
| Spam | Spam-Ordner | 30 Tage | – |
| Papierkorb | Status `trash` | 30 Tage | – |
| Logs | `fc_log`, redigiert (keine Werte, E-Mails, IPs, Tokens) | 30 Tage | – |

**Zusätzlich mit Form Captain Pro** (captain-pro, `addons/form-captain-pro`, Doku `docs/FORM-PRO.md`; Stand 16.11.2026):

| Datenart | Speicherort | Dauer | Empfänger |
|---|---|---|---|
| Hochgeladene Dateien (`form.uploads`) | private Ablage `uploads/form-captain-pro-private/` oder Ordner außerhalb des Web-Roots, Tabelle `{prefix}fcp_files`; Eintrag speichert nur eine Referenz | wie der Eintrag; Löschen, Erasure, Anonymisieren und Aufbewahrungsende löschen die Datei; Uploads nicht gespeicherter Einsendungen nach 1 Tag | nur berechtigte Admin-Rollen per Download mit Nonce; in Mails und CSV nur Name und Größe; optional ein Virenscanner über Filter (Datenfluss hängt vom Scanner ab) |
| Webhook-Zustellung (`form.webhooks`) | Outbox des Kerns | wie Connector-Aufträge | vom Betreiber eingetragene HTTPS-Adresse mit den gemappten Feldern (kann personenbezogene Daten enthalten; Drittland möglich) |
| Entwürfe mehrstufiger Formulare (`form.multi_step`) | Transient, Schlüssel = Hash des Tokens | 7 Tage, beim Absenden gelöscht | wer den Fortsetzungslink hat; sensible, Datei-, versteckte und Einwilligungsfelder nie im Entwurf |
| Ratenbegrenzung der Pro-Routen | Transient mit gesalzenem Hash der IP und Stunde | 1 Stunde | – |
| PDF eines Eintrags (`form.pdf`) | nicht gespeichert, auf Abruf erzeugt | – | berechtigte Admin-Rolle; sensible Felder nur mit Exportrecht |
| Lizenzprüfung | Lizenzserver des Herstellers | – | URL der Website, Installations-ID, Lizenzschlüssel (bei Aktivierung), Versionen, Art der Website; nie Formulardaten |

**Prüffrage R-7:** Reicht für Webhooks der Hinweis im readme (Betreiber wählt Ziel und Felder, AVV/Drittlandtransfer liegt beim Betreiber), oder braucht das Add-on einen Hinweis direkt im Webhook-Dialog?
**Prüffrage R-8:** Ist für Entwürfe (Fortsetzungslink, 7 Tage) ein eigener Textbaustein für die Datenschutzerklärung nötig? Vorschlag liegt im readme des Add-ons („Drafts of multi-step forms …“).
**Prüffrage R-9:** BoatingSchool-Leads werden 180 Tage gespeichert und dann automatisch gelöscht (Entscheidung 20). Ist die Frist für B2B-Interessenten angemessen? Support-Leads enthalten zusätzlich Produkt und Kundennummer des angemeldeten WooCommerce-Kunden.

## 3. Zu prüfende Texte

| ID | Ort | Text | Quelle |
|---|---|---|---|
| T-1 | Einwilligungsfeld der Vorlagen | „I agree that my details will be stored to answer my request.“ / „Ich bin einverstanden, dass meine Angaben zur Beantwortung meiner Anfrage gespeichert werden.“ | templates/forms/*.json, languages/form-captain-de_DE.po |
| T-2 | Vorschlag Datenschutzerklärung (WordPress-Datenschutzseite) | DS-T-70 | docs/spec/datenschutz.md, Texttabelle DS-T |
| T-3 | Admin-Texte zu Aufbewahrung, Löschung, Deinstallation | DS-T-01 … DS-T-40 | docs/spec/datenschutz.md, Texttabelle DS-T |
| T-4 | Hinweis zu Captcha-Anbietern (opt-in, Datenfluss vor Aktivierung) | Einstellungen → Spamschutz | src/Admin/SettingsPage.php |
| T-5 | Hinweis bei Eintragsimport (Rechtsgrundlage der Altdaten liegt beim Betreiber) | Import-Oberfläche | src/Import/ |
| T-6 | Pro-Shop: Laufzeit 12 Monate, Verlängerung, Widerruf, Preise 59/129/249 € zzgl. USt. | Shop/AGB (außerhalb dieses Repos) | konzept.md Abschnitt 3 und 8 |
| T-7 | Double-Opt-in: Bestätigungsmail („Please confirm your submission …“, Gültigkeit, „If you did not send this form …“), Hinweis bis zur Bestätigung, Bestätigungsseite | Formular → Einstellungen → Double-Opt-in (Texte je Formular änderbar) | src/Mail/MailBuilder.php (`double_opt_in()`), src/Service/DoubleOptIn.php |
| T-8 | Vorgabetext neuer Einwilligungsfelder im Editor: „I agree that my details will be stored to process my request.“ (weicht von T-1 ab) | Formular-Editor, Feld „Einwilligung“ | assets/src/editor.js, languages/form-captain-de_DE.po |
| T-9 | Hinweise der Länderprofile, u. a. CH: „the Federal Act on Data Protection (FADP) applies in addition to or instead of the GDPR“ | Einstellungen → Allgemein → Länderprofil | src/Settings/CountryPresets.php |

### Prüffragen Länder, Einwilligung und Fristen (Prüfphase SET-FC-P3, 23.11.2026)

**Prüffrage R-10 (Länderprofile, S8):** Sind die Vorschläge je Profil vertretbar – DE, AT, CH: Aufbewahrung 12 Monate für Kontaktanfragen (E6), Papierkorb/Spam 30 Tage; EU und International: dieselben neutralen Werte? Nach der Prüfung trägt der Inhaber in `src/Settings/CountryPresets.php` je Profil `review = reviewed` und je Wert das Prüfdatum unter `reviewed` ein (bis dahin zeigt die Vorschau „noch nicht geprüft“). Offen auch: Soll ein Profil Fristen wie Schonfrist (`privacy.grace_days`) oder Protokolldauer (`privacy.log_days`) vorschlagen?
**Prüffrage R-11 (Double-Opt-in, E2):** Reichen als Nachweis Bestätigungszeitpunkt und Formularversion ohne IP-Adresse? Sind die Vorgaben Link 48 Stunden und Löschung unbestätigter Einsendungen nach 7 Tagen angemessen? Für welche Zwecke (z. B. Newsletter-Anmeldung über Connectoren) sollte die Anleitung Double-Opt-in empfehlen?
**Prüffrage R-12 (Datenschutzhinweis, T-2):** Der Textvorschlag DS-T-70 erwähnt weder Double-Opt-in (Bestätigungsmail, Löschung unbestätigter Einsendungen) noch den eingebauten Proof-of-Work-Schutz; bei „manuell“ steht dort „[retention period]“. Braucht es dafür eigene Bausteine?
**Prüffrage R-13 (Proof-of-Work, E1):** Der Hilfetext sagt „no cookies, no external service, no personal data“. Ist diese Aussage für die Datenschutzerklärung so verwendbar (technisch zu bestätigen in SET-FC-P4), oder braucht es trotzdem einen Hinweis?
**Prüffrage R-14 (Pro, WooCommerce-Fragebögen):** Gelten Erinnerungsmails an Käufer (Standard: Erinnerung nach Tagen, Anzahl einstellbar) als Werbung, und braucht es dafür eine Einwilligung oder einen Abmeldelink? Der Hilfetext weist bereits auf die Prüfung hin.
**Prüffrage R-15 (Altersgrenze, SET-FC-30):** Die Altersprüfung am Datumsfeld (`validation.min_age`) hat keine Vorgabe und wird von keinem Länderprofil gesetzt. Sollen die Profile 16 bzw. 18 Jahre vorschlagen (Einwilligungsalter je Land)?

## 4. Getroffene Produktentscheidungen mit Rechtsbezug

Siehe [docs/ENTSCHEIDUNGEN.md](ENTSCHEIDUNGEN.md) #2–#7, #13, #14. Zu bestätigen:

- **R-2:** Genügt die Option „anonymisieren“ des Erasers als Erfüllung eines Löschanspruchs (Standard ist vollständiges Löschen)?
- **R-3:** Ist die Einbeziehung interner Notizen in den Auskunftsexport korrekt umgesetzt (Audit-Einträge ausgeschlossen)?
- **R-4:** Reichen 30 Tage für Spam und Papierkorb, 14 Tage Schonfrist bei verkürzter Aufbewahrung?
- **R-5:** Ist die Antwort „Erfolg“ bei erkanntem Spam unbedenklich (keine Täuschung echter Nutzer im Fehlalarmfall)?
- **R-6:** Reicht nach Backup-Restore eine Warnung mit Betreiberpflicht (keine automatische Nachlöschung)?
- **R-7:** Einwilligungswiderruf: Sind die Optionen behalten/anonymisieren/löschen und die Benachrichtigung der Connectoren ausreichend?

## 5. Technische Nachweise

- Automatisierte Tests zu Export, Löschung, Retention, Widerruf, Logs ohne PII: `tests/integration/Privacy/`, `tests/integration/Entries/DataLifecycleTest.php`, `tests/integration/Security/LogPrivacyTest.php`.
- Sicherheitsreview: [docs/SECURITY-REVIEW.md](SECURITY-REVIEW.md).
- Deinstallation behält Daten standardmäßig; vollständige Löschung nur nach ausdrücklicher Einstellung und Bestätigungswort (verifiziert aus dem Release-ZIP).

## 6. Freigabeprotokoll

| Punkt | Ergebnis | Prüfer/in | Datum |
|---|---|---|---|
| R-1 … R-15 | | | |
| T-1 … T-9 | | | |
| Gesamtfreigabe Free | | | |
| Gesamtfreigabe Pro-Verkauf | | | |
