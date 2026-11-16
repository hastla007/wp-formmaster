# Formulare aus einem anderen Formularformat importieren

Menü **Form Captain → Formulare**, Bereich **„Aus anderem Formularformat importieren“** unterhalb der Formularliste. Benötigt das Recht *Formulare verwalten* (`form_captain_manage_forms`).

Unterstützt wird das **Shortcode-Formularformat Typ A**: eine Formularvorlage aus HTML mit Feld-Tags in eckigen Klammern, zum Beispiel:

```
<label> Ihr Name [text* your-name placeholder "Max Mustermann"] </label>
<label> E-Mail [email* your-email] </label>
<label> Anliegen [select anliegen "Angebot" "Support|support"] </label>
[acceptance dsgvo] Ich stimme der Verarbeitung meiner Angaben zu. [/acceptance]
[submit "Senden"]
```

## Ablauf

1. **Quelltext einfügen:** Kopieren Sie die Formularvorlage aus der bisherigen Installation in das Textfeld (höchstens 256 KB) und vergeben Sie optional einen Titel.
2. **Analysieren und Vorschau:** Der Quelltext wird nur gelesen und zerlegt – nie ausgeführt. Es wird noch nichts gespeichert.
3. **Bericht prüfen:** Die Vorschau zeigt eine Ampel (*vollständig*, *mit Anpassungen*, *mit Verlusten*, *nicht importierbar*) und eine Tabelle je Element: Original-Tag, Zieltyp, Feldschlüssel, Beschriftung und Ergebnis (*übernommen*, *angepasst*, *verworfen*). Darunter stehen die Hinweise.
4. **Als Entwurf importieren:** Mit der Bestätigung wird das Formular mit dem Titelzusatz „(Import)“ als **Entwurf** angelegt. Es wird nie automatisch veröffentlicht. Die Vorschau verfällt nach 30 Minuten.
5. **Nacharbeiten:** Empfänger der Benachrichtigung prüfen (Standard: Administrator-Adresse), Einwilligungstext prüfen, Formular testen, dann veröffentlichen und auf Ihren Seiten den alten Shortcode durch den Shortcode bzw. Block von Form Captain ersetzen.

Das Original bleibt unverändert: Form Captain ändert, deaktiviert oder löscht keine Daten der Quelle und ersetzt keine Shortcodes auf Ihren Seiten.

## Was wird wie übernommen?

| Tag | Wird zu | Hinweis |
|---|---|---|
| `text`, `email`, `tel`, `textarea`, `number`, `date`, `select`, `radio`, `checkbox`, `hidden` | gleichnamiger Feldtyp | Pflicht (`*`), Platzhalter, Standardwert, Längen-/Zahlen-/Datumsgrenzen, Optionen und `Beschriftung|Wert` werden übernommen |
| `url` | Einzeiliger Text | keine URL-Prüfung, max. 1000 Zeichen |
| `range` | Zahl | Schieberegler wird zum Zahlenfeld |
| `checkbox … exclusive` | Optionsfelder | |
| einzelne `checkbox` ohne Optionen | Checkbox mit einer Option | |
| `acceptance` | Einwilligung | immer Pflicht, **nie vorangekreuzt**, Revision 1; mit `optional` wird daraus eine normale Checkbox |
| Text/HTML zwischen den Tags | Beschriftung bzw. HTML-Hinweis | erlaubtes HTML siehe [Formulare](formulare.md); Skripte, Event-Attribute und `javascript:`-Links werden entfernt |
| `submit` | – | Form Captain verwendet seine eigene, übersetzte Schaltflächenbeschriftung |
| `file` | – | **nicht unterstützt** (Datei-Upload gibt es nur in Pro); wird nur gemeldet |
| `quiz`, Captcha-Tags, Berechnungen, unbekannte Tags | – | **nicht unterstützt**, im Bericht mit Original-Tag aufgeführt; Form Captain schützt mit Honeypot, Mindestzeit und Rate-Limit |

Weitere Regeln:

- Jedes Feld erhält eine neue, dauerhafte ID. Der **Feldschlüssel** wird aus dem Tag-Namen gebildet (Kleinbuchstaben, `-` wird `_`, beginnt mit einem Buchstaben, max. 40 Zeichen); doppelte Namen erhalten `_2`, `_3` …
- Die **Beschriftung** stammt aus dem umschließenden `<label>`, sonst aus dem unmittelbar vorangehenden Text, sonst aus dem Platzhalter oder dem Schlüssel (Hinweis „abgeleitet“). Sie wird auf 190 Zeichen gekürzt.
- Optionswerte werden auf Buchstaben, Ziffern, `-` und `_` vereinheitlicht.
- Dynamische Vorbelegungen (`default:…` mit Nutzer- oder URL-Daten) und Freitext-Optionen werden nicht übernommen.
- Mehr als 100 Felder oder mehr als 200 Optionen in einem Feld: Das Formular wird **nicht** importiert (kein Teilimport). Ebenso, wenn kein unterstütztes Eingabefeld gefunden wird.
- Mailvorlagen, Meldungstexte und Zusatzeinstellungen der Quelle werden nicht übernommen; das importierte Formular startet mit den Standardeinstellungen von Form Captain.

Der Bericht enthält keine Feldwerte. Er wird zusammen mit der Zuordnung „Quellname → Feld-ID“ beim importierten Formular gespeichert.

## Einträge aus einer CSV-Datei (technischer Ablauf)

Das Format Typ A speichert selbst keine Einsendungen. Einträge lassen sich nur aus einer dokumentierten CSV-Exportdatei übernehmen (Formatprofil „Generisches CSV“):

- UTF-8 (mit oder ohne BOM), Kopfzeile, eine Zeile je Einsendung, Trennzeichen `,` oder `;`, Mehrfachwerte durch `|` getrennt;
- eine Spalte mit der Einsendezeit (ISO 8601 oder `JJJJ-MM-TT HH:MM:SS` in der gewählten Zeitzone);
- höchstens 20 MB und 50 000 Zeilen.

Voraussetzungen: Das Formular wurde über diesen Import angelegt und anschließend **veröffentlicht**; Sie benötigen zusätzlich das Recht *Einträge bearbeiten*. Die Spalten werden den Feldern zugeordnet; jede Zeile wird geprüft (Pflichtfelder dürfen fehlen). Ungültige Zeilen werden mit Zeilennummer und Fehlercode – ohne Wert – gemeldet und übersprungen, ebenso Zeilen, deren Aufbewahrungsfrist ab der ursprünglichen Einsendezeit bereits abgelaufen ist.

Importierte Einträge erhalten den Status *gelesen* und die Einsendezeit aus der Datei. Es werden **keine** E-Mails und keine Connector-Aktionen ausgelöst. Ein erneuter Import derselben Datei erzeugt keine Duplikate.

Hinweis: In dieser Version steht der Eintragsimport als Funktion für Entwickler und Werkzeuge bereit; eine eigene Oberfläche dafür folgt.
