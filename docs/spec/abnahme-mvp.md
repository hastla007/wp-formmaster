# Abnahmekriterien Free MVP v1.0 (P0-11)

**Status:** Entwurf zur Freigabe · **Stand:** 16.11.2026 · **Gilt für:** Form Captain Free v1.0
**Bezug:** konzept.md 1.1 (MVP v1.0), 4 (Free v1.0), 4.1, 6, 6.1, 6.2, 7, 11 · TODO P0-11, Phasen 1–6
**Verwandte Specs:** `datenschutz.md` (DS-*), `bedrohungsmodell.md` (T-*), `rechtevertrag.md` (OP-*, RL-*), `suite-kontext.md` (TC-CTX-*)

## Konventionen

- **Typ:** `unit` (PHPUnit ohne WordPress-DB bzw. Jest), `integration` (WP-PHPUnit mit DB, REST-Requests über `rest_do_request`), `e2e` (Playwright gegen WordPress-Testinstanz), `manuell` (Checkliste mit Protokoll im Release-Ticket).
- **CI:** Alle `unit`/`integration` laufen in jeder PR; `e2e` in jeder PR auf der Hauptmatrix (WP 7.0 + aktuell, PHP 8.5 + aktuell), vollständig vor Release. `manuell` vor jedem Release-Kandidaten.
- **Testdaten:** Fixture-Formulare `contact`, `quote`, `support` (aus Vorlagen), `all-fields` (alle 12 Feldtypen), `conditional` (eine Regel), Nutzer `admin`, `editor`, `author`, `subscriber`, `fc_reader` (nur `form_captain_read_entries`).
- Ein Punkt ist erfüllt, wenn das erwartete Ergebnis vollständig eintritt; Teilerfüllung = nicht erfüllt.
- Testdateien referenzieren die AC-ID im Testnamen (`test_AC_031_…`, `AC-031: …`).

---

## 1. Installation, Aktivierung, Plattform

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-001 | integration | Plugin auf leerer WP 7.0 / PHP 8.5 aktivieren | Tabellen `fc_forms`, `fc_form_versions`, `fc_entries`, `fc_entry_values`, `fc_entry_notes`, `fc_jobs`, `fc_files`, `fc_rate_limits`, `fc_log` existieren mit Präfix; gespeicherte Schema-Version = aktuelle; keine PHP-Notices |
| AC-002 | integration | Frische Installation vs. Upgrade von ältester unterstützter Schema-Version vergleichen (`SHOW CREATE TABLE`) | identisches Schema (Spalten, Indizes, UNIQUE) |
| AC-003 | integration | Aktivierung zweimal ausführen | idempotent, keine doppelten Optionen/Caps/Events |
| AC-004 | integration | Aktivierung prüft Capabilities | `administrator` hat alle 7 `form_captain_*`-Caps; `editor`, `author`, `contributor`, `subscriber` keine |
| AC-005 | integration | Deaktivieren | Cron-Events `form_captain_process_jobs`, `form_captain_daily_cleanup`, `form_captain_hourly_cleanup` entfernt; alle Tabellen/Daten unverändert |
| AC-006 | integration | Plugin auf PHP < 8.5 bzw. WP < 7.0 laden (Mock der Versionsprüfung) | kein Fatal Error; Admin-Hinweis zur Mindestversion; keine Hooks/Routen registriert |
| AC-007 | integration | Multisite-Installation | Aktivierung verweigert/Hinweis „Multisite nicht unterstützt in v1.0“; kein Netzwerk-Aktivieren |
| AC-008 | integration | Plugin ohne WooCommerce und ohne Yoast | alle Funktionen verfügbar, keine Warnungen |
| AC-009 | manuell | Release-ZIP installieren (Upload über Plugins → Installieren) | Aktivierung erfolgreich; ZIP enthält keine `tests/`, `node_modules/`, `.git`, Dev-Vendor, Secrets |
| AC-010 | unit | Statische Prüfung: keine Lizenzprüfung, kein Updater, kein Remote-Call an Lizenzserver im Free-Code (Grep auf Klassen/URLs der Captain-Pro-Clients) | keine Treffer |

## 2. Installationsassistent und Dashboard

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-011 | e2e | Erstaufruf nach Aktivierung als Admin | Assistent mit 6 Schritten (konzept 6 „Inhalt der Ersteinrichtung“) erscheint; überspringbar |
| AC-012 | e2e | Schritt 1 „Kontakt“ wählen | Formular „Kontakt“ als `draft` angelegt |
| AC-013 | e2e | Schritt 2 Empfänger/Absender | Default = Admin-E-Mail / Site-Domain; ungültige Adresse wird abgelehnt |
| AC-014 | e2e | Schritt 3 „Neue Seite erstellen“ | Seite (Entwurf) mit Form-Captain-Block erstellt; keine Seite ohne Aktion angelegt |
| AC-015 | e2e | Schritt 4 Testeinsendung | Entry in Liste sichtbar; Mail-Job-Status angezeigt (gesendet / Fehlercode) |
| AC-016 | e2e | Schritt 5 Retention | Auswahl 90 Tage / 12 Monate (vorgewählt) / manuell; Wert in `form_captain_settings[retention_mode]` |
| AC-017 | e2e | Schritt 6 Suite-Erkennung ohne Suite-Plugins | Hinweis „keine erkannt“, keine Aktivierung |
| AC-018 | manuell | Neuer Nutzer veröffentlicht Beispiel-Kontaktformular ohne Dokumentation (konzept 11) | 3 von 3 Testpersonen schaffen es in ≤ 10 min |
| AC-019 | e2e | Dashboard | zeigt aktive Formulare, neue Einsendungen, letzte Formulare, Schnellaktionen; kein globales Upgrade-Banner |

## 3. Formularübersicht und Lebenszyklus

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-020 | e2e | Formularliste mit 30 Formularen | Name, Status, Einbettungshinweis, Entry-Anzahl, Änderungsdatum; Suche, Statusfilter, Pagination, leerer Zustand |
| AC-021 | integration | Alle Formular-Übergänge aus `zustaende.md` sowie unzulässige (z. B. archived→published, archived→paused) | erlaubte gelingen; unzulässige `conflict` |
| AC-022 | integration | Veröffentlichen eines ungültigen Formulars (Pflicht-Label fehlt) | `schema_invalid`; Status bleibt `draft` |
| AC-023 | integration | Veröffentlichen | neuer unveränderlicher Snapshot in `fc_form_versions`; erneutes Veröffentlichen nach Änderung erzeugt Version n+1; alte Version unverändert |
| AC-024 | e2e | Pausiertes Formular im Frontend | neutrale, konfigurierbare Meldung; Submission → `form_not_accepting` |
| AC-025 | integration | Duplizieren | neue `public_id`, Status `draft`, gleiche Feld-IDs, Aktionen deaktiviert, keine Entries kopiert |
| AC-026 | integration | Formular mit Entries löschen ohne `delete_entries=true` | `conflict`; mit Parameter + Capability: Formular, Versionen, Entries gelöscht |
| AC-027 | integration | Jede Formularaktion ohne Nonce bzw. ohne `form_captain_manage_forms` | `invalid_nonce` bzw. `forbidden`; keine Änderung (P2-03) |

## 4. Vorlagen und Editor

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-028 | unit | Vorlagen `contact`, `quote`, `support` gegen JSON-Schema validieren | gültig, versioniert, stabile Feld-IDs |
| AC-029 | e2e | Formular aus jeder Vorlage erstellen und veröffentlichen | ohne Nacharbeit veröffentlichbar und absendbar |
| AC-030 | e2e | Feld hinzufügen, duplizieren, verschieben, entfernen, speichern, neu laden | Reihenfolge korrekt; bestehende Feld-IDs unverändert; Duplikat erhält neue ID |
| AC-031 | e2e | Editor nur mit Tastatur bedienen (Feld hinzufügen, Einstellungen, Sortieren, Speichern) | alle Aktionen ohne Maus möglich; sichtbarer Fokus |
| AC-032 | e2e | Desktop-/Mobil-Vorschau | Umschaltung zeigt beide Layouts; keine Entry-/Mail-/Job-Erzeugung beim Absenden in der Vorschau (P2-14) |
| AC-033 | integration | Zwei Sessions speichern dasselbe Formular nacheinander mit alter `schema_revision` | zweite Speicherung `revision_conflict` mit verständlicher Meldung |
| AC-034 | unit | Feldeinstellungen: Label, Key (`^[a-z][a-z0-9_]{0,39}$`, eindeutig, `formularschema.md`), Pflicht, Default, Hilfetext, Platzhalter, Min/Max, CSS-Klasse (`^[A-Za-z0-9_ -]*$`) | ungültige Werte → Feldfehler im Editor und `schema_invalid` serverseitig |
| AC-035 | unit | HTML-Hinweis mit `<script>`, `onerror`, `javascript:`-Link, `<iframe>` speichern | nur Allowlist (T-ADM-04) bleibt; Ausgabe identisch bereinigt |
| AC-036 | integration | Vorschau-Link ohne Login / als `editor` / nach 30 min | kein Zugriff, kein Schema offengelegt (P2-15) |
| AC-037 | integration | Feld entfernen, nach Veröffentlichung Entry der alten Version öffnen | alter Entry zeigt Label aus alter Version; entferntes Feld nicht neuen Werten zugeordnet (P2-16) |

## 5. Feldtypen und Servervalidierung

Je Feldtyp ein Unit-Test für den Validator und ein Integrationstest über die REST-Submission.

| ID | Typ | Feldtyp / Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-038 | unit+integration | Einzeiliger Text: leer (Pflicht), > maxlength, Steuerzeichen, gültig | `required` / `too_long` / Steuerzeichen entfernt bzw. abgelehnt / gespeichert |
| AC-039 | unit+integration | Absatz: Zeilenumbrüche, 5 001 Zeichen (Default-Limit) | Umbrüche erhalten; `too_long` |
| AC-040 | unit+integration | E-Mail: `a@b`, `a@b.de`, mit CRLF, IDN | `invalid_email` für ungültige; CRLF abgelehnt; gültige gespeichert (normalisiert lowercase für die Privacy-Suche) |
| AC-041 | unit+integration | Telefon: Buchstaben, `+49 30 123456`, > 32 Zeichen | nur `[0-9 +()/.-]`; Länge begrenzt |
| AC-042 | unit+integration | Zahl: Text, Dezimal, außerhalb min/max, Schrittweite | `invalid_number` / `out_of_range`; Speicherung normalisiert (Punkt als Dezimaltrenner) |
| AC-043 | unit+integration | Auswahl: Wert nicht in Optionen, Array statt Skalar | `invalid_option` / `validation_failed` |
| AC-044 | unit+integration | Checkbox (Mehrfach): unbekannte Option, Duplikate, Min/Max Auswahl | `invalid_option`; Duplikate entfernt; Grenzen geprüft |
| AC-045 | unit+integration | Optionsfeld: zwei Werte, unbekannter Wert | `validation_failed` / `invalid_option` |
| AC-046 | unit+integration | Datum: `2026-02-30`, anderes Format, außerhalb min/max | `invalid_date` / `out_of_range`; Speicherung `Y-m-d` |
| AC-047 | unit+integration | Überschrift/HTML-Hinweis: Wert mitsenden | ignoriert, nicht gespeichert |
| AC-048 | unit+integration | Versteckter Wert: Browser sendet abweichenden Wert | gespeicherter Wert = Schema-Default (T-SUB-04), sofern Feld nicht als übergebbar markiert |
| AC-049 | unit+integration | Einwilligung: Schema mit `default` / Pflicht ohne Häkchen / mit Häkchen | `schema_invalid` / `consent_required` / `consent_json` mit `given`, `text_hash`, `revision`, `form_version`, `given_at` (DS-C-01…05) |
| AC-050 | integration | Unbekannte Feld-ID, verschachteltes Array (Tiefe 3), > 100 Felder | `validation_failed` (`unknown_field`) bzw. `too_many_fields` |
| AC-051 | e2e | Browser-Validierung deaktiviert (`novalidate` per DevTools) | Serverfehler werden inline am Feld angezeigt; Eingaben bleiben erhalten |

## 6. Einfache bedingte Anzeige

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-052 | unit | Regel „Feld B anzeigen, wenn Feld A = ‚Ja‘“ für alle Operatoren aus `formularschema.md` (`equals`, `not_equals`, `contains`, `is_empty`, `is_not_empty`, `checked`) und `action` `show`/`hide` | korrekte Sichtbarkeit je Operator |
| AC-053 | unit | Zweite Regel je Feld, `condition_group` (Pro) ohne Pro, Regel auf sich selbst, Regel auf unbekanntes Feld | `schema_invalid` |
| AC-054 | integration | Ausgeblendetes Pflichtfeld leer senden | gültig; Wert eines ausgeblendeten Feldes wird verworfen |
| AC-055 | integration | Client sendet Werte für Feld, das laut Serverauswertung ausgeblendet ist | Wert nicht gespeichert |
| AC-056 | e2e | Umschalten im Frontend mit Tastatur und Screenreader | Feld erscheint/verschwindet, `aria-live`-Ansage, Fokus bleibt erhalten |

## 7. Einbettung, Renderer, Frontend

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-057 | e2e | Gutenberg-Block einfügen, Formular wählen | Editor-Vorschau; Frontend-Ausgabe identisch mit Shortcode |
| AC-058 | e2e | Block mit gelöschtem/pausiertem Formular | Editor: verständlicher Hinweis; Frontend: keine Fehlerausgabe/neutrale Meldung |
| AC-059 | integration | Shortcode mit gültiger `public_id`, ungültiger, numerischer ID, XSS im Attribut | Ausgabe / leer / leer / escaped |
| AC-060 | integration | HTML-Ausgabe prüfen | `<form>` mit `<label for>`, Pflichthinweis sichtbar, `aria-describedby` für Hilfe/Fehler, kein Inline-Event-Handler |
| AC-061 | e2e | Seite ohne Formular vs. mit Formular: geladene Assets | Form-Captain-CSS/JS nur auf Seite mit Block/Shortcode; keine externen Fonts/Skripte |
| AC-062 | e2e | Zwei Formulare auf einer Seite | beide unabhängig absendbar; IDs im DOM eindeutig |
| AC-063 | e2e | JavaScript deaktiviert | Absenden über `admin-post.php?action=form_captain_submit` funktioniert; Erfolg/Fehler sichtbar; Werte bei Fehler erhalten |
| AC-064 | e2e | Seite mit Vollseiten-Cache (z. B. statisches Cache-Plugin), zwei Browser | beide erhalten eigenes Token über Token-Route; Absenden erfolgreich; Token-Antwort mit `Cache-Control: no-store` |
| AC-065 | e2e | No-JS mit 2 h + 1 min altem, gecachtem Token | Formular wird mit Werten und frischem Token neu gerendert; zweiter Versuch erfolgreich |
| AC-066 | manuell | Theme-Vererbung in 2 Standard-Themes (Twenty Twenty-Five + klassisches Theme) | Formular übernimmt Theme-Farben/Schrift; Basisdarstellung optional; Reset funktioniert |
| AC-067 | integration | Erfolg: Meldung vs. Redirect auf Seite (page_id) | JSON enthält `message` bzw. `redirect_url` = Permalink; No-JS: 303 |
| AC-068 | integration | Redirect auf externe URL konfigurieren; Request mit `redirect_to`-Parameter | Speichern abgelehnt; Parameter ignoriert (T-RED-01/02) |

## 8. Submission, Spamschutz, Rate-Limit

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-069 | integration | Submission ohne Token / manipuliert / abgelaufen (> 2 h) / für anderes Formular → `invalid_token` (400), kein Entry. Zu jung (< Mindestzeit) → als Spam `too_fast` gespeichert, neutrale Erfolgsmeldung, keine Jobs |
| AC-070 | integration | Honeypot befüllt | Antwort wie Erfolg; Entry Status `spam`, Spamgrund `honeypot`; keine Jobs |
| AC-071 | integration | Blocklisten-Wort im Text / gesperrte E-Mail-Domain | `spam`, Grund `blocklist`; keine Jobs |
| AC-072 | integration | 6 Submissions in 10 min von gleicher IP an ein Formular | 6. → `rate_limited` (429) mit `Retry-After` (RL-02) |
| AC-073 | integration | `fc_rate_limits` nach Submission prüfen | nur HMAC-Bucket, keine rohe IP; nach Salt-Rotation anderer Hash |
| AC-074 | integration | Body > 64 KB (Default) bzw. > 256 KB (Maximum, per Filter nicht überschreitbar); 101 Felder | `payload_too_large` (413) bzw. `too_many_fields`, kein Entry |
| AC-075 | integration | Zweimal mit demselben Submission-Token absenden (`request_key` UNIQUE) | ein Entry; zweite Antwort `duplicate_request`, gleiche Antwortdaten; keine zweiten Jobs |
| AC-076 | integration | Erfolgsantwort prüfen | keine Entry-ID, keine Feldwerte; `request_id` vorhanden |
| AC-077 | integration | Fehlerantwort bei provozierter DB-Exception | `internal_error`, generische Meldung, keine SQL/Pfade; Details in `fc_log` |
| AC-078 | integration | `OPTIONS` und `GET` auf Submission-Route, `POST` mit fremdem `Origin` | kein CORS-Freigabeheader; `GET` → 405 |
| AC-079 | integration | Draft-, archiviertes, nicht existentes Formular adressieren | identisch `form_not_found` |
| AC-080 | integration | Entry-Commit, dann Prozessabbruch vor Job-Ausführung simulieren | Entry vorhanden; Jobs `pending` vorhanden (Outbox); nach Worker-Lauf zugestellt |
| AC-081 | integration | Mailversand schlägt fehl (`wp_mail` → false) | Entry gespeichert; Job `failed` mit Backoff; Submission-Antwort Erfolg (konzept 11) |
| AC-082 | manuell | Captcha-Anbieter | standardmäßig keiner aktiv; keine Anbieter-Requests im Netzwerk-Tab |

## 9. Entries verwalten

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-083 | e2e | Entry-Liste mit 1 000 Entries: Suche, Formularfilter, Datumsfilter, Statusfilter, Pagination | korrekte Treffer; Antwortzeit < 1 s (Testsystem) |
| AC-084 | e2e | Entry-Detail | Werte, Formular, Version, Zeitstempel (Site-Zeitzone), Status, Notizen, Spamgrund, Mail-Status; keine IP/User-Agent |
| AC-085 | e2e+integration | Detailansicht als `fc_reader` öffnen; danach REST-`GET` allein | Auto-Read setzt `new` → `read` (nur `read_entries` nötig); reines `GET` ändert keinen Status |
| AC-086 | integration | Statuswechsel new/read/archived/spam/trash + Restore | erlaubte Übergänge; `form_captain_entry_status_changed_v1` feuert nach Commit mit altem/neuem Status |
| AC-087 | integration | Bulk-Aktion mit 3 gültigen + 1 fremden/nicht existenten ID | 3 ausgeführt, 1 in `skipped` mit Code |
| AC-088 | integration | Notiz anlegen, Entry-Werte vergleichen | Werte unverändert; Notiz mit Autor/Zeit; Plaintext escaped |
| AC-089 | integration | Papierkorb: `trashed_gmt` = −31 Tage, Cleanup ausführen | Entry, Werte, Notizen gelöscht; Jobs `cancelled`; `form_captain_entry_deleted_v1` mit `reason = retention` |
| AC-090 | integration | Endgültig löschen mit offenem Mail-Job | Job `cancelled`; Worker versendet nichts (P4-18) |
| AC-091 | integration | Gespeicherte XSS-Payload in Feldwert in Liste/Detail/CSV | escaped dargestellt; im CSV als Text |

## 10. E-Mail

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-092 | integration | Admin-Mail mit Empfänger, Reply-To-Feld, Betreff mit Platzhalter, Feldauswahl | Mail (abgefangen via `pre_wp_mail`) enthält nur gewählte Felder; Reply-To = Einsenderadresse; From = Einstellung |
| AC-093 | integration | Reply-To-Feldwert / Betreff-Platzhalter mit `\r\nBcc: x@y` | kein zusätzlicher Header; Reply-To entfällt; `mail_header_rejected` im Log (T-MAIL-01) |
| AC-094 | integration | Feld mit `sensitive=true` | nicht in Mail, sofern nicht explizit gewählt |
| AC-095 | integration | Receipt aktiv: gültige Adresse / ungültige / Spam / 4. Receipt an gleiche Adresse in 1 h | gesendet / kein Receipt / kein Receipt / kein Receipt (RL-05) |
| AC-096 | integration | Doppelte Worker-Ausführung desselben Mail-Jobs (paralleler Claim) | genau eine Mail (Idempotenzschlüssel + `lock_token`) |
| AC-097 | integration | WP-Cron deaktiviert (`DISABLE_WP_CRON`), Submission | Entry gespeichert; Systemstatus warnt; nach manuellem `wp cron event run form_captain_process_jobs` zugestellt |
| AC-098 | integration | Mail-Job 8× fehlgeschlagen | Status `dead`; Retry über OP-ENT-11 setzt `pending`, `attempts = 0`, Log mit Nutzer |
| AC-099 | e2e | Testmail unter Einstellungen → E-Mail | Versandergebnis angezeigt; 6. Test in 10 min `rate_limited` |
| AC-100 | integration | HTML-Mail mit HTML in Feldwert | escaped; Klartextteil vorhanden |

## 11. CSV-Export

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-101 | unit | Zellen `=1+1`, `+A1`, `-2+3`, `@SUM`, `\tX`, `\rX`, `＝X`, ` =X`, Zahlfeld `-5` | Präfix `'` außer Zahlfeld `-5` (T-CSV-01); Kopfzeilen ebenso |
| AC-102 | unit | Werte mit `,`, `"`, Zeilenumbruch, Umlaut, Emoji | RFC-4180-korrekt; UTF-8 mit BOM; CRLF |
| AC-103 | integration | Spaltenauswahl + Datumsfilter + Status | nur gewählte Spalten/Zeilen; gelöschte nicht enthalten; anonymisierte mit Platzhalter |
| AC-104 | integration | Export ohne Nonce / ohne `export_entries` / `GET` | `invalid_nonce` / `forbidden` / 405 |
| AC-105 | integration | 50 000 Entries exportieren, Speicher messen | Peak-Speicher < 64 MB; Batches à 500; keine temporäre Datei im Dateisystem |
| AC-106 | manuell | CSV in LibreOffice und Excel öffnen | Umlaute korrekt; keine Formel ausgeführt |
| AC-107 | integration | `fc_log` nach Export | `export_created` mit Nutzer, Formular, Zeilenzahl, ohne Werte |

## 12. Formular-JSON-Import/-Export

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-108 | integration | Export → Import auf zweiter Installation | identisches Schema (Feld-IDs gleich), neue `public_id`, Status `draft`; keine Entries/Secrets |
| AC-109 | integration | Exportdatei auf Entries, Secrets, interne IDs prüfen | nicht enthalten (T-JSON-06) |
| AC-110 | integration | Import: 2 MB Datei, Tiefe 40, ungültiges Schema, Consent mit `default`, externer Redirect, `<script>`-Label, serialisierter PHP-String | abgelehnt bzw. bereinigt mit Importbericht; nie `unserialize` |
| AC-111 | e2e | Importbericht | listet Empfängeradressen und entfernte Werte zur Prüfung |

## 13. Datenschutz

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-112 | unit | Retention-Berechnung: `created_gmt` 2026-01-31 + P12M, 2024-02-29 + P12M, + P90D über Zeitumstellung | 2027-01-31 / 2025-03-01 / exakt 90 × 86 400 s (UTC) |
| AC-113 | integration | Retention von 12 Monate auf 90 Tage ändern bei Entries älter 100 Tage | Vorschau-Modal mit Anzahl/Datum; nach Bestätigung `expires_gmt = now + 14 Tage` (Schonfrist) |
| AC-114 | integration | Cleanup mit fälligen Entries (600 Stück) | Löschung in Batches à 200, Laufzeit je Lauf ≤ 20 s; `entry_deleted_v1` mit `reason = retention`; Wiederaufnahme nach Abbruch |
| AC-115 | e2e | Dashboard-Hinweis bei Entries mit Ablauf ≤ 14 Tage | Text DS-T-10 mit Anzahl/Datum; nur für `read_entries` sichtbar |
| AC-116 | integration | Modus `manual` | `expires_gmt = NULL`; Cleanup löscht nur Papierkorb/Spam |
| AC-117 | integration | Privacy-Export für `max@example.org` mit 3 passenden Entries (E-Mail-Feld, Großschreibung abweichend, `user_id`) und 1 Entry mit Adresse nur in Freitext | 3 Items mit Labels der Entry-Version; Freitext-Entry nicht enthalten; Feld mit `privacy_lookup=false` nicht berücksichtigt; Abfrage nutzt Index `field_value` |
| AC-118 | integration | Eraser Modus `delete` | Entries/Werte/Notizen weg, Jobs `cancelled`, `items_removed = true` |
| AC-119 | integration | Eraser Modus `anonymize` | personenbezogene Werte ersetzt, Auswahlwerte + Consent-Nachweis erhalten, `user_id` NULL, zweiter Exporter-Lauf findet nichts |
| AC-120 | e2e | Consent-Feld im Frontend | nie vorausgewählt (auch mit `?consent=1`, Vorausfüllung eingeloggter Nutzer); Link zur Datenschutzseite |
| AC-121 | integration | Consent-Text ändern und veröffentlichen | `consent_revision` +1; alte Entries verweisen auf alte Revision; Detail zeigt alten Wortlaut |
| AC-122 | integration | Deinstallation ohne Flag | alle Tabellen/Optionen bleiben; Cron-Events entfernt |
| AC-123 | integration | Deinstallation mit Flag, aber ohne Bestätigung | wie AC-122 |
| AC-124 | integration | Deinstallation mit Flag + Bestätigung | alle `fc_*`-Tabellen, `form_captain_*`-Optionen/User-Meta/Caps entfernt; keine fremden Tabellen/Optionen berührt |
| AC-125 | integration | Logs, Jobs, Optionen, Systemstatus nach 100 Submissions nach Feldwerten/E-Mail/IP durchsuchen | keine Treffer (DS-06, P5-09) |
| AC-126 | integration | Datenschutzerklärungs-Leitfaden | Text DS-T-70 unter Einstellungen → Datenschutz → Leitfaden vorhanden |
| AC-127 | manuell | Backup einspielen, das abgelaufene Entries enthält | Systemstatus-Hinweis DS-T-31; nächster Cleanup löscht abgelaufene Entries |

## 14. Rechte und Sicherheit

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-128 | integration | Datengetriebener Test über **jede** Zeile in `rechtevertrag.md` 6 mit Nutzern `admin`, `editor`, `author`, `subscriber`, `fc_reader`, anonym | Ergebnis exakt laut Capability-Spalte (`forbidden`/`unauthenticated`/Erfolg) |
| AC-129 | integration | Jede schreibende Admin-Route ohne / mit falschem Nonce | `invalid_nonce`, keine Änderung |
| AC-130 | integration | IDOR: Entry-ID eines anderen Formulars unter `/admin/forms/{id}/…`-bezogener Route, Notiz-ID eines anderen Entries | `not_found` |
| AC-131 | integration | Admin-Route mit Application Password | `forbidden` |
| AC-132 | integration | SQL-Injection-Payloads in Suche, Filter, Sortierung | keine Fehler, keine Datenpreisgabe; Sortierung nur aus Allowlist |
| AC-133 | integration | Rollen-Tab: Capability an `editor` vergeben, ohne `promote_users` | `forbidden`; Administrator kann eigene Caps nicht entfernen |
| AC-134 | e2e | `editor`-Login | Menü „Form Captain“ unsichtbar (ohne zugewiesene Caps); direkte URLs der Admin-Seiten verweigert |
| AC-135 | manuell | Unabhängiges Sicherheitsreview (P5-12) über Submission, Admin, Export, CSV, Import, Cron, Mail, Löschung | alle Befunde ≥ mittel geschlossen und dokumentiert |

## 15. Queue und Betrieb

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-136 | integration | Zwei Worker gleichzeitig (zwei Prozesse) auf 20 Jobs | jeder Job genau einmal `succeeded`; keine doppelte Ausführung |
| AC-137 | integration | Worker stirbt während `running` (`locked_until_gmt` abgelaufen, 5 min) | Job wird `failed`/`lock_expired` und erneut geclaimt; `attempts` erhöht |
| AC-138 | unit | Backoff-Formel für Versuche 1–8 (`zustaende.md`: `min(2^attempts × 60 s, 6 h)` ± 10 %) | Werte innerhalb der Toleranz, Deckel 6 h; nach Versuch 8 `dead` |
| AC-139 | integration | Doppeltes Einreihen mit gleichem Idempotenzschlüssel | UNIQUE verhindert zweiten Job |
| AC-140 | e2e | Systemstatus | WP/PHP-Version, Schema-Version, Cron-/Queue-Status (Rückstand, `dead`), Mail-Hinweis, erkannte Suite-Plugins; keine Secrets/Werte |
| AC-141 | integration | Fehlercode + `request_id` aus öffentlicher Antwort in `fc_log` auffindbar | Eintrag mit Code, Korrelations-ID, ohne Werte (P6-19) |

## 16. Contract v1

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-142 | integration | `FORM_CAPTAIN_CONTRACT_VERSION` | `'1.0.0'` |
| AC-143 | integration | `form_captain_loaded_v1` feuert genau einmal nach Initialisierung | Listener-Zähler = 1 |
| AC-144 | integration | Events `form_created_v1`, `form_updated_v1`, `entry_created_v1`, `entry_status_changed_v1`, `entry_deleted_v1` mit Rollback-Simulation | feuern nur nach Commit; bei Rollback nicht; Payload nur Allowlist (IDs, Form-ID, Zeitstempel, Status), keine Werte/IP |
| AC-145 | integration | `form_captain_get_form_v1` als anonym / als Admin für Draft | öffentliche Projektion ohne Empfänger / Draft verfügbar |
| AC-146 | integration | `form_captain_get_entry_v1` anonym / als `fc_reader` / im Job-Grant mit nicht gemapptem Feld-Scope / unbekannter Scope | `forbidden` / `meta`+`values` erlaubt, `sensitive` ohne `export_entries` `forbidden` / `forbidden` / `invalid_scope` |
| AC-147 | integration | `form_captain_submit_v1` mit gültigem Payload, doppeltem `idempotency_key`, ohne `source` | `created` + `entry_id` / `duplicate_request` gleiche `entry_id` / `invalid_request` |
| AC-148 | integration | Testadapter registriert Aktion vor `form_captain_loaded_v1` / mit `contract = '^2.0'` | `not_ready` / `incompatible_contract` in Diagnose; Aktion nicht wählbar |
| AC-149 | integration | Testadapter mit Aktion: aktivieren, Feld mappen, Submission | Callback erhält nur gemappte Werte + `form_id`, `entry_id`, `idempotency_key`; Ausführung im Worker, nicht im Request |
| AC-150 | integration | Adapter-Callback wirft Exception / liefert `retryable=false` | Entry unverändert; Job `failed` mit Backoff / `dead` |
| AC-151 | integration | Signierter Kontext: Tests TC-CTX-01…24 aus `suite-kontext.md` | alle bestanden |
| AC-152 | manuell | Developer-Dokumentation der Contract-API | jede öffentliche Funktion/Action/Filter mit Signatur, Payload, Fehlercodes, Beispiel |

## 17. Übersetzung, Barrierefreiheit, Kompatibilität

| ID | Typ | Vorgehen | Erwartetes Ergebnis |
|---|---|---|---|
| AC-153 | unit | POT erzeugen (`wp i18n make-pot`), auf Strings ohne Textdomain prüfen | alle Strings mit `form-captain`; keine Verkettung übersetzbarer Teilsätze |
| AC-154 | manuell | Admin und Frontend in de_DE und en_US durchklicken | keine unübersetzten Strings in de_DE; Fehlermeldungen übersetzt |
| AC-155 | e2e | axe-core auf Frontend-Formular (`all-fields`) und Admin-Seiten (Dashboard, Editor, Liste, Detail, Einstellungen) | 0 Verstöße der Stufen „serious“/„critical“ |
| AC-156 | manuell | Screenreader (NVDA + Firefox, VoiceOver + Safari): Formular ausfüllen, Fehler, Erfolg | Labels, Pflicht, Fehler und Erfolg werden angesagt; Fokus springt auf Fehlerzusammenfassung |
| AC-157 | e2e | Mobilansicht 360 px | kein horizontales Scrollen; Touch-Ziele ≥ 24 px |
| AC-158 | e2e | Yoast fehlt / aktiv (Free): Seite mit Formular | Title, Meta-Description, Canonical, Robots, OG-Tags, JSON-LD identisch zur gleichen Seite ohne Formular; keine Form-Captain-Sitemap/kein eigenes JSON-LD |
| AC-159 | integration | Yoast-Filter `yoast.analysis.shortcodes` verfügbar | nur Shortcode-Tag registriert; Analyse-Ausgabe enthält Labels/Hilfetexte, keine Entry-Werte |
| AC-160 | e2e | WooCommerce aktiv (HPOS an/aus): klassischer und Block-Checkout durchführen | Bestellung unverändert erfolgreich; keine Form-Captain-Felder/-Skripte im Checkout; keine Order-Meta von Form Captain |
| AC-161 | manuell | Classic Editor + Shortcode, ein gängiges Security- und SMTP-Plugin | keine Konflikte; Mail über SMTP-Plugin zugestellt |
| AC-162 | e2e | Hauptablauf: Formular erstellen → veröffentlichen → auf Seite einbetten → absenden → Entry verwalten → CSV exportieren (Chromium, Firefox, WebKit) | in allen drei Browsern grün (P6-13) |

## 18. Zuordnung Free-MVP-Funktion → Prüfpunkte

| Funktion (konzept 1.1/4) | Prüfpunkte |
|---|---|
| Formulareditor | AC-030…037 |
| 3 Vorlagen | AC-028, AC-029 |
| Kernfelder (12 Typen) | AC-038…051 |
| Einfache bedingte Feldanzeige | AC-052…056 |
| Formularstatus/Übersicht | AC-020…027 |
| Einträge (Status, Suche, Filter, Detail, Notiz, Papierkorb) | AC-083…091 |
| E-Mail (Admin, Receipt, Status) | AC-092…100 |
| Basisspamschutz | AC-069…075, AC-082 |
| Export (CSV) | AC-101…107 |
| Formular-JSON-Import/-Export | AC-108…111 |
| Block/Shortcode, Renderer, Bestätigung/Redirect | AC-057…068 |
| Datenschutzwerkzeuge (Retention, Consent, Exporter/Eraser, Uninstall) | AC-112…127 |
| Installationsassistent, Dashboard, Systemstatus | AC-011…019, AC-140 |
| Rechte und Sicherheit | AC-128…135 |
| Zuverlässigkeit (Outbox/Queue) | AC-080, AC-081, AC-136…141 |
| Öffentlicher Contract v1 | AC-142…152 |
| Übersetzung, Accessibility, Kompatibilität (Yoast, WooCommerce) | AC-153…162 |
| Installation/Plattform/Free ohne Lizenz | AC-001…010 |
