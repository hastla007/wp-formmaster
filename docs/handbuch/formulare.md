# Formulare anlegen und verwalten

Menü **Form Captain → Formulare**. Benötigt das Recht *Formulare verwalten* (`form_captain_manage_forms`, standardmäßig nur Administratoren).

## Ersteinrichtung (Dashboard)

Nach der Aktivierung öffnet sich einmalig das Dashboard mit der Checkliste „Loslegen“:

1. **Formular aus Vorlage** – Kontakt, Angebotsanfrage oder Support. Das Formular wird als Entwurf angelegt.
2. **Empfänger und Absender prüfen** – zeigt die aktuellen Empfänger; „Benachrichtigung bearbeiten“ öffnet den Editor.
3. **Auf einer Seite einbetten** – Shortcode kopieren, den Block „Form Captain“ verwenden oder per Knopf eine neue Entwurfsseite mit dem Formular erzeugen.
4. **Testeinsendung** – Formular veröffentlichen, Seite aufrufen, einmal absenden; der Eintrag erscheint unter *Einträge*.
5. **Datenschutz/Aufbewahrung** – Link zu *Einstellungen → Datenschutz*.
6. **Suite-Plugins erkennen** – zeigt erkannte Captain-Suite-Plugins; es wird nichts aktiviert.

Jeder Schritt lässt sich überspringen; „Checkliste ausblenden“ beendet die Einrichtung. Das Dashboard zeigt außerdem aktive Formulare, neue Einträge, die letzten Formulare und Schnellaktionen.

## Formularübersicht

- Spalten: Titel, Status, Einbettung (Shortcode mit „Kopieren“), Anzahl Einträge, zuletzt geändert.
- Suche, Statusfilter (Alle, Veröffentlicht, Entwürfe, Pausiert, Archiviert) und Seitenblättern.
- Zeilenaktionen: Bearbeiten, Duplizieren, Vorschau, Pausieren/Fortsetzen, Archivieren bzw. „Als Entwurf wiederherstellen“, JSON exportieren, Löschen.
- **Löschen** ist nur ohne Einträge möglich. Hat ein Formular Einträge, archiviere es – oder lösche es mit dem zusätzlichen Recht *Einträge löschen* zusammen mit allen Einträgen („Mit Einträgen löschen“, nicht rückgängig zu machen).

## Editor

**Formulare → Neues Formular** zeigt die Vorlagen (oder „Leeres Formular“). Der Editor hat fünf Reiter:

### Felder

- Links die Feldpalette, in der Mitte die Feldliste, rechts die Einstellungen des gewählten Feldes.
- Reihenfolge: Pfeil-Schaltflächen (tastaturbedienbar) oder Ziehen am Griff ⋮⋮. Felder lassen sich duplizieren und entfernen.
- Jedes Feld hat eine feste interne ID, die sich nie ändert. Entfernte IDs werden nicht wiederverwendet, damit alte Einträge eindeutig bleiben.
- Einstellungen je Feld: Beschriftung, **Feldschlüssel** (für Export und Platzhalter `{field:schluessel}`; Kleinbuchstaben, Ziffern, `_`), Pflichtfeld, Hilfetext, Platzhalter, Standardwert, Grenzen (Länge, Min/Max, Schrittweite, Datum, Anzahl Auswahl), CSS-Klasse, „sensibel“.

**Feldtypen (Free):** Einzeiliger Text, Absatz, E-Mail, Telefon, Zahl, Auswahlliste (optional Mehrfachauswahl), Optionsfelder, Checkboxen, Datum, Überschrift/HTML-Hinweis, versteckter Wert, Einwilligung. Weitere Typen können Erweiterungen registrieren.

- **HTML-Hinweis:** erlaubt sind nur `p, br, strong, em, b, i, ul, ol, li, a, h3, h4, span`. Alles andere (Skripte, iframes, `javascript:`-Links, Event-Attribute) wird beim Speichern entfernt.
- **Einwilligung:** nie vorangekreuzt. Änderst du den Text, erhöht sich die Revision automatisch; bestehende Einträge behalten den Nachweis ihrer Version.
- **Sensible Felder** landen standardmäßig weder in E-Mails noch in Connector-Aktionen.
- **Versteckte Werte** können von Besuchern verändert werden – nie für Vertrauensentscheidungen nutzen.
- **Muster für einzeiligen Text:** Buchstaben, Buchstaben und Ziffern, Postleitzahl Deutschland (5 Ziffern), Österreich und Schweiz (4 Ziffern), „beliebiges Land“ (2–10 Buchstaben, Ziffern, Leer- oder Bindestriche) oder ein **eigenes Muster** (regulärer Ausdruck, z. B. `[A-Z]{2}-[0-9]{4}`). Eigene Muster mit verschachtelten Wiederholungen, Rückverweisen oder Lookarounds werden abgelehnt, weil sie die Website ausbremsen können.
- **Telefon:** „beliebige Telefonnummer“ (Standard) oder „international mit + und Ländervorwahl“ (z. B. `+49 30 1234567`).
- **Mindestalter am Datumsfeld:** Nur Geburtsdaten von Personen, die am Tag der Einsendung mindestens so alt sind, werden angenommen. Es gibt bewusst keine Vorgabe – welches Alter nötig ist, klärst du rechtlich für dein Land und deinen Zweck.
- **Eigene Fehlermeldung:** ersetzt die Standardmeldung des Feldes (leer = Standard).
- **Autofill:** sagt dem Browser, welche gespeicherten Angaben passen (Name, E-Mail, Telefon, Straße, PLZ, Ort, Land …). „Automatisch“ nutzt bei E-Mail und Telefon die passende Art.

### Bedingungen

Pro Feld genau eine Regel: „Feld anzeigen/ausblenden, wenn *Feld* *gleich / ungleich / enthält / leer / nicht leer / angehakt* *Wert*“. Es kann nur auf **weiter oben** stehende Felder mit Wert verwiesen werden. Die Regel wird auch auf dem Server geprüft; Werte ausgeblendeter Felder werden verworfen.

### Benachrichtigungen

- Admin-Benachrichtigung: Empfänger (eine Adresse pro Zeile, `{admin_email}` = Admin-Adresse der Website), Reply-To-Feld (ein E-Mail-Feld), Betreff mit Platzhaltern `{form_title}` und `{field:schluessel}`, Feldauswahl („alle außer sensiblen“ oder ausgewählte Felder).
- Bestätigung an Besucher: Betreff, Text, optional ausgewählte Antworten. Empfänger ist das E-Mail-Feld mit „Bestätigung an diese Adresse senden“.
- Absender: wird beim Anlegen aus den globalen E-Mail-Einstellungen **kopiert**. Spätere Änderungen der globalen Werte ändern bestehende Formulare nicht.

### Bestätigung

Nach dem Absenden eine Meldung anzeigen oder auf eine **veröffentlichte** Seite dieser Website weiterleiten.

### Formulareinstellungen

Texte, Zeitraum und Grenze, Pausenmeldung, Darstellung (Theme-Styling oder Form-Captain-Basisstil) und Aufbewahrung (global, 90 Tage, 12 Monate, manuell).

- **Texte:** Beschriftung der Absende-Schaltfläche (höchstens 60 Zeichen), Hinweis auf Pflichtfelder (ein `*` im Text wird als Pflichtfeld-Markierung angezeigt) und Überschrift der Fehlerübersicht. Leer = Standardtext in der Sprache der Website.
- **Zeitraum und Grenze:** „Einsendungen annehmen ab/bis“ (Zeitzone der Website), Höchstzahl Einsendungen (Spam und Papierkorb zählen nicht) und der Text, den Besucher stattdessen sehen. Ohne eigenen Text erscheint ein Standardtext (vor Beginn mit Datum).
- Die Vorgangsnummer (*Einstellungen → Allgemein*) steht in Betreff und Text der E-Mails und in der Erfolgsmeldung über `{entry_number}` zur Verfügung.

### Aktionen

Zeigt Aktionen aktivierter Connectoren (z. B. „Ticket anlegen“). Aktion hinzufügen, Einstellungen ausfüllen, Felder zuordnen (nur passende Feldtypen; sensible Felder sind gesperrt) und einschalten. Ändern erfordert zusätzlich das Recht *Integrationen verwalten*. Ohne aktive Connectoren ist die Liste leer.

## Speichern, Konflikte, Veröffentlichen

- **Entwurf speichern** (auch `Strg+S`) speichert den Arbeitsstand – auch mit Fehlern. Fehler werden am Feld bzw. Reiter markiert („(!)“).
- Hat jemand das Formular inzwischen geändert, erscheint eine **Konfliktmeldung**. „Neueste Version laden“ verwirft die eigenen Änderungen; alternativ Änderungen notieren und neu laden.
- **Veröffentlichen** ist erst möglich, wenn keine Fehler bestehen und mindestens ein Eingabefeld vorhanden ist. Jede Veröffentlichung erzeugt eine unveränderliche Version. Änderungen an einem veröffentlichten Formular werden erst mit „Änderungen veröffentlichen“ live; der Editor listet vorher entfernte Felder, neue Pflichtfelder und entfernte Optionen. Bestehende Einträge bleiben mit ihrer ursprünglichen Version lesbar.
- Status: Entwurf → Veröffentlicht ⇄ Pausiert; Veröffentlicht/Pausiert → Entwurf; jeder Status → Archiviert; Archiviert → Entwurf. Pausierte Formulare zeigen die Pausenmeldung und nehmen nichts an.

## Vorschau

„Vorschau“ speichert ungesicherte Änderungen und öffnet die Vorschau (Desktop/Mobil). Der Link ist zufällig, an dein Benutzerkonto gebunden und 30 Minuten gültig. In der Vorschau ist Absenden deaktiviert – es entstehen keine Einträge, E-Mails oder Aktionen.

## JSON-Export und -Import

- **Export** (Zeilenaktion „JSON exportieren“): enthält nur Titel, Schema und Formulareinstellungen – keine Einträge, Dateien, Benutzer-IDs, öffentliche Kennung oder Zugangsdaten. Aktionen werden deaktiviert exportiert.
- **Import** (unter der Formularliste „Formular aus JSON importieren“): `.json`-Datei bis 256 KB. Die Datei wird vollständig geprüft, bevor etwas gespeichert wird; ungültige oder zu neue Dateien werden abgelehnt. Texte werden bereinigt, ungültige Weiterleitungsseiten und Empfänger entfernt, Einwilligungs-Revisionen auf 1 gesetzt. Das Formular entsteht immer als **Entwurf** mit neuer Kennung, Aktionen bleiben aus. Der **Importbericht** listet Empfänger zur Prüfung sowie bereinigte und entfernte Werte.

## Hinweise zu Pro

Gelegentlich erscheint ein kurzer, wegklickbarer Hinweis auf Pro-Funktionen. Er lässt sich pro Benutzer schließen und global unter *Einstellungen → Allgemein* abschalten. Keine Free-Funktion ist gesperrt.
