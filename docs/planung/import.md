# Import aus „Shortcode-Formularformat Typ A“ (P9-05)

**Status:** Spezifikation für die Umsetzung in P9-06. Nach Free v1.0; blockiert kein Release.
**Benennung:** Das Quellformat wird projektweit neutral „Shortcode-Formularformat Typ A“ (kurz **Typ A**) genannt (konzept.md Abschnitt 3). Code, UI-Texte und Tests verwenden `type_a` bzw. „Typ A“; Produktnamen, Domains oder Logos der Quelle erscheinen nicht.
**Bezug:** [`../spec/formularschema.md`](../spec/formularschema.md), [`../spec/datenmodell.md`](../spec/datenmodell.md), [`../spec/bedrohungsmodell.md`](../spec/bedrohungsmodell.md) (T-JSON-01), [`../spec/rechte.md`](../spec/rechte.md).

## 1. Quellformat Typ A (technische Beschreibung)

- Formulare liegen in der Quellinstallation als eigener WordPress-Beitragstyp. Ein Formular besteht aus:
  - **Formularvorlage:** Freitext/HTML mit eingebetteten Feld-Tags in eckigen Klammern, z. B. `[text* firma placeholder "Firma"]`, `[email* kontakt-email]`, `[select anliegen "Angebot" "Support|support"]`, `[submit "Senden"]`.
  - **Mail-Vorlage 1** (Admin) und optional **Mail-Vorlage 2** (Bestätigung): Empfänger, Absender, Betreff, zusätzliche Header, Text, Platzhalter `[feldname]`.
  - **Meldungstexte** (Erfolg, Fehler, Validierung) und **Zusatzeinstellungen** (Schlüssel: Wert je Zeile).
- Tag-Syntax: `[typ(*)? name option… "wert"…]`. `*` = Pflicht. Optionen u. a. `placeholder`, `class:x`, `id:x`, `minlength:n`, `maxlength:n`, `min:n`, `max:n`, `step:n`, Größenangabe `40/100` (Breite/Maxlänge), `include_blank`, `multiple`, `use_label_element`, `label_first`, `exclusive`, `free_text`, `default:…`. Werte in Anführungszeichen sind Optionen bzw. Standardwert; `Label|Wert` trennt Anzeige und gespeicherten Wert („Pipe“).
- Das Format speichert keine Einsendungen selbst. Einsendungen existieren nur, wenn eine Speichererweiterung sie geführt und **exportiert** hat.

Der Leser für Typ A wird als Adapter `FormCaptain\Import\Source\TypeA` gekapselt. Speicherort-Details der Quelle (Beitragstyp-Name, Meta-Schlüssel) stehen ausschließlich in der Adapter-Konfiguration und werden vor Umsetzung gegen die aktuelle, öffentlich dokumentierte Struktur der Quelle verifiziert.

## 2. Importquellen

| Quelle | Inhalt | Voraussetzung |
|---|---|---|
| A) Gleiche Installation | Formulare aus Beitragstyp der Quelle lesen | Quellplugin darf aktiv oder inaktiv sein; Daten werden nur gelesen |
| B) Exportdatei der Quelle | WordPress-WXR-Export, der nur den Beitragstyp der Quelle enthält | Datei ≤ 5 MB, XML-Parser ohne externe Entitäten (XXE aus), keine Ausführung von Inhalten |
| C) Entry-Exportdatei | CSV einer dokumentierten Exportquelle (Abschnitt 7) | Formular wurde bereits importiert; Formatprofil vorhanden |

Keine anderen Formate, kein Direktzugriff auf Tabellen fremder Speichererweiterungen, kein Fernabruf über URL.

## 3. Feldzuordnung auf die 12 Free-Typen

| Typ-A-Tag | Form-Captain-Typ | Übernommene Optionen | Hinweis im Bericht |
|---|---|---|---|
| `text` | `text` | Pflicht, placeholder, Default, `minlength`/`maxlength` (bzw. Größenangabe), class | – |
| `email` | `email` | Pflicht, placeholder, maxlength ≤ 254 | erstes Pflicht-E-Mail-Feld: `privacy_lookup = true`; Reply-To-Zuordnung aus Mail-Header |
| `url` | `text` | wie `text`, `maxlength` 1000 | **angepasst:** keine URL-Validierung im Free |
| `tel` | `tel` | Pflicht, placeholder | Muster-Abweichungen werden nicht übernommen |
| `number` | `number` | `min`, `max`, `step` | – |
| `range` | `number` | `min`, `max`, `step` | **angepasst:** Schieberegler → Zahlenfeld |
| `date` | `date` | `min`, `max` | relative Angaben (`today`) übernommen, sonst `YYYY-MM-DD` |
| `textarea` | `textarea` | Pflicht, placeholder, minlength/maxlength (≤ 10000), Zeilen aus Größenangabe (2–20) | – |
| `select` | `select` | Optionen, `multiple`, Pipe-Werte | `include_blank` → kein Default; `free_text` **nicht unterstützt** |
| `checkbox` | `checkbox` | Optionen, Pipe-Werte | `exclusive` → `radio` (**angepasst**); einzelne Checkbox ohne Optionsliste → `checkbox` mit einer Option |
| `radio` | `radio` | Optionen, Pipe-Werte | Typ A erzwingt Vorauswahl; Default wird **nicht** übernommen, wenn er nur aus dieser Regel stammt |
| `acceptance` | `consent` | Text zwischen Start- und End-Tag → `consent_text` (HTML-Allowlist) | immer Pflicht, nie vorangekreuzt; `consent_revision = 1`; optionale Zustimmung → **angepasst** als `checkbox` |
| `hidden` | `hidden` | Default | `default:`-Quellen (z. B. Nutzer-/URL-Daten) **nicht unterstützt**, Wert leer |
| HTML zwischen Tags | `html` | bereinigt nach Allowlist (formularschema.md) | nur Blöcke mit sichtbarem Text; Layout-Wrapper (`div`, `p` ohne Text) entfallen |
| `submit` | – | Beschriftung → Formulareinstellung Button-Text | – |
| `quiz` | – | – | **nicht unterstützt**, Feld entfällt |
| `file` | – | – | **nicht unterstützt** im Free; Hinweis auf Pro-Upload (nur Hinweis, keine Konvertierung) |
| unbekannte Tags (Erweiterungen) | – | – | **nicht unterstützt**, Original-Tag im Bericht |

**Allgemeine Regeln**
- `id`: neu erzeugt (`f_` + Zufall); Zuordnung Quelle → ID im Importprotokoll (`fc_import_map`, siehe 8) für späteren Entry-Import.
- `key`: aus Tag-Name, kleingeschrieben, `-` → `_`, unzulässige Zeichen entfernt, muss mit Buchstabe beginnen, ≤ 40 Zeichen; Kollisionen → Suffix `_2`, `_3`.
- `label`: Text des umschließenden `<label>` bzw. des unmittelbar vorangehenden Textknotens (≤ 190 Zeichen, ohne HTML); sonst placeholder; sonst `key`. Herkunft der Beschriftung („abgeleitet“) im Bericht.
- `css_class`: nur Klassen nach Muster `^[A-Za-z0-9_\- ]{0,100}$`; `id:`-Option entfällt.
- Pflichtstatus aus `*`.
- Grenzen aus `budgets.md`: > 100 Felder oder > 200 Optionen → Import des Formulars abgelehnt (`import_limit_exceeded`), kein Teilimport.

## 4. Einstellungen, Mails und Regeln

| Typ-A-Bestandteil | Ziel | Regel |
|---|---|---|
| Titel | `fc_forms.title` | Suffix „(Import)“ |
| Mail 1 Empfänger | `notification.to` | nur gültige Adressen; Platzhalter mit Feldbezug → **nicht unterstützt** (feste Empfänger nötig), Fallback `{admin_email}` |
| Mail 1 Betreff/Text | `notification.subject` / Feldauswahl | Platzhalter `[name]` → `{key}`; unbekannte Spezial-Platzhalter entfernt und gemeldet. Freier Mailtext wird nicht übernommen, wenn Free nur Feldliste unterstützt → **angepasst** |
| Mail 1 `Reply-To: [feld]` | `notification.reply_to_field` | nur bei E-Mail-Feld |
| Mail 1 Absender | Formular-Kopie von `email.from_*` | nur wenn Domain = Site-Domain, sonst globaler Default + Warnung |
| Mail 1 weitere Header (CC/BCC) | – | **nicht unterstützt** |
| Mail 1 HTML-Option | `email.format` | – |
| Mail 2 aktiv | `confirmation_mail` | Empfänger muss `[email-feld]` sein → dieses Feld `confirmation_recipient = true`; Betreff/Text mit Platzhalter-Umsetzung |
| Mail-Anhänge | – | **nicht unterstützt** |
| Erfolgsmeldung | `confirmation.message` | Klartext |
| weitere Meldungstexte | – | **nicht übernommen** (Form Captain nutzt eigene, übersetzte Texte) |
| Zusatzeinstellungen (alle Schlüssel) | – | **nicht unterstützt**; jeder Schlüssel im Bericht, Werte **nicht** (können Skripte enthalten) |
| bedingte Anzeige | – | Typ A kennt keine; Erweiterungs-Syntax **nicht unterstützt** |
| Spamschutz der Quelle (Captcha-Tags, Blocklisten) | – | **nicht übernommen**; Form-Captain-Defaults (Honeypot, Mindestzeit, Rate-Limit) aktiv |

Importierte Formulare werden immer als **Entwurf** (`draft`) angelegt. Aktive Connector-Aktionen werden nie importiert.

## 5. Ablauf und Vorschau

Admin-Seite *Form Captain → Werkzeuge → Import* (Recht `form_captain_manage_forms`; Entry-Import zusätzlich `form_captain_edit_entries` und `form_captain_delete_entries` für Rücknahme).

1. **Quelle wählen** (A oder B). Bei A: Liste aller Typ-A-Formulare mit Titel, Anzahl Felder, Datum, Status „bereits importiert“ (aus `fc_import_map`).
2. **Analyse** (rein lesend, keine Schreibvorgänge): Parser erzeugt je Formular ein Kandidatenschema + Befundliste.
3. **Vorschau** je Formular:
   - Ampel: *vollständig* / *mit Anpassungen* / *mit Verlusten* / *nicht importierbar*.
   - Tabelle Quelle → Ziel: Original-Tag (escaped), Zieltyp, key, label, Befund.
   - Gerenderte Formularvorschau aus dem Kandidatenschema (Renderer im Vorschaumodus, keine Einsendung möglich).
   - Admin kann vor dem Import je Feld `label` und `key` ändern und Felder ausschließen. Weitere Bearbeitung danach im Editor.
4. **Import bestätigen** (Nonce): je Formular eigene Transaktion; Schema wird mit `SchemaValidator` geprüft; Fehler → dieses Formular übersprungen, andere laufen weiter.
5. **Bericht** anzeigen und speichern (Abschnitt 6).

Wiederholter Import desselben Quellformulars: Standard „als neues Formular“; Option „vorhandenen Import ersetzen“ nur, solange das Ziel ein Entwurf ohne Entries ist.

## 6. Konvertierungsbericht

- Gespeichert als Notiz/Datensatz zum Zielformular (sichtbar im Editor unter „Importbericht“), herunterladbar als JSON und lesbare Textdatei.
- Inhalt: Zeitpunkt, Benutzer-ID, Quellart (A/B), Quell-ID, Ziel-Form-ID, Plugin-Version, Zähler (Felder gesamt/übernommen/angepasst/entfallen), Liste der Befunde.
- Befund: `{severity: info|adjusted|lost|blocked, code, source_tag_name, target_field_id?, message}`; Codes z. B. `type_mapped`, `option_dropped`, `field_unsupported`, `placeholder_unknown`, `setting_unsupported`, `mail_header_dropped`, `label_derived`, `key_renamed`, `limit_exceeded`.
- Keine Feldwerte, keine E-Mail-Adressen außer den übernommenen Empfängerangaben, keine Inhalte von Zusatzeinstellungen.
- Abschluss-Checkliste für den Admin: Empfänger prüfen, Consent-Text prüfen, Formular testen, dann veröffentlichen, Einbettung auf der Seite austauschen (Shortcode/Block von Form Captain statt Quell-Shortcode).

## 7. Entries nur aus dokumentierten Exportdateien

- Entry-Import erfolgt **nur** aus Dateien, deren Format in einem **Formatprofil** dokumentiert ist: Spaltenstruktur, Trennzeichen, Kodierung, Datumsformat, Zeitzone, Mehrfachwert-Trennung. Profile liegen versioniert im Code (`src/Import/Profiles/`), mit Testdatei. Ohne passendes Profil kein Entry-Import.
- Mindestprofil v1: „Generisches CSV“ – UTF-8 (mit/ohne BOM), Kopfzeile, eine Zeile je Einsendung, Spalte für Einsendezeit (ISO 8601 oder `YYYY-MM-DD HH:MM:SS` + gewählte Zeitzone), Admin ordnet Spalten den Feldern des importierten Formulars zu.
- Datei ≤ 20 MB, ≤ 50 000 Zeilen; Verarbeitung batchweise (500 Zeilen) über die Queue (Job-Typ `import.entries`), fortsetzbar.
- Jede Zeile durchläuft die Feldvalidierung des Zielschemas im Modus „Import“ (Pflichtfelder dürfen fehlen; ungültige Werte → Zeile übersprungen, Befund mit Zeilennummer, **ohne** Wert).
- Importierte Entries: Status `read`, `created_gmt` aus Datei, `form_version_id` = Version beim Import, `source_url` = NULL, `user_id` = NULL, `context_json` = `{"import": "<import_id>"}`, `request_key` = SHA-256(`import_id` + Zeilennummer) → wiederholter Lauf erzeugt keine Duplikate. `expires_gmt` nach Retention ab **Original**-Einsendezeit; bereits abgelaufene Zeilen werden nicht importiert (Bericht: Anzahl).
- Für importierte Entries werden **keine** Mails, Connector-Jobs oder öffentlichen `entry_created_v1`-Events ausgelöst; stattdessen ein Event `form_captain_entries_imported_v1(import_id, form_id, count)` (Vertragserweiterung, in `api-v1.md` zu ergänzen).
- CSV-Formelzeichen in Werten bleiben als Text gespeichert; Export-Bereinigung greift wie bei allen Entries.
- Rücknahme: „Import zurücknehmen“ löscht alle Entries mit diesem `import_id` über den regulären `EntryDeletionService` (hard delete), solange nicht bearbeitet; Recht `form_captain_delete_entries`.
- Die hochgeladene Datei wird nur temporär im privaten Verzeichnis gehalten und nach Abschluss/Abbruch, spätestens nach 24 h, gelöscht.

## 8. Originale nie löschen

- Der Importer liest Quellformulare und Exportdateien ausschließlich. Er ändert, deaktiviert oder löscht keine Quellbeiträge, Metadaten, Optionen oder Tabellen.
- Kein automatisches Ersetzen von Quell-Shortcodes in Seiten. Optional (spätere Ausbaustufe, eigener Task): Liste der Seiten mit Quell-Shortcode als Hilfe für manuelles Austauschen.
- Zuordnungstabelle `fc_import_map` (`id`, `import_id` CHAR(32), `source_type` = `type_a`, `source_id`, `form_id`, `field_map_json` (Quellname → Feld-ID), `created_gmt`, `created_by`) – neue Tabelle, eigene Migration; enthält keine Nutzdaten.
- Deinstallation von Form Captain berührt Quelldaten nicht.

## 9. Sicherheit

- Parser ist ein eigener Tokenizer für Tags, **kein** Aufruf von `do_shortcode()` oder Quellcode; Inhalte werden nie ausgeführt.
- HTML-Anteile ausschließlich über die Allowlist (`wp_kses`) ins `html`-Feld bzw. `consent_text`.
- WXR-Import: `libxml` ohne Netzwerkzugriff und externe Entitäten, Größen- und Tiefenlimit; nur Elemente des Typ-A-Beitragstyps werden gelesen.
- Alle Schritte mit Nonce + Capability; Vorschau rendert escaped.
- Logs enthalten nur Codes, Zähler und IDs (P5-09).

## 10. Tests

| ID | Art | Fall | Erwartung |
|---|---|---|---|
| IMP-01 | unit | Tokenizer: alle Tags aus Abschnitt 3 mit Optionen, Anführungszeichen, Pipes, Escape-Sequenzen | korrekte Token-Liste |
| IMP-02 | unit | Mapping je Tag-Typ (Tabelle 3) | Zieltyp, Optionen, Befundcode wie spezifiziert |
| IMP-03 | unit | `key`-Normalisierung und Kollisionen | gültige, eindeutige Keys |
| IMP-04 | unit | Label-Ableitung (umschließendes Label, vorangehender Text, Fallbacks) | erwartete Labels, Befund `label_derived` |
| IMP-05 | unit | Mail-Platzhalter, Reply-To, Mail 2 | korrekte `settings_json`; unbekannte Platzhalter gemeldet |
| IMP-06 | unit | Kandidatenschema aller Fixtures gegen `SchemaValidator` | gültig |
| IMP-07 | unit | Grenzfälle: 101 Felder, 201 Optionen, leere Vorlage, nur unbekannte Tags | abgelehnt bzw. „nicht importierbar“, kein Teilimport |
| IMP-08 | unit | bösartige Eingaben: `<script>`, Event-Handler, `javascript:`-Links, verschachtelte Klammern, 5-MB-Vorlage | bereinigt bzw. abgelehnt, keine Ausführung |
| IMP-09 | integration | Quelle A: Fixture-Beiträge vorhanden; Import | Formulare als `draft`, `fc_import_map` befüllt, **Quellbeiträge/Meta byte-identisch** (Hash vorher/nachher) |
| IMP-10 | integration | Quelle B: WXR-Fixture inkl. XXE-Versuch | Import ok, XXE wirkungslos |
| IMP-11 | integration | Entry-CSV 1 000 Zeilen, 10 ungültig, 5 abgelaufen | 985 Entries, Bericht mit Zeilennummern ohne Werte, keine Mails/Jobs |
| IMP-12 | integration | Entry-Import zweimal ausführen | keine Duplikate (`request_key`) |
| IMP-13 | integration | Abbruch nach Batch 1, Wiederaufnahme | vollständig, keine Duplikate |
| IMP-14 | integration | Import zurücknehmen | alle importierten Entries gelöscht, andere unberührt |
| IMP-15 | integration | Rechte: Editor/Autor ruft Import-Endpunkte auf | `forbidden` |
| IMP-16 | e2e | Analyse → Vorschau → Label ändern → Import → Bericht → Editor → veröffentlichen → Testeinsendung | durchgängig erfolgreich |
| IMP-17 | manuell | Fixtures aus realen, anonymisierten Formularen (≥ 10, inkl. mehrsprachiger Texte) | Befunde plausibel, keine Abstürze |

Fixtures werden selbst erstellt (Syntax nach Abschnitt 1), keine Kopien fremder Beispielformulare.

## 11. Offene Punkte

- Verifikation der aktuellen Speicherstruktur der Quelle (Beitragstyp, Meta-Schlüssel) vor Umsetzung; Abweichung → Adapter-Konfiguration anpassen.
- Freitext-Mailinhalte: Free unterstützt nur Feldliste; Entscheidung, ob ein einfacher Mailtext mit Platzhaltern in den Free-Kern kommt (Produktentscheidung).
- Welche Entry-Exportformate über „Generisches CSV“ hinaus ein eigenes Profil erhalten (nur auf Nachfrage, je Profil dokumentiert und getestet).
- Event `form_captain_entries_imported_v1` in `api-v1.md` aufnehmen (Minor-Vertragserweiterung).
