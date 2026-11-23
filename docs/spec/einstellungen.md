# Einstellungsstruktur (P2-17, P2-19, P2-20)

Gespeichert als eine Option `form_captain_settings` (Array, autoload). Jede Option ist in `FormCaptain\Settings\SettingsSchema` mit Typ, Default, Sanitizer, Validator, Tab und Capability definiert; unbekannte Schlüssel werden verworfen. Migration ergänzt neue Defaults, ohne bestehende Werte zu überschreiben (P5-21).

| Tab | Schlüssel | Typ | Default | Hinweis |
|---|---|---|---|---|
| Allgemein | `general.setup_completed` | bool | false | Ersteinrichtung |
| Allgemein | `general.show_pro_hints` | bool | true | sparsame, wegklickbare Hinweise |
| Darstellung | `appearance.mode` | enum `inherit`/`base` | `inherit` | `base` lädt Basis-CSS |
| Darstellung | `appearance.accent_color` | hex | `#2271b1` | nur bei `base` |
| E-Mail | `email.from_name` | string ≤ 190 | Site-Name | Default für neue Formulare |
| E-Mail | `email.from_address` | email | `wordpress@{domain}` | Hinweis, wenn Domain ≠ Site-Domain |
| E-Mail | `email.format` | enum `html`/`text` | `html` | |
| E-Mail | `email.reply_to` | email, leer erlaubt | leer | feste Antwortadresse für Mails an Besucher (SET-FC-03) |
| E-Mail | `email.footer_text` | text ≤ 2000, Zeilen bleiben | leer | Fußzeile/Pflichtangaben in allen Mails; Filter `form_captain_mail_footer_v1` gewinnt; Rechtsprüfung |
| E-Mail | `email.logo_id` | attachment (Bild), 0 = keins | 0 | Logo oben in HTML-Mails |
| Datenschutz | `privacy.retention` | enum `90d`/`12m`/`manual` | `12m` | |
| Datenschutz | `privacy.trash_days` | int 1–90 | 30 | |
| Datenschutz | `privacy.delete_data_on_uninstall` | bool | false | erfordert Bestätigungstext |
| Datenschutz | `privacy.policy_page_hint` | bool | true | Link auf WP-Datenschutzseite in Consent |
| Spamschutz | `spam.honeypot` | bool | true | |
| Spamschutz | `spam.min_seconds` | int 0–60 | 3 | |
| Spamschutz | `spam.rate_limit` | int 1–100 | 5 | je Zeitfenster / Formular / IP-Hash |
| Spamschutz | `spam.blocklist` | text ≤ 10 000 | leer | ein Begriff/Domain je Zeile |
| Spamschutz | `spam.captcha_provider` | enum `none`/`pow`/Erweiterung | `none` | `pow` = eingebauter Proof of Work (docs/spam-proof-of-work.md); externe Anbieter via Erweiterung mit Bestätigung |
| Spamschutz | `spam.window_minutes` | int 1–1440 | 10 | Zeitfenster aller Sendegrenzen; Filter `form_captain_spam_limits_v1` gewinnt |
| Spamschutz | `spam.form_total` | int 10–100 000 | 300 | Einsendungen je Formular und Fenster (alle Besucher) |
| Spamschutz | `spam.receipts_per_hour` | int 1–50 | 3 | Mails an dieselbe Besucheradresse je Stunde (Bestätigung, Double-Opt-in) |
| Zugriff & Rollen | (Rollen-Capabilities, nicht in Option, sondern `WP_Roles`) | | nur Admin | |

Alle Tabs erfordern `form_captain_manage_settings`. Systemstatus ist schreibgeschützt.

**Formularbezogen und nie global:** Empfänger, Reply-To, Betreff, Feldauswahl, Nutzerbestätigung, Erfolgsmeldung, Redirect, Aktionen. Neue Formulare übernehmen `email.from_*` und `appearance.mode` als **Kopie** in ihr `settings_json`; spätere Änderung der globalen Werte ändert bestehende Formulare nicht (Hinweis in der UI zeigt Anzahl der Formulare mit eigenen Werten).

## Formularwerte aus SET-FC-10/17/19 (`Settings\FormMessagingSettings`)

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

