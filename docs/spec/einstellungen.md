# Einstellungsstruktur (P2-17, P2-19, P2-20)

Gespeichert als eine Option `form_captain_settings` (Array, autoload). Jede Option ist in `FormCaptain\Settings\SettingsSchema` mit Typ, Default, Sanitizer, Validator, Tab und Capability definiert; unbekannte Schlüssel werden verworfen. Migration ergänzt neue Defaults, ohne bestehende Werte zu überschreiben (P5-21).

**Speicherweg:** nicht die WordPress Settings API (`register_setting`/`options.php`), sondern ein eigener, gleichwertig abgesicherter Weg: `SettingsPage` sendet jeden Reiter an `admin-post.php` (Aktionen `form_captain_save_settings`, `form_captain_reset_settings`), prüft Recht `form_captain_manage_settings` und eine Nonce je Reiter (`form_captain_settings_<reiter>`), lässt die Werte durch `SettingsSchema::sanitize_group()` laufen und meldet Fehler je Feld. Grund: Fehler je Feld mit Sprungmarken, Bestätigungen (Aufbewahrung, Deinstallation) und Teilspeichern eines Reiters lassen sich so ohne Umwege abbilden.

| Tab | Schlüssel | Typ | Default | Hinweis |
|---|---|---|---|---|
| Allgemein | `general.setup_completed` | bool | false | Ersteinrichtung |
| Allgemein | `general.show_pro_hints` | bool | true | sparsame, wegklickbare Hinweise; blendet auch die Pro-Hinweiszeilen aus |
| Allgemein | `general.log_level` | enum `error`/`warning`/`info`/`debug` | `info` | SET-FC-09; Filter `form_captain_log_level_v1` gewinnt; mit `WP_DEBUG` immer `debug`; Hinweis im Systemstatus, wenn per Einstellung auf `debug` |
| Allgemein | `general.text_max` | int 100–5000 | 1000 | SET-FC-34; Obergrenze einzeiliger Felder |
| Allgemein | `general.textarea_max` | int 500–50 000 | 10 000 | SET-FC-34; Obergrenze mehrzeiliger Felder; Anfragegröße wächst mit |
| Allgemein | `general.country_profile` | intern | leer | zuletzt übernommenes Länderprofil (SET-FC-12) |
| Allgemein | `general.entry_number_format` | string ≤ 30 | leer (aus) | Vorgangsnummer, Platzhalter `{year}` und `{number}` (Pflicht, genau einmal); Filter `form_captain_entry_number_v1` (SET-FC-29) |
| Allgemein | `general.entry_number_digits` | int 1–10 | 4 | Stellen der laufenden Nummer (führende Nullen) |
| Allgemein | `general.entry_number_yearly_reset` | bool | true | Neustart am 1. Januar (Zeitzone der Website) |
| Darstellung | `appearance.mode` | enum `inherit`/`base` | `inherit` | `base` lädt Basis-CSS |
| Darstellung | `appearance.accent_color` | hex | `#2271b1` | nur bei `base` |
| Darstellung | `appearance.radius` | int 0–24 | 4 | Eckenradius in px, nur bei `base` (SET-FC-32) |
| Darstellung | `appearance.spacing` | int 0–48 | 16 | Abstand zwischen Feldern in px, nur bei `base` |
| Darstellung | `appearance.font_size` | enum `theme`/`14`/`16`/`18`/`20` | `theme` | nur bei `base` |
| Darstellung | `appearance.custom_css` | css ≤ 10 000 | leer | für beide Darstellungsarten; ändern nur mit `unfiltered_html`; `<` und `@import` werden abgelehnt |
| E-Mail | `email.from_name` | string ≤ 190 | Site-Name | Default für neue Formulare |
| E-Mail | `email.from_address` | email | `wordpress@{domain}` | Hinweis, wenn Domain ≠ Site-Domain |
| E-Mail | `email.format` | enum `html`/`text` | `html` | |
| E-Mail | `email.reply_to` | email, leer erlaubt | leer | feste Antwortadresse für Mails an Besucher (SET-FC-03) |
| E-Mail | `email.footer_text` | text ≤ 2000, Zeilen bleiben | leer | Fußzeile/Pflichtangaben in allen Mails; Filter `form_captain_mail_footer_v1` gewinnt; Rechtsprüfung |
| E-Mail | `email.logo_id` | attachment (Bild), 0 = keins | 0 | Logo oben in HTML-Mails |
| Datenschutz | `privacy.retention` | Zeitraum `<Zahl><d\|m\|y>` oder `manual` | `manual` (S9; bestehende Installationen behalten ihren Wert, ohne gespeicherten Wert `12m`) | SET-FC-01; Website-Zustand mahnt, solange keine Frist gesetzt ist (Test `form_captain_retention`); 1 Tag – 30 Jahre; Kalenderrechnung (12m = 1y); Änderung braucht Bestätigung; Filter `form_captain_retention_seconds_v1` gewinnt |
| Datenschutz | `privacy.trash_days` | int 1–90 | 30 | |
| Datenschutz | `privacy.delete_data_on_uninstall` | bool | false | erfordert Bestätigungstext |
| Datenschutz | `privacy.policy_page_hint` | bool | true | Link auf WP-Datenschutzseite in Consent |
| Datenschutz | `privacy.change_log_months` | int 1–120 | 12 | Aufbewahrung des Änderungsprotokolls; Filter `form_captain_change_log_months_v1` gewinnt |
| Datenschutz | `privacy.grace_days` | int 0–90 | 14 | SET-FC-08; Schonfrist nach Verkürzung; Filter `form_captain_retention_grace_days_v1` (0–365) gewinnt |
| Datenschutz | `privacy.expiry_notice_days` | int 0–90 | 14 | SET-FC-08; „läuft bald ab“; 0 = aus; Filter `form_captain_expiry_notice_days_v1` gewinnt |
| Datenschutz | `privacy.log_days` | int 1–365 | 30 | SET-FC-09; Protokoll-Aufbewahrung; Filter `form_captain_log_days_v1` (bis 3650) gewinnt |
| Spamschutz | `spam.honeypot` | bool | true | |
| Spamschutz | `spam.min_seconds` | int 0–60 | 3 | |
| Spamschutz | `spam.rate_limit` | int 1–100 | 5 | je Zeitfenster / Formular / IP-Hash |
| Spamschutz | `spam.blocklist` | text ≤ 10 000 | leer | ein Begriff/Domain je Zeile |
| Spamschutz | `spam.captcha_provider` | enum `none`/`pow`/Erweiterung | `none` | `pow` = eingebauter Proof of Work (docs/spam-proof-of-work.md); externe Anbieter via Erweiterung mit Bestätigung |
| Spamschutz | `spam.client_ip_source` | enum `remote_addr`/`cf_connecting_ip`/`x_forwarded_for`/`x_real_ip` | `remote_addr` | SET-FC-16; Kopfzeile nur hinter dem Dienst wählen (Warnhinweis); ungültige/fehlende Kopfzeile → `REMOTE_ADDR`; Filter `form_captain_client_ip` gewinnt |
| Spamschutz | `spam.global_per_ip` | int 1–1000 | 20 | SET-FC-34; Einsendungen je IP-Hash über alle Formulare im Zeitfenster |
| Spamschutz | `spam.token_minutes` | int 5–1440 | 120 | SET-FC-34; Gültigkeit des Formular-Tokens |
| Spamschutz | `spam.window_minutes` | int 1–1440 | 10 | Zeitfenster aller Sendegrenzen; Filter `form_captain_spam_limits_v1` gewinnt |
| Spamschutz | `spam.form_total` | int 10–100 000 | 300 | Einsendungen je Formular und Fenster (alle Besucher) |
| Spamschutz | `spam.receipts_per_hour` | int 1–50 | 3 | Mails an dieselbe Besucheradresse je Stunde (Bestätigung, Double-Opt-in) |
| Export | `export.delimiter` | enum `,`/`;` | `,` | SET-FC-31; Vorauswahl im CSV-Export, vom Länderprofil gesetzt |
| Export | `export.bom` | bool | true | SET-FC-31; Vorauswahl UTF-8-BOM |
| Zugriff & Rollen | (Rollen-Capabilities, nicht in Option, sondern `WP_Roles`) | | nur Admin | |

Alle Tabs erfordern `form_captain_manage_settings`. Systemstatus ist schreibgeschützt.

**Formularbezogen und nie global:** Empfänger, Reply-To, Betreff, Feldauswahl, Nutzerbestätigung, Erfolgsmeldung, Redirect, Aktionen. Neue Formulare übernehmen `email.from_*` und `appearance.mode` als **Kopie** in ihr `settings_json`; spätere Änderung der globalen Werte ändert bestehende Formulare nicht (Hinweis in der UI zeigt Anzahl der Formulare mit eigenen Werten).

## Formular-Einstellungen (SET-FC-28)

Die Werte im `settings_json` eines Formulars sind in `FormCaptain\Settings\FormSettingsSchema` beschrieben: je Pfad (`notification.to`, `confirmation.message`, `paused_message` …) Typ, Vorgabe, Beschriftung, Hilfetext und Prüfung. `FormService::default_settings()` und `FormService::sanitize_settings()` lesen nur diese Liste; Regeln über mehrere Werte (Empfänger nötig, Bestätigungsseite gültig, Betreff der Bestätigungsmail) stehen in `FormSettingsSchema::checks()`. Ungültige Werte fallen auf die sichere Vorgabe zurück und werden als Fehlercode (`pfad => code`) gemeldet. Nicht in der Liste: `template`/`template_version` (Herkunft) und `x` (Werte von Erweiterungen, Vertrag 1.1.0).

Neue Formularwerte: einen Eintrag in `definitions()` im passenden Abschnitt ergänzen (Reihenfolge = gespeicherte Reihenfolge), Hilfetext Pflicht (Test `FormSettingsSchemaTest`).

## Pro-Hinweiszeilen und Felder von Erweiterungen (S1, SET-FC-06)

- `SettingsSchema::pro_hints()`: Zeilen nur für Pro-Funktionen, die heute verkauft werden (S5: Uploads, mehrstufige Formulare/Entwürfe, erweiterte Logik/Routing, PDF, Webhooks, WooCommerce-Fragebögen); noch nicht verkaufte Funktionen (Formularinhalte je Sprache, E-Mail-Gestalter, eine Einsendung je Person/Warteliste) bekommen keine Zeile. Feste Liste `{id, tab, title, text, link}`, nur Text. Ausgabe am Ende des Reiters (nach Formular und „Zurücksetzen“) als graue Zeile mit „Pro“-Kennzeichen, solange `general.show_pro_hints` an ist und keine Erweiterung ein Feld mit derselben `id` angemeldet hat. Kein Eingabeelement, kein Standardwert, keine Prüfung, keine Rechteschlüssel-Namen. `link` ist leer, solange es keine Produktseite gibt (`SettingsSchema::PRO_INFO_URL`, offene Entscheidung E-6 in `WORDPRESS-ORG.md`).
- Filter `form_captain_settings_fields_v1`: Liste von Feldern **oder** Zuordnung Reiter → Liste von Feldern (der Schlüssel ergänzt ein fehlendes `tab`). `id`: `a-z0-9_.-`. `tab`: ein Kernreiter (`general`, `appearance`, `email`, `privacy`, `spam`, `export`) oder ein eigener Reiter (`[a-z][a-z0-9_-]`, nicht `access`/`status`) – solche Reiter erscheinen vor „Zugriff & Rollen“, aber nur, solange dort Felder angemeldet sind; Bezeichnung aus `tab_label` des ersten Felds, sonst „Uploads“ bzw. „Integrations“ oder der Schlüssel mit großem Anfangsbuchstaben (für `woocommerce` muss das Add-on `tab_label` „WooCommerce“ setzen – der Kern nennt keine Shop-Software, Wächtertest `WooCommerceIsolationTest`). Zwei Feldformen:
  1. **Rückrufe** (Form der Gruppe A): `type` (`bool|int|enum|string|text|email|hex`), `label`, `help`, je nach Typ `options`/`min`/`max`/`max_length`, `get()` und `set( $wert )`, optional `validate( $wert ): ?string` (Fehlermeldung) und `default` (für „Bereich zurücksetzen“). Der Kern prüft den Typ wie bei eigenen Werten, ruft `validate` und speichert über `set`.
  2. **Speicherort** (Form von Form Captain Pro): `owner`, `label`, `help`, `type` (`checkbox|number|text|textarea|select|multiselect|media|confirm` oder ein Kerntyp; `text` = einzeilig), `default`, `value` (aktueller Wert; fehlt er, liest der Kern den Speicherort), optional `sanitize( $roh, $gesendet ): mixed|WP_Error` und `storage` = {`option`, `key`}: Der Kern schreibt den sauberen Wert in diese Option unter diesem Schlüssel (nie in `form_captain_settings`; ein solches Feld wird verworfen). Ohne `sanitize` prüft der Kern selbst (`min`/`max`, `max_length`, `options`). `confirm` = Schalter mit Bestätigungswort (`DELETE`/`LÖSCHEN`): das Wort als Wert oder Haken plus Wort unter `<id>_confirm`; `multiselect` = Liste erlaubter Optionen.

  Die Felder erscheinen nach den Kernfeldern des Reiters als `settings[ext][<id>]`; Fehler unter `ext.<id>`. Nicht angehakte Kästchen und leere Mehrfachauswahl werden als „aus“ bzw. leere Liste gespeichert. Speicherort und Werte gehören dem Add-on; Kern-Export, -Import, -CLI und Änderungsprotokoll enthalten sie nicht.
- Eine Hinweiszeile verschwindet, sobald ein Feld mit derselben `id` angemeldet ist (gleich in welchem Reiter). IDs sind an Form Captain Pro angeglichen:

| id | Hinweis | Reiter der Hinweiszeile |
|---|---|---|
| `uploads.types` | Dateitypen und Größe | Allgemein |
| `uploads.max_files` | mehrere Dateien | Allgemein |
| `multistep.draft_days` | gespeicherte Entwürfe | Allgemein |
| `webhooks` | Webhooks allgemein | Allgemein |
| `webhooks.timeout` | Webhook-Zustellung/Wiederholung | Allgemein |
| `woo.reminder_days` | Fragebogen-Erinnerung | Allgemein |
| `woo.link_days` | Gültigkeit des Fragebogen-Links | Allgemein |
| `woo.reminder_subject` | Fragebogen-Mails | Allgemein |
| `multistep.progress` | Fortschrittsanzeige | Darstellung |
| `pdf.logo_id` | PDF-Gestaltung | Darstellung |
| `recipient_rules` | Empfänger nach Regel | E-Mail |
| `pdf.attach_notification` | PDF-Anhang | E-Mail |
| `uploads.file_retention_days` | Dateien früher löschen | Datenschutz |
| `upload_virus_scan` | Virenprüfung | Spamschutz |

## Änderungsprotokoll (SET-FC-15)

`FormCaptain\Settings\ChangeLog` hängt an `update_option_form_captain_settings` und schreibt jede Änderung, egal über welchen Weg (Oberfläche, WP-CLI, Import, Code): Zeitpunkt (UTC), Benutzer-ID, Quelle (`ui`, `cli`, `import`, `system`), Schlüssel, alter und neuer Wert. Rollenänderungen erscheinen als `access.<rolle>.<recht>` (alt/neu `true`/`false`). Geheime Werte (Kernwerte: `secret => true` in der Definition; Add-on-Felder und andere Schlüssel: Name mit `secret`, `token`, `password`, `api_key`, `private_key`, `credential` – `spam.token_minutes` ist eine Zeitgrenze und kein Geheimnis) werden als `••••••` gespeichert, lange Werte auf 200 Zeichen gekürzt. Ablage in der Option `form_captain_settings_changelog` (nicht autoload, höchstens 1000 Einträge), Löschung nach `privacy.change_log_months` Monaten durch die tägliche Bereinigung – nicht nach 30 Tagen wie das technische Protokoll. Ansicht: Reiter *Systemstatus → Änderungsprotokoll* (letzte 50 Einträge).

## Export und Import der Einstellungen (SET-FC-13)

`FormCaptain\Settings\SettingsTransfer`, Oberfläche im Reiter *Systemstatus*. Datei (JSON):

```json
{ "format": "form-captain/settings", "format_version": 1, "plugin_version": "1.0.0", "exported_gmt": "2026-11-23 12:00:00",
  "keys": [ "general.show_pro_hints", "…" ], "settings": { "general": { "show_pro_hints": true }, "…": {} } }
```

- Exportiert werden alle bedienbaren Werte aus `SettingsSchema` außer Geheimnissen (Kernwerte mit `secret => true`), internen Werten (`privacy.delete_data_confirmed_gmt`) und Werten mit `exportable => false` (`privacy.delete_data_on_uninstall` – Datenlöschung muss am Ziel immer selbst bestätigt werden). Nicht enthalten: Rollenrechte, Formulare, Einsendungen, Werte von Add-ons.
- Import in zwei Schritten: Datei hochladen (höchstens 64 KB) → Vorschau alt/neu, gemeldete Fehler und unbekannte Schlüssel (werden nicht übernommen) → „Importieren“. Jede Gruppe läuft durch `SettingsSchema::sanitize_group()`; eine geänderte Aufbewahrung und ein externer Captcha brauchen weiter ihre Bestätigung. Fehlende Schlüssel bleiben unverändert. Eine Datei mit höherer `format_version` wird abgelehnt.
- Jede Übernahme landet im Änderungsprotokoll mit Quelle `import`.

## Aufbewahrung je Formular (SET-FC-01)

`retention.mode` im `settings_json` des Formulars: `global` oder ein Zeitraum wie oben (`manual`, `30d`, `6m`, `10y`). Ungültige Werte werden als `global` gespeichert und mit Fehlercode `invalid_retention` gemeldet (Eintrag in `FormSettingsSchema`, SET-FC-28).

**Migration** (`Settings\SettingsMigrations`, Schritt `retention_periods`, läuft einmal, Merker in Option `form_captain_settings_migrations`): `90d`, `12m`, `manual` bleiben unverändert gültig und bedeuten dasselbe wie vorher. Die Spec-Aliasse `days_90`/`months_12` werden zu `90d`/`12m`; unbekannte Werte werden zu dem, was sie vorher bewirkten (global `12m`, je Formular `global`). Ablaufdaten bestehender Einsendungen ändern sich dadurch nicht.

**Vorgabe (S9, 23.11.2026):** Neue Installationen starten mit `manual` – kein überraschendes Löschen. Bestehende Installationen bleiben unverändert: ein gespeicherter Wert (z. B. `12m`) bleibt; fehlt er, setzt `Settings::ensure_defaults()` die frühere Vorgabe `Settings::LEGACY_RETENTION` (`12m`). Das Länderprofil schlägt 12 Monate für Kontaktanfragen vor (E6); jede Frist trägt den Hinweis „Rechtsprüfung“.

## Länderprofile (SET-FC-12)

`Settings\CountryPresets`, Schlüssel `DE`, `AT`, `CH`, `EU`, `OTHER` (suiteweit gleich). Reiter Allgemein → „Länderprofil“: Profil wählen → Vorher/Nachher-Tabelle (nur lesend, GET) → Zeilen auswählen, Prüfung bestätigen → übernehmen (`admin-post.php?action=form_captain_apply_country_profile`, Nonce gleichen Namens). Nichts wird still überschrieben; jeder Wert bleibt danach änderbar. Werte mit rechtlichem Gewicht (`privacy.retention`, `privacy.trash_days`, `privacy.grace_days`, `privacy.log_days`) zeigen „Rechtlich geprüft am …“ oder „Ungeprüft“; alle mitgelieferten Werte sind ungeprüft. Prüfstand je Profil (S8, Feld `review`): DE, AT, CH `pending` (Rechtsprüfung vor Verkaufsstart, bis dahin „noch nicht geprüft“), EU und OTHER `unreviewed` (neutrale Werte, deutlich „nicht geprüft“ in Auswahl und Vorschau). Nach der Prüfung trägt der Inhaber `review = reviewed` und die Daten unter `reviewed` ein.

| Profil | Aufbewahrung | Papierkorb | CSV-Trennzeichen | BOM | Hinweis |
|---|---|---|---|---|---|
| DE, AT | 12 Monate | 30 Tage | `;` | ja | Anrede: Sprache „Deutsch (Sie)“ vs. „Deutsch“ |
| CH | 12 Monate | 30 Tage | `;` | ja | DSG neben/statt DSGVO, „ss“ statt „ß“ |
| EU | 12 Monate | 30 Tage | `,` | ja | Semikolon in Ländern mit Dezimalkomma |
| OTHER | 12 Monate | 30 Tage | `,` | ja | neutrale Vorgaben |

Filter `form_captain_country_presets_v1` (Array Schlüssel → `label`, `values` [Pfad → Wert], `reviewed` [Pfad → `Y-m-d`], `note`, `fields`); ungültige Werte werden angezeigt, aber nie übernommen.

**Muster für neue Felder (SET-FC-12 mit SET-FC-04):** Jedes Profil trägt `fields` = `zip_pattern` (Schlüssel aus `Domain\ValidationPatterns`) und `tel_format` (`''` = beliebig, `international`): DE `zip_de`, AT `zip_at`, CH `zip_ch`, EU und OTHER `zip_generic` + `international`. `CountryPresets::field_suggestions()` liefert die Werte des zuletzt übernommenen Profils (`general.country_profile`) an den Editor (`fieldSuggestions`): neue Telefonfelder starten mit dem vorgeschlagenen Format, einzeilige Textfelder ohne Muster zeigen einen Knopf „Vorschlag des Länderprofils übernehmen“. Bestehende Felder ändern sich nie; unbekannte Muster aus dem Filter (auch `custom`) werden verworfen. Die Vorschau des Profils nennt die Vorschläge.

## Formularwerte aus SET-FC-10/17/19 (Prüfung in `Settings\FormMessagingSettings`, Einträge in `FormSettingsSchema`)

| Schlüssel | Typ | Default | Hinweis |
|---|---|---|---|
| `email.format` | `global`/`html`/`text` | `global` | Format aller Mails des Formulars |
| `spam.mode` | `global`/`strict`/`off` | `global` | `strict`: Captcha immer (sonst `pow`), auch ohne JS; `off`: kein Captcha |
| `confirmation_mail.body` | Text ≤ 5000 | leer | Klartext oder einfaches HTML der Positivliste (`HtmlSanitizer`) |
| `double_opt_in.enabled` | bool | false | Einsendung gilt erst nach Bestätigung |
| `double_opt_in.email_field` | Feld-ID (Pflicht-E-Mail-Feld) | leer | |
| `double_opt_in.subject` / `.body` / `.pending_message` | Text ≤ 190 / 5000 / 1000 | leer = Standardtext | Platzhalter `{confirm_link}`, `{link_hours}` |
| `double_opt_in.link_hours` | int 1–720 | 48 | |
| `double_opt_in.delete_after_days` | int 1–90, ≥ Link-Gültigkeit | 7 | unbestätigte Einsendungen werden gelöscht |

Filter `form_captain_double_opt_in_v1` gewinnt über die Formularwerte.

