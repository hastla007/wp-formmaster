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
| Formularwerte | `fc_entry_values` | Standard 12 Monate, wählbar 90 Tage/12 Monate/manuell | nur berechtigte Admin-Rollen; optional Connector-Aktionen nach Opt-in mit Feld-Freigabe |
| E-Mail-Benachrichtigungen | Mailserver des Kunden | außerhalb des Plugins | konfigurierte Empfänger |
| IP-Adresse | **nicht gespeichert**; nur kurzlebiger HMAC-Hash für Rate-Limit (Tagessalt) | max. 24 h | – |
| Einwilligungsnachweis | `consent_json` (Feld, Textversion/Revision, Hash, Zeitpunkt) | wie Eintrag | – |
| Widerruf | `consent_withdrawn_gmt` + Ereignis an Connectoren | wie Eintrag | Connectoren (nur IDs) |
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

## 3. Zu prüfende Texte

| ID | Ort | Text | Quelle |
|---|---|---|---|
| T-1 | Einwilligungsfeld der Vorlagen | „I agree that my details will be stored to answer my request.“ / „Ich bin einverstanden, dass meine Angaben zur Beantwortung meiner Anfrage gespeichert werden.“ | templates/forms/*.json, languages/form-captain-de_DE.po |
| T-2 | Vorschlag Datenschutzerklärung (WordPress-Datenschutzseite) | DS-T-70 | docs/spec/datenschutz.md, Texttabelle DS-T |
| T-3 | Admin-Texte zu Aufbewahrung, Löschung, Deinstallation | DS-T-01 … DS-T-40 | docs/spec/datenschutz.md, Texttabelle DS-T |
| T-4 | Hinweis zu Captcha-Anbietern (opt-in, Datenfluss vor Aktivierung) | Einstellungen → Spamschutz | src/Admin/SettingsPage.php |
| T-5 | Hinweis bei Eintragsimport (Rechtsgrundlage der Altdaten liegt beim Betreiber) | Import-Oberfläche | src/Import/ |
| T-6 | Pro-Shop: Laufzeit 12 Monate, Verlängerung, Widerruf, Preise 59/129/249 € zzgl. USt. | Shop/AGB (außerhalb dieses Repos) | konzept.md Abschnitt 3 und 8 |

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
| R-1 … R-7 | | | |
| T-1 … T-6 | | | |
| Gesamtfreigabe Free | | | |
| Gesamtfreigabe Pro-Verkauf | | | |
