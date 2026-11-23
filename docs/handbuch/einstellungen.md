# Einstellungen

*Form Captain → Einstellungen* (Recht „Einstellungen verwalten“). Jeder Bereich wird einzeln gespeichert; ungültige Eingaben werden mit Hinweis am Feld abgelehnt, alle übrigen Werte gespeichert.

| Bereich | Was Sie hier einstellen |
|---|---|
| **Allgemein** | Ersteinrichtung erledigt, gelegentliche Pro-Hinweise (auch die grauen Pro-Zeilen am Ende der Bereiche), Protokollstufe (Fehler … Debug), Höchstlänge ein- und mehrzeiliger Felder, Länderprofil als Vorschlag, **Vorgangsnummer** (Format wie `REQ-{year}-{number}`, Stellenzahl, jährlicher Neustart; leer = keine Nummer). Formularbezogenes (Empfänger, Betreff, Bestätigung, Weiterleitung, Aktionen) stellen Sie im Formular ein. Formular-Import/-Export finden Sie in der Formularliste, CSV-Export bei den Einträgen. |
| **Darstellung** | Theme-Stil (Standard) oder Form-Captain-Basisstil mit Akzentfarbe, Eckenradius, Abstand zwischen den Feldern und Schriftgröße. Es werden keine externen Schriften/Dateien geladen. **Eigenes CSS** gilt für beide Stile und kann nur von Nutzern mit dem WordPress-Recht „ungefiltertes HTML“ geändert werden (`@import` und HTML sind nicht erlaubt). |
| **E-Mail** | Standard-Absender für **neue** Formulare und Format. Hinweis, wenn die Absenderdomain nicht zur Website passt. Testmail an Ihre eigene Adresse (max. 3 je 10 min). SMTP-Zugangsdaten verwaltet Form Captain nicht – nutzen Sie dafür ein SMTP-Plugin. |
| **Datenschutz & Speicherung** | Aufbewahrung als Zeitraum (1 Tag bis 30 Jahre) oder manuell (Vorgabe bei Neuinstallation; der Website-Zustand erinnert, bis eine Frist gesetzt ist), Schonfrist nach Verkürzung, Vorwarnung „läuft bald ab“, Aufbewahrung des Protokolls, Papierkorbfrist, Verhalten bei Löschanfragen (löschen oder anonymisieren), Datenlöschung bei Deinstallation, Textvorschlag für die Datenschutzerklärung, Backup-Hinweis. |
| **Spamschutz** | Honeypot, Mindest-Ausfüllzeit, Rate-Limit je Formular und über alle Formulare (`spam.global_per_ip`), Zeitfenster, Höchstzahl je Formular, Mails je Besucheradresse, Zeit zum Ausfüllen, Blockliste, Quelle der Besucheradresse hinter Proxy/CDN (nur wählen, wenn die Website wirklich hinter dem Dienst steht). Captcha: eingebauter Proof of Work (ohne externen Dienst) oder externe Captchas über Erweiterungen; vor Aktivierung eines externen Anbieters sehen Sie Anbieter, übermittelte Daten und Zweck und müssen bestätigen. |
| **Export** | Vorauswahl für den CSV-Export: Trennzeichen (Komma/Semikolon) und UTF-8-BOM. Jeder Export kann andere Werte nutzen. |
| **Zugriff & Rollen** | Matrix Rolle × Recht. Angezeigt wird, wie viele Nutzer danach Einträge lesen/exportieren können. Administratoren behalten immer „Einstellungen verwalten“. Ändern erfordert zusätzlich das WordPress-Recht „Benutzer befördern“. |
| **Systemstatus** | Nur lesend: Versionen, Datenbankschema, Warteschlange/Cron, Mail-Fehlercodes, Integrationen, letzte Fehler mit Korrelations-ID, kopierbarer Diagnosebericht. |

## Pro-Hinweiszeilen

Am Ende einiger Bereiche stehen graue Zeilen mit „Pro“-Kennzeichen. Sie nennen nur, welche Einstellung Form Captain Pro an dieser Stelle ergänzt; es gibt dort nichts anzuklicken oder freizuschalten, und alle freien Funktionen bleiben frei. Ist das Add-on aktiv und die Funktion verfügbar, erscheint an derselben Stelle das echte Feld. Ausblenden: *Allgemein → Pro-Hinweise zeigen* abwählen.

## Globale Standards und Formularwerte

Absender und Darstellung werden beim Anlegen eines Formulars **kopiert**. Spätere Änderungen gelten nur für neue Formulare; die Seite zeigt, wie viele bestehende Formulare eigene Werte behalten.

## Vorgangsnummer

Mit einem Format wie `REQ-{year}-{number}` bekommt jede neue Einsendung eine fortlaufende Nummer (z. B. `REQ-2026-0042`). `{number}` ist Pflicht, `{year}` optional; erlaubt sind außerdem Buchstaben, Ziffern und `- _ / . #`. Mit „jährlich neu beginnen“ startet die Nummer am 1. Januar (Zeitzone der Website) wieder bei 1 – nehmen Sie dann `{year}` ins Format auf. Ohne `{year}` im Format zählt die Nummer über den Jahreswechsel weiter, damit keine Nummer doppelt vergeben wird. Spam bekommt keine Nummer. Die Nummer steht in der Eintragsansicht und über `{entry_number}` in E-Mails und der Erfolgsmeldung. Ohne Format bleibt die Funktion aus; `{entry_number}` zeigt dann die Eintrags-ID.

## Aufbewahrung ändern

Geben Sie eine Zahl mit Einheit (Tage, Monate, Jahre) ein oder wählen Sie „bis zum manuellen Löschen“. Eine Änderung muss bestätigt werden: Ohne Häkchen zeigt die Seite die Änderung erneut mit der Anzahl betroffener Einträge und dem frühesten Löschdatum. Es wird nie früher als nach der eingestellten Schonfrist (Standard 14 Tage) gelöscht. Auch jedes Formular kann einen eigenen Zeitraum haben (Formular → Einstellungen → Aufbewahrung). Gesetzliche Fristen hängen von Land und Zweck ab – lassen Sie sie rechtlich prüfen.

## Länderprofil

*Allgemein → Länderprofil*: Land wählen, „Vorschlag anzeigen“. Die Tabelle zeigt je Einstellung den aktuellen und den vorgeschlagenen Wert sowie den rechtlichen Stand („ungeprüft“ = neutraler Vorschlag). Erst nach Auswahl der Zeilen und Bestätigung wird etwas geändert; alle Werte bleiben danach änderbar. Deutschland, Österreich und Schweiz werden vor dem Verkaufsstart rechtlich geprüft; EU allgemein und International enthalten neutrale, nicht geprüfte Werte. Alle Profile schlagen 12 Monate Aufbewahrung für Kontaktanfragen vor.

Nach dem Übernehmen schlägt der Formular-Editor für **neue** Felder passende Muster vor: Bei einem einzeiligen Textfeld ohne Muster erscheint „Postleitzahl-Feld? Vorschlag des Länderprofils übernehmen“ (Deutschland 5 Ziffern, Österreich und Schweiz 4 Ziffern, EU/International beliebiges Land); neue Telefonfelder starten bei EU und International mit „international mit + und Ländervorwahl“. Bestehende Felder bleiben unverändert.

## Zurücksetzen

„Diesen Bereich zurücksetzen“ setzt nur den gewählten Bereich auf Standardwerte (Bestätigung nötig). Andere Bereiche und bestehende Formulare bleiben unverändert. Bei Updates werden neue Optionen ergänzt, vorhandene Werte nie überschrieben.

## Daten bei Deinstallation löschen

Standardmäßig bleiben Formulare und Einträge beim Löschen des Plugins erhalten. Für vollständige Löschung: Häkchen setzen **und** `DELETE` bzw. `LÖSCHEN` eingeben, dann speichern. Die Pluginliste zeigt danach einen Warnhinweis. Häkchen entfernen hebt die Bestätigung auf.

## Einstellungen exportieren und importieren

*Systemstatus → Einstellungen exportieren* lädt eine JSON-Datei mit allen Einstellungen dieser Seite herunter – ohne Geheimnisse, Rollenrechte, Formulare und Einsendungen. Auf der Zielseite wählen Sie die Datei unter *Einstellungen importieren* aus und sehen zuerst eine Vorschau mit altem und neuem Wert. Ungültige Werte und unbekannte Schlüssel werden gemeldet und nicht übernommen. Eine andere Aufbewahrungsfrist müssen Sie wie auf der Einstellungsseite ausdrücklich bestätigen; „Daten bei Deinstallation löschen“ wird nie importiert.

## Änderungsprotokoll

*Systemstatus → Änderungsprotokoll* zeigt die letzten 50 Änderungen an Einstellungen und Rollenrechten: wann, wer, über welchen Weg (Einstellungsseite, WP-CLI, Import, System), welcher Wert von alt auf neu. Geheime Werte werden nie angezeigt. Wie lange Einträge bleiben, stellen Sie unter *Datenschutz & Speicherung* ein (Standard 12 Monate).

## Diagnosebericht für den Support

*Systemstatus → Bericht kopieren*. Der Bericht enthält Status, Fehlercodes und Korrelations-IDs, aber **keine** Feldwerte, E-Mail-Adressen, IP-Adressen, Tokens oder Schlüssel. Der Support findet mit der Korrelations-ID die passenden Protokolleinträge. Per WP-CLI: `wp form-captain status`.

## Schlüssel und Secrets

- Der Signaturschlüssel (für Absende-Tokens und signierte Suite-Kontexte) wird nie angezeigt oder protokolliert.
- *Systemstatus → Signaturschlüssel erneuern*: Der alte Schlüssel bleibt 26 Stunden gültig, damit geöffnete Formulare und Links weiter funktionieren. Bei Verdacht auf Kompromittierung „sofort verwerfen“ wählen – dann sind ältere Formulare/Links sofort ungültig (Besucher laden die Seite neu).
- Eine Änderung der WordPress-Salts (`AUTH_KEY`/`AUTH_SALT`) macht alle Tokens sofort ungültig.
- Zugangsdaten von Connectoren verwaltet der jeweilige Connector; sie erscheinen nicht im Diagnosebericht. Beim Entfernen eines Connectors dessen Einstellungen/Secrets dort löschen.

## Website-Zustand und WP-CLI

Unter *Werkzeuge → Website-Zustand* prüft Form Captain Warteschlange, Mail-Fehler (24 h), Cron und Datenbankschema. Ist `DISABLE_WP_CRON` gesetzt, richten Sie einen System-Cron ein:

```
* * * * * wp --path=/pfad/zu/wordpress form-captain jobs run
```

Weitere Befehle: `wp form-captain jobs list --status=dead`, `wp form-captain jobs retry <id>`, `wp form-captain cleanup`.

## E-Mail: Fußzeile, Antwortadresse, Logo

Im Reiter E-Mail legst du eine Fußzeile fest (z. B. Firmenname, Anschrift, Registernummer). Sie steht unter jeder Mail von Form Captain, auch unter der Test-E-Mail. Welche Angaben in Geschäftsmails Pflicht sind, hängt vom Land ab – lass den Text rechtlich prüfen. Die feste Antwortadresse gilt für Mails an Besucher (Bestätigung, Double-Opt-in). Das Logo wählst du aus der Mediathek; es erscheint oben in HTML-Mails.

## Spamschutz: eingebauter Proof of Work und Grenzen

„Eingebauter Proof of Work“ schützt ohne externen Dienst, ohne Cookies und ohne Rätsel: Der Browser rechnet im Hintergrund eine kleine Aufgabe. Ohne JavaScript gelten die übrigen Prüfungen. Je Formular (Reiter Einstellungen im Editor) kannst du das Captcha abschalten oder „strenger“ wählen. Zeitfenster, Höchstzahl je Formular und Mails je Besucheradresse sind einstellbar. Einzelheiten: `docs/spam-proof-of-work.md`.

## Double-Opt-in je Formular

Im Editor unter Benachrichtigungen: Ist Double-Opt-in an, bekommt der Besucher eine Mail mit Bestätigungslink. Erst nach dem Klick auf „Einsendung bestätigen“ gilt die Einsendung als angenommen; dann gehen Benachrichtigungen und Aktionen los. Der Link ist einmal nutzbar und standardmäßig 48 Stunden gültig; unbestätigte Einsendungen werden nach 7 Tagen gelöscht. Der Zeitpunkt der Bestätigung (ohne IP-Adresse) steht im Datenschutz-Export. Die Mail geht in der Sprache der Seite, auf der das Formular stand. Mit „Test an mich“ prüfst du jede Mail des Formulars mit Beispielwerten an deine eigene Adresse.

