# Einstellungen

*Form Captain → Einstellungen* (Recht „Einstellungen verwalten“). Jeder Bereich wird einzeln gespeichert; ungültige Eingaben werden mit Hinweis am Feld abgelehnt, alle übrigen Werte gespeichert.

| Bereich | Was Sie hier einstellen |
|---|---|
| **Allgemein** | Ersteinrichtung erledigt, gelegentliche Pro-Hinweise, Protokollstufe (Fehler … Debug), Höchstlänge ein- und mehrzeiliger Felder, Länderprofil als Vorschlag. Formularbezogenes (Empfänger, Betreff, Bestätigung, Weiterleitung, Aktionen) stellen Sie im Formular ein. Formular-Import/-Export finden Sie in der Formularliste, CSV-Export bei den Einträgen. |
| **Darstellung** | Theme-Stil (Standard) oder Form-Captain-Basisstil mit Akzentfarbe. Es werden keine externen Schriften/Dateien geladen. |
| **E-Mail** | Standard-Absender für **neue** Formulare und Format. Hinweis, wenn die Absenderdomain nicht zur Website passt. Testmail an Ihre eigene Adresse (max. 3 je 10 min). SMTP-Zugangsdaten verwaltet Form Captain nicht – nutzen Sie dafür ein SMTP-Plugin. |
| **Datenschutz & Speicherung** | Aufbewahrung als Zeitraum (1 Tag bis 30 Jahre, Standard 12 Monate) oder manuell, Schonfrist nach Verkürzung, Vorwarnung „läuft bald ab“, Aufbewahrung des Protokolls, Papierkorbfrist, Verhalten bei Löschanfragen (löschen oder anonymisieren), Datenlöschung bei Deinstallation, Textvorschlag für die Datenschutzerklärung, Backup-Hinweis. |
| **Spamschutz** | Honeypot, Mindest-Ausfüllzeit, Rate-Limit je Formular und über alle Formulare, Zeit zum Ausfüllen, Blockliste, Quelle der Besucheradresse hinter Proxy/CDN (nur wählen, wenn die Website wirklich hinter dem Dienst steht). Externe Captchas gibt es nur über Erweiterungen; vor Aktivierung sehen Sie Anbieter, übermittelte Daten und Zweck und müssen bestätigen. |
| **Export** | Vorauswahl für den CSV-Export: Trennzeichen (Komma/Semikolon) und UTF-8-BOM. Jeder Export kann andere Werte nutzen. |
| **Zugriff & Rollen** | Matrix Rolle × Recht. Angezeigt wird, wie viele Nutzer danach Einträge lesen/exportieren können. Administratoren behalten immer „Einstellungen verwalten“. Ändern erfordert zusätzlich das WordPress-Recht „Benutzer befördern“. |
| **Systemstatus** | Nur lesend: Versionen, Datenbankschema, Warteschlange/Cron, Mail-Fehlercodes, Integrationen, letzte Fehler mit Korrelations-ID, kopierbarer Diagnosebericht. |

## Globale Standards und Formularwerte

Absender und Darstellung werden beim Anlegen eines Formulars **kopiert**. Spätere Änderungen gelten nur für neue Formulare; die Seite zeigt, wie viele bestehende Formulare eigene Werte behalten.

## Aufbewahrung ändern

Geben Sie eine Zahl mit Einheit (Tage, Monate, Jahre) ein oder wählen Sie „bis zum manuellen Löschen“. Eine Änderung muss bestätigt werden: Ohne Häkchen zeigt die Seite die Änderung erneut mit der Anzahl betroffener Einträge und dem frühesten Löschdatum. Es wird nie früher als nach der eingestellten Schonfrist (Standard 14 Tage) gelöscht. Auch jedes Formular kann einen eigenen Zeitraum haben (Formular → Einstellungen → Aufbewahrung). Gesetzliche Fristen hängen von Land und Zweck ab – lassen Sie sie rechtlich prüfen.

## Länderprofil

*Allgemein → Länderprofil*: Land wählen, „Vorschlag anzeigen“. Die Tabelle zeigt je Einstellung den aktuellen und den vorgeschlagenen Wert sowie den rechtlichen Stand („ungeprüft“ = neutraler Vorschlag). Erst nach Auswahl der Zeilen und Bestätigung wird etwas geändert; alle Werte bleiben danach änderbar.

## Zurücksetzen

„Diesen Bereich zurücksetzen“ setzt nur den gewählten Bereich auf Standardwerte (Bestätigung nötig). Andere Bereiche und bestehende Formulare bleiben unverändert. Bei Updates werden neue Optionen ergänzt, vorhandene Werte nie überschrieben.

## Daten bei Deinstallation löschen

Standardmäßig bleiben Formulare und Einträge beim Löschen des Plugins erhalten. Für vollständige Löschung: Häkchen setzen **und** `DELETE` bzw. `LÖSCHEN` eingeben, dann speichern. Die Pluginliste zeigt danach einen Warnhinweis. Häkchen entfernen hebt die Bestätigung auf.

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
