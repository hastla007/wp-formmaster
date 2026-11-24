# Sicherheitsreview Form Captain (intern)

**Datum:** 16.11.2026 · **Prüfer:** Agent F (unabhängiges internes Review) · **Stand des Codes:** Arbeitskopie vom 16.11.2026 (inkl. WIP-Commit `8a6e29d`)
**Bezug:** `docs/spec/bedrohungsmodell.md` (T-*), `docs/spec/rechtevertrag.md` (OP-*, RL-*), `docs/spec/rechte.md`, `docs/spec/budgets.md`, `docs/adr/0003-rest-und-submission.md` · TODO P5-07, P5-08, P5-09, P5-11, P5-15, P6-16

## 1. Umfang

| Bereich | Dateien |
|---|---|
| Bootstrap, Deinstallation | `form-captain.php`, `uninstall.php`, `includes/functions.php` |
| Öffentliche REST-Routen | `src/Http/RestController.php` (Token, Submission) |
| No-JS-Einsendung | `src/Http/AdminPostController.php` (`admin_post(_nopriv)_form_captain_submit`) |
| Admin-REST | `src/Admin/AdminRest.php` (11 Routen unter `/form-captain/v1/admin/…`) |
| admin-post-Handler | `FormsPage` (14 Aktionen inkl. Import Typ A), `EditorPage` (Vorschau), `EntriesPage` (Bulk, Einzelaktion), `CsvExporter`, `SettingsPage` (4), `IntegrationsPage` (4), `DashboardPage` (4), `Integrations/CoreActions` (Hand-over-Bestätigung) |
| AJAX | keine eigenen `wp_ajax_*`-Handler; nur Yoast-Analyse-Erkennung (`YoastCompat`, rein lesend) |
| WP-CLI | `src/Cli/Commands.php` (`jobs run/list/retry`, `cleanup`, `status`) |
| Privacy | `src/Privacy/PrivacyIntegration.php` (Exporter/Eraser über Core) |
| Cron/Jobs | `src/Jobs/*`, `src/Mail/*`, `src/Integrations/ConnectorRegistry.php`, `JobGrant`, `CoreActions`, `src/Service/RetentionService.php` |
| Rendering | `src/Frontend/*` (Shortcode, Block, Renderer, Assets), Admin-Seiten und List-Tables |
| Daten/SQL | `src/Repository/*`, `src/Database/*`, `src/Security/*` |
| Import/Export | `FormService::import/export`, `FormsPage::import_upload`, `src/Import/*` (Typ-A-Import, nur oberflächlich – siehe 6.) |
| JavaScript | `assets/src/frontend.js`, `editor.js`, `forms-admin.js`, `admin-settings.js`, `block-editor.js` |
| Referenz-Connector | `connectors/form-captain-reference-connector/*` |

Nicht im Umfang: Pro-Funktionen (Uploads, Webhooks, Datei-Downloads – im Free-Code nicht vorhanden), WordPress-Core, Server-/Hosting-Konfiguration, `docs/`.

## 2. Methode

1. **Spezifikationsabgleich:** Jede Route/jeder Handler gegen `rechtevertrag.md` 6 (Auth-Art, Capability, Datenumfang, RL) und die Prüfreihenfolge in 7 abgeglichen; Schutzregeln T-SUB, T-ADM, T-MAIL, T-CSV, T-JSON, T-CTX, T-ADP, T-JOB, T-RED, T-IDOR einzeln geprüft.
2. **Manuelles Code-Review** je Einstiegspunkt: Authentisierung, Capability (vor Objektzugriff), Nonce, IDOR/Zugehörigkeit, Eingabe-Sanitizing, Output-Escaping (PHP und JS – keine `innerHTML`/`dangerouslySetInnerHTML`/`insertAdjacentHTML`/`eval` gefunden), SQL (`$wpdb->prepare`, dynamisches `ORDER BY` nur aus Allowlist), Mail-Header, CSV-Formeln, Redirects, SSRF (kein ausgehender HTTP-Aufruf im Free-Code und im Referenz-Connector), Secrets/Tokens in Logs und Ausgaben, `hash_equals`, Zufallsquellen (`random_bytes`/`random_int`), Rate-Limits, Größenlimits, Deserialisierung (kein `unserialize`/`maybe_unserialize` auf Nutzerdaten; nur `json_decode` mit Tiefenlimit), Uploads beim JSON-Import, Rechte bei Deinstallation/Reset.
3. **Grep-Sweeps** über alle Quellen nach riskanten Funktionen und Mustern (Ergebnis im Anhang A).
4. **Automatisierte Tests** (neu, `tests/integration/Security/`), die Routen und Handler **zur Laufzeit** ermitteln, damit neue Einstiegspunkte automatisch erfasst werden:

| Datei | Task | Inhalt |
|---|---|---|
| `RouteAuthorizationTest.php` | P5-07 | Alle Routen aus `rest_get_server()->get_routes()` und alle `admin_post_form_captain_*`-Hooks aus `$wp_filter`; Aufruf als anonym, Abonnent, Autor, Editor (mit **und** ohne gültige Nonce) → abgelehnt, Zustands-Fingerprint (Plugin-Tabellen, Optionen, Rollen-Caps, User-Meta, Seiten) unverändert; Admin ohne Nonce, mit ungültiger und mit fremder Nonce → abgelehnt; Admin mit Application Password → `forbidden`; neue öffentliche Route bzw. neuer Handler lässt den Test fehlschlagen, bis er bewusst eingetragen ist. |
| `IdorTest.php` | P5-15 | Capability-vor-Existenz (gleiche Antwort für existierende/fehlende IDs) in REST, Services und Contract-API; Zugehörigkeit Job↔Entry; Nonce je Entry; Bulk prüft jede ID; öffentliche Routen nur `public_id`; Submission-Token, No-JS-Ergebnis, Vorschau-Token und Job-Grant an genau ein Objekt/einen Nutzer gebunden; Erfolgsantwort ohne Entry-ID. |
| `InjectionTest.php` | P5-08, P6-16 | SQL-/HTML-/Script-Payloads in Titel, Labels, Hilfetexten, Optionen, HTML-Feldern, Consent-Text, Feldwerten und Suchparametern – direkt in die DB geschrieben, damit das Output-Escaping selbst geprüft wird; Ausgabe in Frontend, Formularliste, Entry-Liste/-Suche/-Detail, Exportmaske, Mail, CSV, JSON-Import neutralisiert; manipulierte Payloads (unbekannte Felder, Arrays, Verschachtelung, ungültiges UTF-8, NUL, CRLF, Längen, 150 Felder, gefälschte/abgelaufene/zukünftige Tokens), 413/415, Replay und parallele Doppel-Requests (UNIQUE `request_key`), Rate-Limit, nicht steuerbarer No-JS-Redirect. |
| `LogPrivacyTest.php` | P5-09 | Nach Submission (ok/Spam/ungültig/Token/Kontext), Mail-Fehler mit personenbezogener Provider-Meldung, Connector-Fehler und -Exception mit Feldwerten, Export, Statuswechsel, Löschung, Settings: `fc_log` ohne E-Mail, IPv4/IPv6, Feldwerte, Tokens, Secret, Salt; Worker ohne Rechte des auslösenden Nutzers; Provider-Texte überleben den Entry nicht. |
| `RoleBoundaryTest.php` | P5-11 | Default nur `administrator`; Lesen ≠ Ändern/Export/Löschen; Schreibrechte ohne `read_entries` wirkungslos; `manage_forms` kann keine Aktionen freigeben und keine Entries löschen; Duplizieren/Import aktivieren nie Aktionen; Rollenverwaltung nur mit `promote_users`, Aussperrschutz; Uninstall: Datenlöschung nur nach Flag + Bestätigung, **jede** in `src/` deklarierte Option wird entfernt (statisch ermittelt). |

Gegenprobe: Für SR-02/SR-03 und einen Capability-Guard wurde der Fix testweise zurückgenommen – die neuen Tests schlugen jeweils fehl.

## 3. Befunde

Schweregrade: **Hoch** (Rechteausweitung/Datenabfluss ohne besondere Voraussetzungen), **Mittel** (Schutzregel verletzt, Ausnutzung braucht weitere Bedingungen), **Niedrig** (Defense-in-Depth, Datensparsamkeit, Audit), **Info** (bewusst akzeptiert/Hinweis).

| ID | Schweregrad | Ort | Beschreibung | Status |
|---|---|---|---|---|
| SR-01 | Hoch | `src/Service/FormService.php:84-94` (`create()`), erreichbar über `POST /form-captain/v1/admin/forms` | `create()` übernahm `settings.actions` ungeprüft (`sanitize_settings` kopiert `actions` roh). Ein Nutzer mit nur `form_captain_manage_forms` konnte so ein Formular mit **aktivierten** Connector-Aktionen und beliebigem Mapping (auch auf `sensitive`-Felder, beliebige Settings-Keys) anlegen und damit OP-FRM-10 (`manage_forms` **und** `manage_integrations`) sowie `sanitize_actions()` umgehen. Nach Veröffentlichung wären Feldwerte an aktive Connectoren geflossen. | **Behoben:** Aktionen laufen in `create()` durch `sanitize_actions()` und werden immer deaktiviert angelegt (wie bei Import/Duplizieren). Test `RoleBoundaryTest::test_manage_forms_cannot_release_actions_or_delete_entries`. |
| SR-02 | Mittel | `src/Jobs/JobRunner.php:81-94` | T-JOB-06 nicht umgesetzt: Job-Handler liefen mit dem aktuellen Nutzer des auslösenden Requests (Admin-Fallback in `admin_init`, `wp form-captain jobs run --user=…`). Ein Connector-Callback erhielt dadurch über `form_captain_get_entry_v1()` alle Werte inkl. `sensitive`, statt nur der per Job-Grant gemappten Felder (T-ADP-01, rechtevertrag 5). | **Behoben:** `wp_set_current_user( 0 )` vor jedem Handler, Wiederherstellung im `finally`. Test `LogPrivacyTest::test_worker_runs_handlers_without_the_triggering_users_capabilities`. |
| SR-03 | Mittel | `src/Jobs/JobRunner.php:101`, `src/Service/RetentionService.php:168`, `:447` | `fc_log` speicherte Freitext aus Handler-/Connector-Ergebnissen und Exception-Meldungen (Drittcode, Provider). `Logger::redact()` entfernt nur E-Mail, IP, Token-Muster und lange Ziffernfolgen – Namen, Nachrichtentexte o. Ä. blieben im Log (P5-09, T-WH-07-Analogie). | **Behoben:** In `fc_log` nur noch Fehlercode bzw. Exception-Klasse; der redigierte Text bleibt für die Diagnose ausschließlich am Job (`fc_jobs.last_error_message`, siehe SR-08). Tests `LogPrivacyTest::test_connector_failures_and_exceptions_with_personal_data`, `…test_mail_errors_with_personal_data_in_provider_message`. |
| SR-04 | Mittel | `uninstall.php:34-61` | Die Deinstallation mit Datenlöschung ließ u. a. `form_captain_secret_previous` (vorheriger Signaturschlüssel, A-05), `form_captain_rl_salt`, Retention-/Setup-/Cleanup-Optionen, Typ-A-Importberichte (`form_captain_import_{id}`), Transients mit No-JS-Feldwerten/Vorschau-Tokens und die User-Meta des Pro-Hinweises zurück. | **Behoben:** vollständige Optionsliste, Präfix-Löschung für Import-Records und Plugin-Transients, User-Meta. Test `RoleBoundaryTest::test_uninstall_deletes_only_after_confirmation_and_removes_every_option` ermittelt alle Optionsnamen statisch aus `src/` und schlägt bei neuen, nicht entfernten Optionen fehl. |
| SR-05 | Niedrig | `src/Admin/AdminRest.php:137-142` | Ablehnung von Application Passwords nur anhand des `Authorization`-Headers. Auf Servern, die Basic-Auth nur über `PHP_AUTH_USER` durchreichen, fehlt der Header – Admin-Routen wären dann mit App-Passwort nutzbar (rechtevertrag 1, WP-REST). Nonce-Pflicht entfällt bei App-Passwörtern. | **Behoben:** zusätzlich `rest_get_authenticated_app_password()` (Core-Status der Authentisierung). Test `RouteAuthorizationTest::test_admin_routes_refuse_application_passwords`. |
| SR-06 | Niedrig | `src/Admin/IntegrationsPage.php:87-104` | Retry/Abbruch von Integrations-Jobs ohne Audit-Eintrag (T-JOB-05, T-ADM-06); Mail-Retry am Entry protokollierte bereits. | **Behoben:** `job_manual_retry` / `job_manual_cancel` mit User-ID und Job-ID. |
| SR-07 | Niedrig | `src/Admin/EditorPage.php:56-57` | Editor-Bootstrap (`window.formCaptainEditor`) als Inline-Script mit `wp_json_encode()` ohne `JSON_HEX_TAG`. Enthält Seitentitel anderer Nutzer (Autoren) und Labels; `</script>` war durch Slash-Escaping zwar entschärft, `<!--`/`<script`-Sequenzen konnten aber den HTML-Parser-Zustand des Script-Blocks verändern. | **Behoben:** `JSON_HEX_TAG | JSON_HEX_AMP`. |
| SR-08 | Niedrig | `src/Repository/JobRepository.php:185-195` | `fc_jobs.last_error_message` (redigierter Provider-Text, kann Namen zitieren) blieb nach Löschung/Anonymisierung des Entries bis zur Job-Bereinigung (30/90 Tage) erhalten. | **Behoben:** `cancel_for_entry()` (Löschen, Privacy-Eraser, Anonymisierung) leert die Texte aller Jobs des Entries. Test `LogPrivacyTest::test_provider_error_texts_do_not_outlive_the_entry`. |
| SR-09 | Info | `src/Http/RestController.php:136-152` | Token-Route gibt bei Kontext-Ausstellung Detailcodes (`context_purpose_unknown`, `context_ref_rejected` …) in `context_error` zurück; minimales Orakel über registrierte Zwecke. | **Akzeptiert:** so spezifiziert (`suite-kontext.md` TC-CTX-22); betrifft nur Ausstellung, die Verifikation gibt öffentlich nur die drei Codes nach T-CTX-06 aus. |
| SR-10 | Info | `src/Frontend/Shortcode.php:68` | Shortcode/Block akzeptiert neben `public_id` auch numerische Formular-IDs. | **Akzeptiert:** Eingabe stammt von Redakteuren (Beitragsinhalt), nicht aus Requests; Entwürfe/Archiv werden Besuchern nicht gerendert; T-IDOR-03 betrifft öffentliche Routen, diese nehmen nur `public_id` (getestet). |
| SR-11 | Info | `src/Frontend/Renderer.php:411-433`, `src/Http/AdminPostController.php:103-113` | No-JS-Fehlerfall legt nicht sensible Feldwerte 10 min in einem Transient ab; Schlüssel (128 Bit) steht in der URL und kann in Browser-Verlauf/Server-Logs landen. | **Akzeptiert:** sensible, Consent- und Hidden-Felder ausgeschlossen, an `public_id` gebunden, kurze TTL; nach SR-04 auch bei Deinstallation gelöscht. Empfehlung siehe 5. |
| SR-12 | Info | `src/Admin/FormsPage.php` (`action_url()`), `FormsListTable` | Formularaktionen inkl. Löschen werden als GET-Links mit Nonce (`wp_nonce_url`) ausgelöst. | **Akzeptiert:** WordPress-üblich, Nonce je Aktion **und** Formular-ID, Capability vor Nonce, Löschen mit Entries zusätzlich `delete_entries` + Bestätigung. T-ADM-01 („GET ändert nie Zustand“) gilt für REST und ist dort eingehalten. |
| SR-13 | Info | `EntriesPage::NONCE_*`, `CsvExporter::NONCE_ACTION`, `IntegrationsPage` | Nonce-Aktionsnamen weichen teils vom Schema `form_captain_{aktion}` ab bzw. werden per `wp_verify_nonce` statt `check_admin_referer` geprüft. | **Akzeptiert:** funktional gleichwertig, objektgebunden; `RouteAuthorizationTest` belegt, dass keine Nonce eine andere Aktion freischaltet. |
| SR-14 | Info | WordPress-Core (`rest_send_cors_headers`) | Admin-Routen tragen die von Core gesetzten CORS-Header mit Credentials. | **Akzeptiert:** Cookie-Requests ohne gültige `wp_rest`-Nonce werden von Core anonymisiert (getestet: 401/403); öffentliche Routen entfernen CORS-Header (T-SUB-11). |
| SR-15 | Info | `src/Admin/SettingsPage.php:273`, `CsvExporter::check_request()` | Testmail-Limit 3/10 min statt RL-06 (5) und Export-Limit per Transient (nicht atomar). | **Akzeptiert:** strenger als Spec bzw. nur gegen versehentliche Mehrfachauslösung durch berechtigte Admins gedacht. |

Ohne Befund geprüft (Auswahl): SQL ausschließlich über `prepare`, `ORDER BY` nur aus Allowlist (`FormRepository::list`), `LIKE` mit `esc_like`; Submission-Token und Signed Context mit `hash_equals`, Signatur vor JSON-Dekodierung, Tiefe 2; Honeypot-Name per HMAC; Rate-Limit-Buckets per HMAC ohne Klar-IP, IP nur aus `REMOTE_ADDR`; Größenprüfung per `Content-Length` vor dem Parsen und erneut am Body; Hidden-Felder aus Schema-Default; Mail: `From` nur aus Einstellungen, Empfänger statisch, Reply-To nach CRLF-Prüfung und `is_email`, HTML-Teil durchgehend `esc_html`; CSV: Formelschutz inkl. Vollbreitenzeichen und Kopfzeilen, Streaming, POST + Nonce + Capability; JSON-Import ≤ 256 KB, `json_decode` Tiefe 32, Validierung vor Speicherung, Aktionen deaktiviert, Redirect-Seite neu validiert; Redirects nur auf veröffentlichte interne Seiten bzw. Quellseite über `wp_validate_redirect`; Vorschau-Token 128 Bit, nutzergebunden, 30 min; Privacy-Exporter/-Eraser über Core-Capabilities, Löschung über denselben Service wie Admin; WP-CLI nur lokal/Shell; Referenz-Connector ohne Netzwerkzugriff; Frontend-JS setzt Texte ausschließlich über `textContent`, kein Nonce im gecachten HTML.

## 4. Verbleibende Risiken

1. **Drittcode im selben Prozess:** Connectoren/Adapter laufen mit vollen PHP-Rechten. Job-Grant und Scope-Prüfung schützen nur die Contract-API, nicht direkte DB-Zugriffe eines bösartigen Plugins (Vertragsregel, technisch nicht erzwingbar – T-ADP-06).
2. **Rate-Limit hinter Proxies/CDN:** Ohne passende Einstellung `spam.client_ip_source` (SET-FC-16) oder Filter `form_captain_client_ip` teilen sich alle Besucher die Proxy-IP (Fehlsperren) bzw. greift das Limit nicht pro Besucher.
3. **Spam-Maskierung:** Honeypot-/Blocklisten-Treffer antworten wie Erfolg; Missbrauch für Mail-Relay ist durch statische Empfänger und Receipt-Limit begrenzt, Datenbankwachstum durch RL-02…RL-04.
4. **No-JS-Ergebnisschlüssel in der URL** (SR-11) und **Typ-A-Importquelle im Transient** (nutzergebunden, nur oberflächlich geprüft).
5. **Neuer Code in Arbeit:** `src/Import/*` (u. a. 1 000-Zeilen-Parser `ShortcodeFormatParser`) und `src/Integrations/CoreActions.php` wurden während des Reviews von anderen Agenten ergänzt. Einstiegspunkte (Capability, Nonce, Nutzerbindung, Escaping) sind geprüft und durch `RouteAuthorizationTest` abgedeckt; der Parser selbst (ReDoS, Grenzfälle) nicht vertieft.
6. **Admin-Fallback des Workers** läuft im Admin-Request; nach SR-02 ohne Nutzerrechte, aber mit Laufzeitbudget im Request des Admins.
7. **Kein CSP** für Admin-Vorschau und Frontend; Schutz beruht auf konsequentem Escaping.

## 5. Empfehlungen für das externe Review vor Release

1. **Pentest der öffentlichen Fläche** (Token-/Submission-Route, No-JS-Fallback) mit echten Proxys/Caches: Rate-Limit-Umgehung, Cache-Poisoning, Replay über Cache-Varianten, Verhalten bei `Transfer-Encoding: chunked` ohne `Content-Length`.
2. **Fuzzing** von `SubmissionValidator`, `SchemaValidator`, `FormService::import` und `Import\ShortcodeFormatParser` (Laufzeit/ReDoS, Speicher, Unicode-Normalisierung, Homoglyphen in CSV-Formelprüfung).
3. **Code-Review der Pro-Funktionen** gegen T-UPL, T-DL, T-WH (Uploads, Downloads, Webhook-SSRF inkl. DNS-Rebinding) – im Free-Code nicht vorhanden und daher hier nicht geprüft.
4. **Statische Analyse** mit PHPStan (Level laut `phpstan.neon.dist`) und einem Taint-Analyzer (z. B. Psalm-Taint) in CI; `vendor/bin/phpstan` war in dieser Umgebung nicht installiert.
5. **Header-Härtung prüfen:** `Referrer-Policy: same-origin` auf Seiten mit `fc_r`-Parameter, optionale CSP für die Admin-Vorschau.
6. **Multisite/Super-Admin** (laut Spec nicht unterstützt) explizit abweisen oder testen, bevor das Plugin dort aktivierbar ist. *Erledigt 24.11.2026 (SET-FC-44): Multisite unterstützt; Super-Admins ohne eigene Rolle erhalten die Entry-Capabilities nicht (Standard), Daten strikt je Site; Tests MS-01 bis MS-12 (ohne MS-11) in `tests/integration/Multisite/MultisiteTest.php`.*
7. **Security-Tests verpflichtend halten:** Die Tests unter `tests/integration/Security/` erfassen neue Routen, Handler und Optionen automatisch und schlagen fehl, bis diese bewusst eingetragen sind – nicht abschwächen, sondern Einträge ergänzen.

## Anhang A – Grep-Sweeps (Ergebnis)

| Muster | Treffer | Bewertung |
|---|---|---|
| `unserialize`, `maybe_unserialize` | 0 | – |
| `eval`, `new Function`, `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `dangerouslySetInnerHTML` | 0 | – |
| `wp_remote_*`, `curl`, `file_get_contents` mit URL | 0 (nur lokale Dateien: Templates mit Allowlist, Asset-Manifest, Upload-Tempdatei nach `is_uploaded_file`) | ok |
| `mt_rand`, `rand(`, `uniqid` (Produktivcode) | 0 (`wp_rand` nur für Bereinigungs-Wahrscheinlichkeit) | ok |
| `permission_callback => '__return_true'` | 3 (nur öffentliche Routen Token/Submission/405) | spezifiziert |
| `md5(` | 2 (DOM-ID, Transient-Name) | keine Sicherheitsfunktion |

## Anhang B – Testergebnis

`WP_TESTS_DB_NAME=wp_tests_f vendor/bin/phpunit` → **OK (351 Tests, 4 521 Assertions)**, davon 49 Tests in `tests/integration/Security/`. `vendor/bin/phpcs` auf allen geänderten Dateien: 0 Fehler (nur vorbestehende Warnungen zu ungenutzten Parametern/Schleifenbedingung).
