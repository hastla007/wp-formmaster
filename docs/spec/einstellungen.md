# Einstellungsstruktur (P2-17, P2-19, P2-20)

Gespeichert als eine Option `form_captain_settings` (Array, autoload). Jede Option ist in `FormCaptain\Settings\SettingsSchema` mit Typ, Default, Sanitizer, Validator, Tab und Capability definiert; unbekannte Schlüssel werden verworfen. Migration ergänzt neue Defaults, ohne bestehende Werte zu überschreiben (P5-21).

| Tab | Schlüssel | Typ | Default | Hinweis |
|---|---|---|---|---|
| Allgemein | `general.setup_completed` | bool | false | Ersteinrichtung |
| Allgemein | `general.show_pro_hints` | bool | true | sparsame, wegklickbare Hinweise |
| Allgemein | `general.log_level` | enum `error`/`warning`/`info`/`debug` | `info` | SET-FC-09; Filter `form_captain_log_level_v1` gewinnt; mit `WP_DEBUG` immer `debug`; Hinweis im Systemstatus, wenn per Einstellung auf `debug` |
| Allgemein | `general.text_max` | int 100–5000 | 1000 | SET-FC-34; Obergrenze einzeiliger Felder |
| Allgemein | `general.textarea_max` | int 500–50 000 | 10 000 | SET-FC-34; Obergrenze mehrzeiliger Felder; Anfragegröße wächst mit |
| Allgemein | `general.country_profile` | intern | leer | zuletzt übernommenes Länderprofil (SET-FC-12) |
| Darstellung | `appearance.mode` | enum `inherit`/`base` | `inherit` | `base` lädt Basis-CSS |
| Darstellung | `appearance.accent_color` | hex | `#2271b1` | nur bei `base` |
| E-Mail | `email.from_name` | string ≤ 190 | Site-Name | Default für neue Formulare |
| E-Mail | `email.from_address` | email | `wordpress@{domain}` | Hinweis, wenn Domain ≠ Site-Domain |
| E-Mail | `email.format` | enum `html`/`text` | `html` | |
| Datenschutz | `privacy.retention` | Zeitraum `<Zahl><d\|m\|y>` oder `manual` | `12m` | SET-FC-01; 1 Tag – 30 Jahre; Kalenderrechnung (12m = 1y); Änderung braucht Bestätigung; Filter `form_captain_retention_seconds_v1` gewinnt |
| Datenschutz | `privacy.trash_days` | int 1–90 | 30 | |
| Datenschutz | `privacy.delete_data_on_uninstall` | bool | false | erfordert Bestätigungstext |
| Datenschutz | `privacy.policy_page_hint` | bool | true | Link auf WP-Datenschutzseite in Consent |
| Datenschutz | `privacy.grace_days` | int 0–90 | 14 | SET-FC-08; Schonfrist nach Verkürzung; Filter `form_captain_retention_grace_days_v1` (0–365) gewinnt |
| Datenschutz | `privacy.expiry_notice_days` | int 0–90 | 14 | SET-FC-08; „läuft bald ab“; 0 = aus; Filter `form_captain_expiry_notice_days_v1` gewinnt |
| Datenschutz | `privacy.log_days` | int 1–365 | 30 | SET-FC-09; Protokoll-Aufbewahrung; Filter `form_captain_log_days_v1` (bis 3650) gewinnt |
| Spamschutz | `spam.honeypot` | bool | true | |
| Spamschutz | `spam.min_seconds` | int 0–60 | 3 | |
| Spamschutz | `spam.rate_limit` | int 1–100 | 5 | je 10 min / Formular / IP-Hash |
| Spamschutz | `spam.blocklist` | text ≤ 10 000 | leer | ein Begriff/Domain je Zeile |
| Spamschutz | `spam.captcha_provider` | enum `none` | `none` | Provider nur via Erweiterung, opt-in |
| Spamschutz | `spam.client_ip_source` | enum `remote_addr`/`cf_connecting_ip`/`x_forwarded_for`/`x_real_ip` | `remote_addr` | SET-FC-16; Kopfzeile nur hinter dem Dienst wählen (Warnhinweis); ungültige/fehlende Kopfzeile → `REMOTE_ADDR`; Filter `form_captain_client_ip` gewinnt |
| Spamschutz | `spam.global_per_ip` | int 1–1000 | 20 | SET-FC-34; Einsendungen je IP-Hash über alle Formulare im Zeitfenster |
| Spamschutz | `spam.token_minutes` | int 5–1440 | 120 | SET-FC-34; Gültigkeit des Formular-Tokens |
| Export | `export.delimiter` | enum `,`/`;` | `,` | SET-FC-31; Vorauswahl im CSV-Export, vom Länderprofil gesetzt |
| Export | `export.bom` | bool | true | SET-FC-31; Vorauswahl UTF-8-BOM |
| Zugriff & Rollen | (Rollen-Capabilities, nicht in Option, sondern `WP_Roles`) | | nur Admin | |

Alle Tabs erfordern `form_captain_manage_settings`. Systemstatus ist schreibgeschützt.

**Formularbezogen und nie global:** Empfänger, Reply-To, Betreff, Feldauswahl, Nutzerbestätigung, Erfolgsmeldung, Redirect, Aktionen. Neue Formulare übernehmen `email.from_*` und `appearance.mode` als **Kopie** in ihr `settings_json`; spätere Änderung der globalen Werte ändert bestehende Formulare nicht (Hinweis in der UI zeigt Anzahl der Formulare mit eigenen Werten).

## Aufbewahrung je Formular (SET-FC-01)

`retention.mode` im `settings_json` des Formulars: `global` oder ein Zeitraum wie oben (`manual`, `30d`, `6m`, `10y`). Ungültige Werte werden als `global` gespeichert und mit Fehlercode `invalid_retention` gemeldet (Prüfung vorerst in `FormService::sanitize_settings()`, gehört in `FormSettingsSchema`, SET-FC-28).

**Migration** (`Settings\SettingsMigrations`, Schritt `retention_periods`, läuft einmal, Merker in Option `form_captain_settings_migrations`): `90d`, `12m`, `manual` bleiben unverändert gültig und bedeuten dasselbe wie vorher. Die Spec-Aliasse `days_90`/`months_12` werden zu `90d`/`12m`; unbekannte Werte werden zu dem, was sie vorher bewirkten (global `12m`, je Formular `global`). Ablaufdaten bestehender Einsendungen ändern sich dadurch nicht.

## Länderprofile (SET-FC-12)

`Settings\CountryPresets`, Schlüssel `DE`, `AT`, `CH`, `EU`, `OTHER` (suiteweit gleich). Reiter Allgemein → „Länderprofil“: Profil wählen → Vorher/Nachher-Tabelle (nur lesend, GET) → Zeilen auswählen, Prüfung bestätigen → übernehmen (`admin-post.php?action=form_captain_apply_country_profile`, Nonce gleichen Namens). Nichts wird still überschrieben; jeder Wert bleibt danach änderbar. Werte mit rechtlichem Gewicht (`privacy.retention`, `privacy.trash_days`, `privacy.grace_days`, `privacy.log_days`) zeigen „Rechtlich geprüft am …“ oder „Ungeprüft“; alle mitgelieferten Werte sind ungeprüft.

| Profil | Aufbewahrung | Papierkorb | CSV-Trennzeichen | BOM | Hinweis |
|---|---|---|---|---|---|
| DE, AT | 12 Monate | 30 Tage | `;` | ja | Anrede: Sprache „Deutsch (Sie)“ vs. „Deutsch“ |
| CH | 12 Monate | 30 Tage | `;` | ja | DSG neben/statt DSGVO, „ss“ statt „ß“ |
| EU | 12 Monate | 30 Tage | `,` | ja | Semikolon in Ländern mit Dezimalkomma |
| OTHER | 12 Monate | 30 Tage | `,` | ja | neutrale Vorgaben |

Filter `form_captain_country_presets_v1` (Array Schlüssel → `label`, `values` [Pfad → Wert], `reviewed` [Pfad → `Y-m-d`], `note`); ungültige Werte werden angezeigt, aber nie übernommen. Postleitzahl-/Telefon-Muster für neue Felder folgen, sobald SET-FC-04 die Länder-Muster liefert.
