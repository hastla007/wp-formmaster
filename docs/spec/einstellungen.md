# Einstellungsstruktur (P2-17, P2-19, P2-20)

Gespeichert als eine Option `form_captain_settings` (Array, autoload). Jede Option ist in `FormCaptain\Settings\SettingsSchema` mit Typ, Default, Sanitizer, Validator, Tab und Capability definiert; unbekannte Schlüssel werden verworfen. Migration ergänzt neue Defaults, ohne bestehende Werte zu überschreiben (P5-21).

**Speicherweg:** nicht die WordPress Settings API (`register_setting`/`options.php`), sondern ein eigener, gleichwertig abgesicherter Weg: `SettingsPage` sendet jeden Reiter an `admin-post.php` (Aktionen `form_captain_save_settings`, `form_captain_reset_settings`), prüft Recht `form_captain_manage_settings` und eine Nonce je Reiter (`form_captain_settings_<reiter>`), lässt die Werte durch `SettingsSchema::sanitize_group()` laufen und meldet Fehler je Feld. Grund: Fehler je Feld mit Sprungmarken, Bestätigungen (Aufbewahrung, Deinstallation) und Teilspeichern eines Reiters lassen sich so ohne Umwege abbilden.

| Tab | Schlüssel | Typ | Default | Hinweis |
|---|---|---|---|---|
| Allgemein | `general.setup_completed` | bool | false | Ersteinrichtung |
| Allgemein | `general.show_pro_hints` | bool | true | sparsame, wegklickbare Hinweise; blendet auch die Pro-Hinweiszeilen aus |
| Darstellung | `appearance.mode` | enum `inherit`/`base` | `inherit` | `base` lädt Basis-CSS |
| Darstellung | `appearance.accent_color` | hex | `#2271b1` | nur bei `base` |
| E-Mail | `email.from_name` | string ≤ 190 | Site-Name | Default für neue Formulare |
| E-Mail | `email.from_address` | email | `wordpress@{domain}` | Hinweis, wenn Domain ≠ Site-Domain |
| E-Mail | `email.format` | enum `html`/`text` | `html` | |
| Datenschutz | `privacy.retention` | enum `90d`/`12m`/`manual` | `12m` | |
| Datenschutz | `privacy.trash_days` | int 1–90 | 30 | |
| Datenschutz | `privacy.delete_data_on_uninstall` | bool | false | erfordert Bestätigungstext |
| Datenschutz | `privacy.policy_page_hint` | bool | true | Link auf WP-Datenschutzseite in Consent |
| Datenschutz | `privacy.change_log_months` | int 1–120 | 12 | Aufbewahrung des Änderungsprotokolls; Filter `form_captain_change_log_months_v1` gewinnt |
| Spamschutz | `spam.honeypot` | bool | true | |
| Spamschutz | `spam.min_seconds` | int 0–60 | 3 | |
| Spamschutz | `spam.rate_limit` | int 1–100 | 5 | je 10 min / Formular / IP-Hash |
| Spamschutz | `spam.blocklist` | text ≤ 10 000 | leer | ein Begriff/Domain je Zeile |
| Spamschutz | `spam.captcha_provider` | enum `none` | `none` | Provider nur via Erweiterung, opt-in |
| Zugriff & Rollen | (Rollen-Capabilities, nicht in Option, sondern `WP_Roles`) | | nur Admin | |

Alle Tabs erfordern `form_captain_manage_settings`. Systemstatus ist schreibgeschützt.

**Formularbezogen und nie global:** Empfänger, Reply-To, Betreff, Feldauswahl, Nutzerbestätigung, Erfolgsmeldung, Redirect, Aktionen. Neue Formulare übernehmen `email.from_*` und `appearance.mode` als **Kopie** in ihr `settings_json`; spätere Änderung der globalen Werte ändert bestehende Formulare nicht (Hinweis in der UI zeigt Anzahl der Formulare mit eigenen Werten).

## Formular-Einstellungen (SET-FC-28)

Die Werte im `settings_json` eines Formulars sind in `FormCaptain\Settings\FormSettingsSchema` beschrieben: je Pfad (`notification.to`, `confirmation.message`, `paused_message` …) Typ, Vorgabe, Beschriftung, Hilfetext und Prüfung. `FormService::default_settings()` und `FormService::sanitize_settings()` lesen nur diese Liste; Regeln über mehrere Werte (Empfänger nötig, Bestätigungsseite gültig, Betreff der Bestätigungsmail) stehen in `FormSettingsSchema::checks()`. Ungültige Werte fallen auf die sichere Vorgabe zurück und werden als Fehlercode (`pfad => code`) gemeldet. Nicht in der Liste: `template`/`template_version` (Herkunft) und `x` (Werte von Erweiterungen, Vertrag 1.1.0).

Neue Formularwerte: einen Eintrag in `definitions()` im passenden Abschnitt ergänzen (Reihenfolge = gespeicherte Reihenfolge), Hilfetext Pflicht (Test `FormSettingsSchemaTest`).

## Pro-Hinweiszeilen und Felder von Erweiterungen (S1, SET-FC-06)

- `SettingsSchema::pro_hints()`: Zeilen nur für Pro-Funktionen, die heute verkauft werden (S5: Uploads, mehrstufige Formulare/Entwürfe, erweiterte Logik/Routing, PDF, Webhooks, WooCommerce-Fragebögen); noch nicht verkaufte Funktionen (Formularinhalte je Sprache, E-Mail-Gestalter, eine Einsendung je Person/Warteliste) bekommen keine Zeile. Feste Liste `{id, tab, title, text, link}`, nur Text. Ausgabe am Ende des Reiters (nach Formular und „Zurücksetzen“) als graue Zeile mit „Pro“-Kennzeichen, solange `general.show_pro_hints` an ist und keine Erweiterung ein Feld mit derselben `id` angemeldet hat. Kein Eingabeelement, kein Standardwert, keine Prüfung, keine Rechteschlüssel-Namen. `link` ist leer, solange es keine Produktseite gibt (`SettingsSchema::PRO_INFO_URL`, offene Entscheidung E-6 in `WORDPRESS-ORG.md`).
- Filter `form_captain_settings_fields_v1` (Liste von Feldern): `id` (`a-z0-9_-`), `tab` (einer der gespeicherten Reiter `general`, `appearance`, `email`, `privacy`, `spam`), `type` (`bool|int|enum|string|text|email|hex`), `label`, `help`, je nach Typ `options`/`min`/`max`/`max_length`, Rückrufe `get()` und `set( $wert )`, optional `validate( $wert ): ?string` (Fehlermeldung) und `default` (für „Bereich zurücksetzen“). Das Feld erscheint nach den Kernfeldern des Reiters als `settings[ext][<id>]`; der Kern prüft den Typ wie bei eigenen Werten, ruft `validate` und speichert über `set`. Speicherort und Werte gehören dem Add-on; Kern-Export, -Import und -CLI enthalten sie nicht. Fehler erscheinen unter `ext.<id>`.

| id | Reiter |
|---|---|
| `upload_defaults`, `upload_multiple`, `draft_lifetime`, `webhooks`, `webhook_delivery`, `questionnaire_reminder`, `questionnaire_link`, `questionnaire_mails` | Allgemein |
| `step_progress`, `pdf_layout` | Darstellung |
| `recipient_rules`, `pdf_attachment` | E-Mail |
| `upload_retention` | Datenschutz |
| `upload_virus_scan` | Spamschutz |

## Änderungsprotokoll (SET-FC-15)

`FormCaptain\Settings\ChangeLog` hängt an `update_option_form_captain_settings` und schreibt jede Änderung, egal über welchen Weg (Oberfläche, WP-CLI, Import, Code): Zeitpunkt (UTC), Benutzer-ID, Quelle (`ui`, `cli`, `import`, `system`), Schlüssel, alter und neuer Wert. Rollenänderungen erscheinen als `access.<rolle>.<recht>` (alt/neu `true`/`false`). Geheime Werte (`secret => true` in der Definition oder Name mit `secret`, `token`, `password`, `api_key`, `private_key`, `credential`) werden als `••••••` gespeichert, lange Werte auf 200 Zeichen gekürzt. Ablage in der Option `form_captain_settings_changelog` (nicht autoload, höchstens 1000 Einträge), Löschung nach `privacy.change_log_months` Monaten durch die tägliche Bereinigung – nicht nach 30 Tagen wie das technische Protokoll. Ansicht: Reiter *Systemstatus → Änderungsprotokoll* (letzte 50 Einträge).

## Export und Import der Einstellungen (SET-FC-13)

`FormCaptain\Settings\SettingsTransfer`, Oberfläche im Reiter *Systemstatus*. Datei (JSON):

```json
{ "format": "form-captain/settings", "format_version": 1, "plugin_version": "1.0.0", "exported_gmt": "2026-11-23 12:00:00",
  "keys": [ "general.show_pro_hints", "…" ], "settings": { "general": { "show_pro_hints": true }, "…": {} } }
```

- Exportiert werden alle bedienbaren Werte aus `SettingsSchema` außer Geheimnissen (`secret` bzw. Name wie `secret`/`token`/`password`/`api_key`), internen Werten (`privacy.delete_data_confirmed_gmt`) und Werten mit `exportable => false` (`privacy.delete_data_on_uninstall` – Datenlöschung muss am Ziel immer selbst bestätigt werden). Nicht enthalten: Rollenrechte, Formulare, Einsendungen, Werte von Add-ons.
- Import in zwei Schritten: Datei hochladen (höchstens 64 KB) → Vorschau alt/neu, gemeldete Fehler und unbekannte Schlüssel (werden nicht übernommen) → „Importieren“. Jede Gruppe läuft durch `SettingsSchema::sanitize_group()`; eine geänderte Aufbewahrung und ein externer Captcha brauchen weiter ihre Bestätigung. Fehlende Schlüssel bleiben unverändert. Eine Datei mit höherer `format_version` wird abgelehnt.
- Jede Übernahme landet im Änderungsprotokoll mit Quelle `import`.
