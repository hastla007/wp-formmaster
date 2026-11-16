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

## Einträge aus einer CSV-Datei importieren

Bisherige Einsendungen lassen sich aus einer CSV-Datei in ein **veröffentlichtes** (oder pausiertes) Formular übernehmen – unabhängig davon, ob das Formular importiert oder neu angelegt wurde.

**Einstieg:** *Form Captain → Formulare* → beim Formular die Zeilenaktion **„Einträge importieren“**, oder unten im Importbereich **„Einträge aus einer CSV-Datei importieren“** das Zielformular wählen.

**Rechte:** *Formulare verwalten* und *Einträge bearbeiten*; für die Rücknahme *Formulare verwalten* und *Einträge löschen*.

### Datenschutz vorab

- Für die Rechtsgrundlage der Altdaten (z. B. die damalige Einwilligung) sind Sie als Betreiber verantwortlich. Importieren Sie nur Daten, die Sie weiter speichern und verarbeiten dürfen.
- Die **Aufbewahrungsfrist** des Formulars (90 Tage, 12 Monate oder manuell) wird ab der **ursprünglichen Einsendezeit** berechnet. Zeilen, deren Frist bereits abgelaufen ist, werden nicht importiert; die übrigen werden automatisch gelöscht, sobald ihre Frist endet.
- Die Datei wird nur vorübergehend in einem geschützten Verzeichnis gespeichert und nach dem Import, beim Abbrechen, spätestens aber nach **einer Stunde** gelöscht. Ihr Speicherort erscheint nie in einer Adresse.

### Schritt 1: Datei hochladen

- CSV in **UTF-8** (mit oder ohne BOM), erste Zeile mit Spaltenüberschriften, eine Zeile je Einsendung; Trennzeichen **Komma oder Semikolon** wird automatisch erkannt. Werte mit Trennzeichen, Anführungszeichen oder Zeilenumbrüchen in Anführungszeichen (Excel-/RFC-4180-Stil).
- Höchstens **5 MB** und **10 000 Zeilen**. Größere Bestände teilen Sie auf mehrere Dateien auf.
- Mehrfachwerte (Checkboxen, Mehrfachauswahl) durch `|` trennen, z. B. `Web|App`. Optionen dürfen als Beschriftung oder Wert angegeben werden.
- Dateien in anderer Kodierung (z. B. Windows-1252) werden abgelehnt – speichern Sie die Datei in Excel als „CSV UTF-8“.

### Schritt 2: Spalten zuordnen

Für jede Spalte wählen Sie das Zielfeld (mit Hinweis auf das erwartete Format) oder **„Ignorieren“**. Spalten mit gleichem Namen wie Feldbeschriftung oder -schlüssel sind vorbelegt. Eine Vorschau zeigt die ersten 5 Zeilen mit gekürzten Werten.

**Einsendezeit:** Wählen Sie die Spalte mit dem Zeitpunkt der Einsendung und das Datumsformat – meist genügt „Automatisch erkennen“:

| Format | Beispiele |
|---|---|
| ISO 8601 | `2026-03-01T10:15:00+01:00`, `2026-03-01 10:15:00` |
| JJJJ-MM-TT | `2026-03-01`, `2026-03-01 10:15` |
| TT.MM.JJJJ | `01.03.2026`, `1.3.2026 10:15`, `01.03.2026 10:15:30` |
| TT/MM/JJJJ bzw. MM/TT/JJJJ | `13/01/2026`, `01/13/2026 10:15` – bei mehrdeutigen Werten gilt Tag/Monat; wählen Sie das Format sonst selbst |
| Unix-Zeitstempel | `1767225600` |

Zeiten ohne Zeitzonenangabe gelten in der **Zeitzone der Website** (*Einstellungen → Allgemein*) und werden in UTC gespeichert; reine Datumsangaben als 00:00 Uhr.

### Schritt 3: Prüfen (Trockenlauf)

Alle Zeilen werden geprüft, ohne etwas zu speichern. Sie sehen, wie viele Zeilen **gültig**, **ungültig**, **Duplikate** (bereits importiert) und **abgelaufen** sind. Ungültige Zeilen werden mit Zeilennummer (Kopfzeile = 1), Fehlercode und Feldschlüssel aufgeführt – **ohne** Werte. Pflichtfelder dürfen fehlen; ungültige Werte (z. B. `invalid_email`, `invalid_option`, `invalid_time`) führen zum Überspringen der Zeile. Bei Bedarf passen Sie die Zuordnung an und prüfen erneut.

### Schritt 4: Importieren

Der Import läuft in Schritten zu je 500 Zeilen und setzt sich automatisch fort; ein Fortschrittsbalken zeigt den Stand. Wird er unterbrochen (Browser geschlossen, Zeitüberschreitung), klicken Sie auf **„Fortsetzen“** – oder laden Sie die Datei nach Ablauf der Stunde einfach erneut hoch: bereits importierte Zeilen werden erkannt und übersprungen. Am Ende zeigt der **Importbericht** die Zahlen und einen Link zu den Einträgen.

Importierte Einträge erhalten den Status *gelesen* und die Einsendezeit aus der Datei. Es werden **keine** E-Mails, keine Connector-Aktionen und keine „Eintrag erstellt“-Ereignisse ausgelöst. Werte werden **so gespeichert, wie sie in der Datei stehen** – auch Formeln (`=…`) oder HTML; in der Verwaltung werden sie stets maskiert angezeigt und beim CSV-Export gegen Formel-Injektion geschützt.

### Import rückgängig machen

Unter **„Bisherige Importe“** steht bei jedem Lauf **„Import rückgängig machen“**. Das löscht **genau die Einträge, die dieser Lauf angelegt hat**, endgültig – auch wenn sie danach bearbeitet wurden; andere Einträge bleiben unberührt. Möglich innerhalb von **7 Tagen** nach dem Import. Große Läufe werden in Schritten gelöscht.

### Für Entwickler

`FormCaptain\Import\ImportService::import_entries()` importiert weiterhin ohne Oberfläche in Formulare, die über den Formularimport angelegt wurden (bis 20 MB / 50 000 Zeilen, Spaltenzuordnung über Spaltenüberschriften). Nach jedem Schritt mit neuen Einträgen wird `form_captain_entries_imported_v1( $import_id, $form_id, $count )` ausgelöst.
