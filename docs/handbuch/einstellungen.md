# Einstellungen

*Form Captain → Einstellungen* (Recht „Einstellungen verwalten“). Jeder Bereich wird einzeln gespeichert; ungültige Eingaben werden mit Hinweis am Feld abgelehnt, alle übrigen Werte gespeichert.

| Bereich | Was Sie hier einstellen |
|---|---|
| **Allgemein** | Ersteinrichtung erledigt, gelegentliche Pro-Hinweise (auch die grauen Pro-Zeilen am Ende der Bereiche). Formularbezogenes (Empfänger, Betreff, Bestätigung, Weiterleitung, Aktionen) stellen Sie im Formular ein. Formular-Import/-Export finden Sie in der Formularliste, CSV-Export bei den Einträgen. |
| **Darstellung** | Theme-Stil (Standard) oder Form-Captain-Basisstil mit Akzentfarbe. Es werden keine externen Schriften/Dateien geladen. |
| **E-Mail** | Standard-Absender für **neue** Formulare und Format. Hinweis, wenn die Absenderdomain nicht zur Website passt. Testmail an Ihre eigene Adresse (max. 3 je 10 min). SMTP-Zugangsdaten verwaltet Form Captain nicht – nutzen Sie dafür ein SMTP-Plugin. |
| **Datenschutz & Speicherung** | Aufbewahrung 90 Tage / 12 Monate (Standard) / manuell, Papierkorbfrist, Verhalten bei Löschanfragen (löschen oder anonymisieren), Datenlöschung bei Deinstallation, Textvorschlag für die Datenschutzerklärung, Backup-Hinweis. |
| **Spamschutz** | Honeypot, Mindest-Ausfüllzeit, Rate-Limit, Blockliste. Externe Captchas gibt es nur über Erweiterungen; vor Aktivierung sehen Sie Anbieter, übermittelte Daten und Zweck und müssen bestätigen. |
| **Zugriff & Rollen** | Matrix Rolle × Recht. Angezeigt wird, wie viele Nutzer danach Einträge lesen/exportieren können. Administratoren behalten immer „Einstellungen verwalten“. Ändern erfordert zusätzlich das WordPress-Recht „Benutzer befördern“. |
| **Systemstatus** | Nur lesend: Versionen, Datenbankschema, Warteschlange/Cron, Mail-Fehlercodes, Integrationen, letzte Fehler mit Korrelations-ID, kopierbarer Diagnosebericht. |

## Pro-Hinweiszeilen

Am Ende einiger Bereiche stehen graue Zeilen mit „Pro“-Kennzeichen. Sie nennen nur, welche Einstellung Form Captain Pro an dieser Stelle ergänzt; es gibt dort nichts anzuklicken oder freizuschalten, und alle freien Funktionen bleiben frei. Ist das Add-on aktiv und die Funktion verfügbar, erscheint an derselben Stelle das echte Feld. Ausblenden: *Allgemein → Pro-Hinweise zeigen* abwählen.

## Globale Standards und Formularwerte

Absender und Darstellung werden beim Anlegen eines Formulars **kopiert**. Spätere Änderungen gelten nur für neue Formulare; die Seite zeigt, wie viele bestehende Formulare eigene Werte behalten.

## Aufbewahrung ändern

Eine Änderung muss bestätigt werden. Sie sehen vorher je Option die Anzahl betroffener Einträge und das früheste Löschdatum. Es wird nie früher als 14 Tage nach der Änderung gelöscht.

## Zurücksetzen

„Diesen Bereich zurücksetzen“ setzt nur den gewählten Bereich auf Standardwerte (Bestätigung nötig). Andere Bereiche und bestehende Formulare bleiben unverändert. Bei Updates werden neue Optionen ergänzt, vorhandene Werte nie überschrieben.

## Daten bei Deinstallation löschen

Standardmäßig bleiben Formulare und Einträge beim Löschen des Plugins erhalten. Für vollständige Löschung: Häkchen setzen **und** `DELETE` bzw. `LÖSCHEN` eingeben, dann speichern. Die Pluginliste zeigt danach einen Warnhinweis. Häkchen entfernen hebt die Bestätigung auf.

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
