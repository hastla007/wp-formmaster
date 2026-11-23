# Einträge, Export, E-Mail und Aufbewahrung

Dieses Kapitel beschreibt, wie Sie mit Einsendungen arbeiten, wie E-Mails zugestellt werden und wie lange Form Captain Daten aufbewahrt.

## Einträge verwalten

**Form Captain → Einträge** zeigt alle Einsendungen. Sichtbar nur für Rollen mit dem Recht *Einträge lesen* (standardmäßig nur Administratoren).

- **Liste:** Name und E-Mail (aus dem ersten Text- bzw. E-Mail-Feld, nie aus sensiblen Feldern), Vorgangsnummer (falls unter *Einstellungen → Allgemein* eingeschaltet; Spam bekommt keine), Formular, Eingangszeit in der Zeitzone der Website, Status.
- **Reiter:** Alle (ohne Spam/Papierkorb), Neu, Gelesen, Archiviert, Spam, Papierkorb, *Läuft bald ab* (automatische Löschung in den nächsten 14 Tagen).
- **Filter:** Formular, Zeitraum (von/bis), Einwilligung (widerrufen / nicht widerrufen), Volltextsuche in den Antworten und nach der genauen Vorgangsnummer, Seitenweise Anzeige (20 je Seite). Einträge mit widerrufener Einwilligung tragen in der Statusspalte das Badge „Einwilligung widerrufen am …“.
- **Sammelaktionen:** Als gelesen markieren, Archivieren, Als Spam markieren, Kein Spam, In den Papierkorb, Wiederherstellen, Endgültig löschen. Jede Aktion prüft je Eintrag das passende Recht; nicht erlaubte oder nicht mehr vorhandene Einträge werden übersprungen und gezählt.

### Detailansicht

Ein Klick auf einen Eintrag öffnet die Detailansicht. Ein **neuer** Eintrag wird dabei automatisch auf **gelesen** gesetzt.

- **Antworten** mit den Feldbezeichnungen der Formularversion, mit der eingesendet wurde. Felder, die später aus dem Formular entfernt wurden, sind mit „Feld entfernt“ markiert; sensible Felder mit „Sensibel“.
- **Details:** Formular und Version, Eingang, letzte Änderung, Status, Spamgrund, Quellseite (ohne Query-String), eingeloggter Benutzer, verifizierter Suite-Kontext.
- **Einwilligungsnachweis:** Zeitpunkt, Text-Revision und der damals gültige Wortlaut.
- **Versandstatus:** Admin-Benachrichtigung, Bestätigung an die einsendende Person und Integrationen mit Status (*In Warteschlange, Wird gesendet, Gesendet, Fehlgeschlagen – neuer Versuch folgt, Endgültig fehlgeschlagen, Abgebrochen*), Anzahl Versuche und Fehlercode. „Erneut senden“ steht bei fehlgeschlagenen Mails Nutzern mit *Einträge bearbeiten* zur Verfügung, bei Integrationen Nutzern mit *Integrationen verwalten*.
- **Interne Notizen:** sehen alle mit *Einträge lesen*, schreiben nur Nutzer mit *Einträge bearbeiten*. Notizen ändern die ursprüngliche Einsendung nie. Statuswechsel und erneute Zustellversuche werden als Protokolleintrag (ohne Feldwerte) festgehalten.

IP-Adressen und Browserkennungen werden nicht gespeichert und deshalb auch nicht angezeigt.

### Einwilligung widerrufen

Widerruft eine Person ihre Einwilligung (z. B. per E-Mail), erfassen Sie das in der Detailansicht im Kasten **Einwilligung widerrufen** (Recht *Einträge bearbeiten*):

1. Wählen Sie, was mit dem Eintrag geschehen soll:
   - **Eintrag behalten** – die Antworten bleiben, der Eintrag wird nur gekennzeichnet.
   - **Personenbezogene Daten anonymisieren** – wie bei einer Löschanfrage im Modus „Anonymisieren“: persönliche Werte werden durch `[deleted]` ersetzt, Notizen gelöscht, Auswahlwerte und Einwilligungsnachweis bleiben.
   - **Eintrag endgültig löschen** – nur mit dem Recht *Einträge löschen*.
2. Bestätigen Sie mit dem Häkchen, dass die Person widerrufen hat, und klicken Sie **Einwilligung widerrufen**.

Was dann passiert:

- Der Zeitpunkt wird gespeichert und als Badge „Einwilligung widerrufen am …“ in Liste und Detailansicht angezeigt. Der ursprüngliche Einwilligungsnachweis bleibt unverändert.
- Noch offene Mails und Integrations-Aufträge des Eintrags werden abgebrochen (Fehlercode `consent_withdrawn` im Versandstatus). Danach werden für diesen Eintrag keine Mails und keine Integrations-Aufträge mehr ausgeführt; „Admin-Benachrichtigung erneut senden“ steht nicht mehr zur Verfügung.
- Ein Protokolleintrag „Widerruf der Einwilligung erfasst“ (ohne Feldwerte) wird angelegt.
- Verbundene Integrationen werden über das Ereignis `form_captain_consent_withdrawn_v1` informiert (nur IDs, Zeitpunkt, Quelle). Ob und wie ein Fremdsystem darauf reagiert, legt die jeweilige Integration fest; Daten im Fremdsystem werden nicht ungefragt gelöscht.
- Der Widerruf lässt sich nicht rückgängig machen. Ein zweiter Widerruf ändert nichts.

### Status

| Status | Bedeutung |
|---|---|
| Neu | noch nicht geöffnet |
| Gelesen | geöffnet oder manuell markiert |
| Archiviert | erledigt, bleibt in Suche und Export |
| Spam | automatisch erkannt oder manuell markiert; keine Mails/Integrationen; wird nach der Papierkorbfrist gelöscht |
| Papierkorb | wird nach der Papierkorbfrist (Standard 30 Tage) endgültig gelöscht; bis dahin wiederherstellbar |

Wird ein Spam-Eintrag auf „Kein Spam“ gesetzt, wird die Benachrichtigung nicht automatisch nachgeholt. Nutzen Sie dafür in der Detailansicht „Admin-Benachrichtigung erneut senden“.

## CSV-Export

**Einträge → CSV exportieren** (Recht *Einträge exportieren*).

1. Formular wählen (Pflicht – die Spalten hängen vom Formular ab).
2. Spalten wählen: Metadaten, alle Felder der aktuellen Version und Felder älterer Versionen (Zusatz „(entfernt)“). **Sensible Felder sind abgewählt** und werden nur exportiert, wenn Sie sie ausdrücklich anhaken.
3. Optional Status und Zeitraum wählen. Ohne Auswahl werden alle aktiven Einträge exportiert, also ohne Spam und Papierkorb.
4. Format: UTF-8 mit BOM (empfohlen für Excel), Trennzeichen Komma oder Semikolon.

Hinweise:

- Werte, die mit `=`, `+`, `-`, `@`, Tabulator oder Wagenrücklauf beginnen, werden mit `'` versehen, damit Tabellenprogramme sie nicht als Formel ausführen. Zahlenfelder mit negativen Zahlen bleiben unverändert.
- Mehrzeilige Antworten bleiben in einer Zelle (korrekt in Anführungszeichen).
- Die Datei wird direkt gestreamt und nicht auf dem Server gespeichert. Maximal 100 000 Zeilen je Export, sonst bitte den Zeitraum eingrenzen; höchstens 10 Exporte je Stunde und Nutzer.
- Gelöschte Einträge sind nie enthalten; anonymisierte erscheinen mit Status „Anonymisiert“ und Platzhaltern.
- Die Spalte **Reference number** (Vorgangsnummer) ist standardmäßig ausgewählt; Einträge ohne Nummer bleiben leer.
- Die Spalte **Consent withdrawn** (Einwilligung widerrufen) ist standardmäßig ausgewählt und enthält bei widerrufener Einwilligung Datum und Uhrzeit, sonst bleibt sie leer. Sie können sie abwählen.
- Jeder Export wird im technischen Protokoll vermerkt (Nutzer-ID, Formular, Zeilenzahl – keine Inhalte).

Behandeln Sie exportierte Dateien wie die Einträge selbst: nur so lange aufbewahren wie nötig.

## E-Mail-Zustellung

Form Captain versendet Mails nicht während der Einsendung, sondern über eine **Warteschlange**: Die Einsendung wird zuerst sicher gespeichert, danach werden die Mails im Hintergrund (WP-Cron, jede Minute) versendet. Fällt der Versand aus, geht keine Einsendung verloren.

- **Admin-Benachrichtigung:** Empfänger, Betreff und Feldauswahl stellen Sie je Formular ein. `{admin_email}` steht für die Administrator-Adresse der Website. Platzhalter im Betreff: `{form_title}`, `{site_title}`, `{field:schluessel}` (sensible Felder bleiben leer). Standardmäßig enthält die Mail alle nicht sensiblen Felder; Hinweistexte (HTML-Felder) und Einwilligungstexte nie (die Einwilligung erscheint als Ja/Nein). Die Mail enthält einen Link zur Detailansicht im Admin.
- **Antwortadresse (Reply-To):** das gewählte E-Mail-Feld – nur, wenn der Wert eine gültige Adresse ohne Zeilenumbrüche ist.
- **Absender (From):** aus den Formular-Einstellungen, sonst aus *Einstellungen → E-Mail*. Niemals aus Formularwerten.
- **Bestätigung an die einsendende Person** (optional): geht nur an das als Empfänger markierte, gültige E-Mail-Feld, enthält standardmäßig keine Feldwerte, kein Admin-Link, höchstens 3 Bestätigungen je Empfängeradresse und Stunde. Für Spam wird keine Bestätigung gesendet.
- **Format:** HTML (alle Werte maskiert) mit zusätzlichem Textteil oder reiner Text, einstellbar unter *Einstellungen → E-Mail*.
- **Fehler und Wiederholung:** Schlägt `wp_mail()` fehl, versucht Form Captain es mit wachsender Wartezeit erneut (bis zu 8 Versuche). Danach ist der Versand „endgültig fehlgeschlagen“ und kann in der Detailansicht erneut angestoßen werden. Fehlermeldungen werden ohne Adressen gespeichert.
- **Gelöschte Einträge werden nie versendet:** Beim Löschen werden offene Mails und Integrationsaufträge abgebrochen; jeder Auftrag lädt den Eintrag unmittelbar vor dem Versand neu.

### SMTP und Zustellbarkeit

WordPress versendet standardmäßig über die PHP-Funktion `mail()` des Servers. Das ist oft unzuverlässig (Spamfilter, fehlende SPF/DKIM-Einträge). Empfehlung:

1. Ein SMTP- oder Mail-API-Plugin installieren und mit einem echten Postfach der eigenen Domain verbinden. Form Captain nutzt automatisch `wp_mail()` und damit dieses Plugin.
2. Als Absenderadresse eine Adresse der eigenen Domain verwenden (z. B. `noreply@ihre-domain.de`), nie die Adresse der einsendenden Person.
3. SPF, DKIM und DMARC der Domain prüfen lassen.
4. Mit *Einstellungen → E-Mail → Testmail* prüfen und danach eine Testeinsendung machen; der Versandstatus erscheint im Eintrag.

Ist WP-Cron deaktiviert (`DISABLE_WP_CRON`), richten Sie einen System-Cron ein, z. B. `wp form-captain jobs run` jede Minute oder einen Aufruf von `wp-cron.php`.

## Aufbewahrung und Löschung

Unter *Einstellungen → Datenschutz* wählen Sie, wie lange Einsendungen gespeichert bleiben: **90 Tage**, **12 Monate** (Standard) oder **bis zur manuellen Löschung**. Einzelne Formulare können eine eigene Frist haben.

- Die Frist zählt ab Eingang (UTC). „12 Monate“ sind Kalendermonate (29.02. → 01.03. des Folgejahres).
- Wird eine Frist **verkürzt**, wird kein Eintrag früher als **14 Tage** nach der Änderung gelöscht. Bei Wechsel auf „manuell“ entfällt die automatische Löschung.
- In den 14 Tagen vor einer automatischen Löschung zeigt die Eintragsliste einen Hinweis mit Anzahl und Datum und den Reiter „Läuft bald ab“; die Detailansicht nennt das Löschdatum. Exportieren Sie Einträge rechtzeitig, falls Sie sie noch brauchen.
- Papierkorb und Spam werden nach der Papierkorbfrist (Standard 30 Tage) endgültig gelöscht.
- Die tägliche Bereinigung arbeitet in kleinen Portionen (200 Einträge, höchstens 20 Sekunden je Lauf) und setzt bei Bedarf automatisch fort. Ein Eintrag, der sich einmal nicht löschen lässt, wird beim nächsten Lauf erneut versucht. Die Bereinigung entfernt außerdem alte Protokolle (30 Tage), erledigte Aufträge (30 Tage) und endgültig fehlgeschlagene Aufträge (90 Tage).

**Endgültiges Löschen** (manuell, Aufbewahrungsfrist, Datenschutzanfrage oder Formularlöschung) entfernt in dieser Reihenfolge: offene Mail-/Integrationsaufträge (werden abgebrochen), Dateien und Dateiverweise, Antworten, Notizen und den Eintrag selbst. Erweiterungen werden über das Ereignis `form_captain_entry_deleted_v1` informiert. Das technische Protokoll enthält danach nur noch die Eintrags-ID und den Löschgrund.

## Datenlebenszyklus und Kopien

| Ort | Was liegt dort? | Wann wird es entfernt? |
|---|---|---|
| Einträge, Antworten, Notizen | Einsendung | Aufbewahrungsfrist, Papierkorb, Datenschutzanfrage, manuell |
| Dateiverweise (Pro) | Upload-Metadaten | mit dem Eintrag |
| Warteschlange | nur IDs und Fehlercodes, keine Antworten | beim Löschen abgebrochen; erledigte nach 30, fehlgeschlagene nach 90 Tagen gelöscht |
| Technisches Protokoll | Codes, IDs, Zeit – keine Inhalte, keine Adressen | nach 30 Tagen |
| Versandte E-Mails | freigegebene Feldwerte | **außerhalb** von Form Captain (Postfach, SMTP-Dienst) – dort separat löschen |
| CSV-Dateien | exportierte Spalten | **außerhalb** von Form Captain – liegen nur auf Ihrem Rechner |
| Integrationen | gemappte Felder | im Zielsystem, je nach Integration |
| Backups | vollständige Datenbankkopie | **außerhalb** von Form Captain – bis das Backup abläuft |

### Backups

Form Captain hat keinen Einfluss auf Backups. Gelöschte Einsendungen bleiben in vorhandenen Backups, bis diese ablaufen. Wird ein Backup eingespielt, löscht die nächste Bereinigung abgelaufene Einträge und alten Papierkorb automatisch erneut, denn die Löschung richtet sich allein nach den gespeicherten Zeitpunkten. Der Systemstatus warnt, wenn nach einem Lauf noch Einträge vorhanden sind, deren Frist schon länger abgelaufen ist (Hinweis auf ein eingespieltes Backup).

Datenschutz-Löschanfragen (Werkzeuge → Personenbezogene Daten löschen), die nach dem Backup-Datum abgeschlossen wurden, müssen Sie nach einem Restore **erneut ausführen** – Form Captain führt bewusst keine Liste gelöschter Personen. Stimmen Sie die Aufbewahrungsdauer Ihrer Backups auf die gewählte Frist ab.
