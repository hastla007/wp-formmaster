# Einstellungsstruktur (P2-17, P2-19, P2-20)

Gespeichert als eine Option `form_captain_settings` (Array, autoload). Jede Option ist in `FormCaptain\Settings\SettingsSchema` mit Typ, Default, Sanitizer, Validator, Tab und Capability definiert; unbekannte Schlüssel werden verworfen. Migration ergänzt neue Defaults, ohne bestehende Werte zu überschreiben (P5-21).

| Tab | Schlüssel | Typ | Default | Hinweis |
|---|---|---|---|---|
| Allgemein | `general.setup_completed` | bool | false | Ersteinrichtung |
| Allgemein | `general.show_pro_hints` | bool | true | sparsame, wegklickbare Hinweise |
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
| Datenschutz | `privacy.retention` | enum `90d`/`12m`/`manual` | `12m` | |
| Datenschutz | `privacy.trash_days` | int 1–90 | 30 | |
| Datenschutz | `privacy.delete_data_on_uninstall` | bool | false | erfordert Bestätigungstext |
| Datenschutz | `privacy.policy_page_hint` | bool | true | Link auf WP-Datenschutzseite in Consent |
| Spamschutz | `spam.honeypot` | bool | true | |
| Spamschutz | `spam.min_seconds` | int 0–60 | 3 | |
| Spamschutz | `spam.rate_limit` | int 1–100 | 5 | je 10 min / Formular / IP-Hash |
| Spamschutz | `spam.blocklist` | text ≤ 10 000 | leer | ein Begriff/Domain je Zeile |
| Spamschutz | `spam.captcha_provider` | enum `none` | `none` | Provider nur via Erweiterung, opt-in |
| Zugriff & Rollen | (Rollen-Capabilities, nicht in Option, sondern `WP_Roles`) | | nur Admin | |

Alle Tabs erfordern `form_captain_manage_settings`. Systemstatus ist schreibgeschützt.

**Formularbezogen und nie global:** Empfänger, Reply-To, Betreff, Feldauswahl, Nutzerbestätigung, Erfolgsmeldung, Redirect, Aktionen. Neue Formulare übernehmen `email.from_*` und `appearance.mode` als **Kopie** in ihr `settings_json`; spätere Änderung der globalen Werte ändert bestehende Formulare nicht (Hinweis in der UI zeigt Anzahl der Formulare mit eigenen Werten).
