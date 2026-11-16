# Rechtevertrag je Operation (P0-17)

**Status:** Entwurf zur Freigabe · **Stand:** 16.11.2026 · **Gilt für:** Form Captain Free v1.0, Pro v1 (markiert), Contract v1
**Bezug:** konzept.md 4.1, 5, 6, 7 · TODO P0-07, P0-17, P2-03, P3-06, P3-11, P3-13, P5-07, P5-11, P5-15, P8-01, P8-28
**Verwandte Specs:** `bedrohungsmodell.md`, `datenschutz.md`, `suite-kontext.md`

Jede Operation des Plugins ist hier genau einmal aufgeführt. Eine Route/Funktion, die hier fehlt, darf nicht implementiert werden, bevor sie ergänzt ist. Tests (AC-SEC-*, P5-07) prüfen jede Zeile einzeln.

---

## 1. Authentisierungsarten

| Kürzel | Art | Prüfung |
|---|---|---|
| **PUB-T** | Öffentlich mit Submission-Token | Kein Login nötig. Token aus `GET /form-captain/v1/forms/{public_id}/token`: HMAC (Label `form-captain/submit-token/v1`) über `form_public_id` + `issued_at` + 16 Byte Zufall; gültig 2 h; ≥ Mindestzeit (Default 3 s) alt; an `public_id` gebunden; nonce-frei, nicht nutzergebunden, daher cache-sicher. Kein WordPress-Nonce. |
| **PUB** | Öffentlich ohne Token | Nur lesend, nur veröffentlichte Daten, rate-limitiert. |
| **WP-REST** | Eingeloggt, Cookie + `X-WP-Nonce` (Action `wp_rest`) | WordPress-Cookie-Authentisierung; Nonce im Header oder als `_wpnonce` im POST-Body (nur für Datei-Downloads/Exporte per Formular-POST). Application Passwords werden für Admin-Routen in v1 **nicht** akzeptiert (Filter: Admin-Routen lehnen `Authorization: Basic` mit `forbidden` ab). |
| **WP-ADMIN** | Eingeloggt, Admin-Seite/`admin-post.php` | `check_admin_referer( 'form_captain_{aktion}' )` + Capability. |
| **PHP** | Aufruf im selben PHP-Prozess (Contract v1) | Kein Netzwerkpfad. Capability des aktuellen Nutzers **oder** aktiver Job-Grant (Abschnitt 5) je nach Funktion. |
| **SYS** | Systemkontext (WP-Cron, Worker) | Kein Nutzer (`wp_set_current_user(0)`); nur interne Handler; keine Request-Parameter. |
| **CORE** | Von WordPress-Core aufgerufen | Core prüft Capability; Form Captain prüft zusätzlich nichts, verlässt sich aber nicht auf `current_user`. |

## 2. Capabilities und Standardrollen

| Capability | Erlaubt | Administrator | Alle anderen Rollen |
|---|---|---|---|
| `form_captain_manage_forms` | Formulare anlegen, bearbeiten, veröffentlichen, pausieren, archivieren, duplizieren, löschen (ohne Entries), JSON-Import/-Export, Vorschau | ✔ | ✘ |
| `form_captain_read_entries` | Entry-Liste/-Detail, Notizen lesen, Mail-Status am Entry, Datei-Download (Pro) | ✔ | ✘ |
| `form_captain_edit_entries` | Status ändern (new/read/archived/spam), Notizen anlegen, Mail-Retry am Entry | ✔ | ✘ |
| `form_captain_export_entries` | CSV-Export | ✔ | ✘ |
| `form_captain_delete_entries` | Papierkorb, Wiederherstellen, endgültig löschen, Formular samt Entries löschen | ✔ | ✘ |
| `form_captain_manage_settings` | Einstellungen aller Tabs, Systemstatus, Testmail, Schlüsselrotation, Uninstall-Flag, Rollenzuweisung (zusätzlich `promote_users`) | ✔ | ✘ |
| `form_captain_manage_integrations` | Integrationen/Connectoren aktivieren, konfigurieren, testen; Formularaktionen freigeben; Integrations-Jobs ansehen/wiederholen/abbrechen | ✔ | ✘ |

- Registrierung bei Aktivierung (P1-10), nur an `administrator`. Keine Meta-Capabilities, keine objektbezogenen Rechte in v1 (alle Rechte gelten für alle Formulare der Site).
- Implizite Voraussetzung: Wer `edit_entries`, `export_entries` oder `delete_entries` besitzt, braucht für die zugehörige UI zusätzlich `read_entries`; Routen prüfen beide.
- Menü: „Form Captain“ sichtbar bei mindestens einer der Capabilities; Unterseiten jeweils mit der Capability der zugehörigen Lese-Route.

## 3. Fehlercodes

Antwortformat REST (öffentlich und Admin): `{ "success": false, "code": "<code>", "message": "<übersetzt>", "request_id": "<uuid>", "errors": { "<field_id>": ["<feldcode>"] } }`. `errors` nur bei `validation_failed`/`schema_invalid`. Contract-Funktionen liefern dasselbe Objekt als PHP-Array (plus `form_id`/`entry_id`), werfen keine Exceptions für erwartete Fehler.

| Code | HTTP | Bedeutung |
|---|---|---|
| `unauthenticated` | 401 | Admin-Route ohne Login |
| `forbidden` | 403 | Capability fehlt, Scope nicht gewährt, Auth-Art nicht zulässig |
| `invalid_nonce` | 403 | `X-WP-Nonce`/`_wpnonce`/Admin-Referer fehlt oder ungültig |
| `invalid_token` | 400 | Submission-Token fehlt, falsch signiert, abgelaufen, zu jung (Mindestzeit) oder für anderes Formular |
| `invalid_context` | 400 | `_fc_ctx` ungültig (Detailcodes `suite-kontext.md` 5) |
| `form_not_found` | 404 | Formular existiert nicht oder ist öffentlich nicht sichtbar (draft/archived) |
| `form_not_accepting` | 409 | Formular `paused` |
| `not_found` | 404 | Entry/Notiz/Job/Datei/Integration nicht vorhanden oder gehört nicht zum übergeordneten Objekt |
| `validation_failed` | 400 | Feldvalidierung fehlgeschlagen; Feldcodes z. B. `required`, `invalid_email`, `too_long`, `out_of_range`, `invalid_option`, `unknown_field`, `consent_required` |
| `schema_invalid` | 400 | Formularschema/Import ungültig |
| `schema_unsupported` | 400 | Schema-Version unbekannt/zu neu (Import) |
| `too_many_fields` | 413 | mehr Felder als Budget (`budgets.md`) |
| `revision_conflict` | 409 | Schema-Revision beim Speichern veraltet (Editor) |
| `invalid_scope` | 400 | unbekannter Scope in `form_captain_get_entry_v1` |
| `invalid_request` | 400 | Parameter fehlen/haben falschen Typ (Admin-Routen) |
| `conflict` ¹ | 409 | unzulässiger Status-/Lebenszyklusübergang (`zustaende.md`), Formular mit Entries ohne Löschbestätigung |
| `rate_limited` | 429 | Rate-Limit überschritten; Header `Retry-After` |
| `spam_suspected` | 200/400 | Blockliste getroffen. Öffentlich: Default-Antwort wie Erfolg (200, `success: true`, Code `created`), damit Bots nichts lernen; bei Einstellung „Spam sichtbar ablehnen“ 400. Contract: `success: false`, `code: spam_suspected`. |
| `payload_too_large` | 413 | Body/Felder/Dateien über Limit |
| `unsupported_media_type` | 415 | Content-Type nicht `application/json` bzw. `multipart/form-data`/`application/x-www-form-urlencoded` |
| `incompatible_contract` | – (Diagnose) / 409 | Adapter/Connector-Major-Version inkompatibel |
| `connector_disabled` | 409 | Aktion/Test für deaktivierte Integration |
| `connector_not_found` | 404 | Integration/Connector-Key unbekannt |
| `job_not_found` | 404 | Job-ID unbekannt |
| `context_expired` / `context_replayed` | 400 | Kontext abgelaufen bzw. Einweg-Kontext wiederverwendet (`suite-kontext.md` 5) |
| `storage_failed` | 500 | Entry konnte nicht gespeichert werden (Transaktion zurückgerollt) |
| `job_not_retryable` | 409 | Retry für Job in Status `succeeded`/`running`/`cancelled` |
| `export_too_large` | 400 | CSV-Export > 100 000 Zeilen |
| `not_ready` | – | Contract-Aufruf vor `form_captain_loaded_v1` |
| `internal_error` | 500 | Unerwarteter Fehler; Details nur in `fc_log` |

Erfolgscodes: `created` (201 REST / 303 No-JS; auch Ergebnis von `form_captain_submit_v1`), `duplicate_request` ¹ (200, gleiche Antwort wie Original, T-SUB-12), `ok` (200), `deleted` (200).

Codes aus `api-v1.md` sind maßgeblich. Mit ¹ markierte sowie `unauthenticated`, `invalid_scope`, `invalid_request`, `not_found`, `unsupported_media_type`, `job_not_retryable`, `export_too_large`, `not_ready` sind Ergänzungen dieser Spec und in die stabile Liste in `api-v1.md` aufzunehmen.

## 4. Rate-Limits (Defaults)

Schlüssel `ip_hash` gemäß `datenschutz.md` 9. Werte sind Defaults; finale Budgets in P0-19. Einstellbar unter *Spamschutz* (nur Submission/Token).

| ID | Bereich | Schlüssel | Limit | Fenster |
|---|---|---|---|---|
| RL-01 | Token holen | `ip_hash` | 30 | 10 min |
| RL-02 | Submission je Formular | `form_id` + `ip_hash` | 5 | 10 min |
| RL-03 | Submission global | `ip_hash` | 20 | 10 min |
| RL-04 | Submission je Formular gesamt (Flutbremse) | `form_id` | 300 | 10 min |
| RL-05 | Receipt je Empfänger | HMAC(E-Mail) | 3 | 60 min |
| RL-06 | Testmail | `user_id` | 5 | 10 min |
| RL-07 | Integrations-Testlauf | `user_id` + Integration | 5 | 10 min |
| RL-08 | CSV-Export | `user_id` | 1 gleichzeitig, 10 | 60 min |
| RL-09 | `form_captain_submit_v1` | `form_id` + `$context['source']` | 120 | 10 min |

Admin-Routen sind ansonsten nicht rate-limitiert. Eingeloggte Nutzer unterliegen bei öffentlichen Routen denselben Limits (Schlüssel bleibt `ip_hash`).

## 5. Scopes für Entry-Daten

Scopes laut `api-v1.md`:

| Scope | Inhalt |
|---|---|
| `meta` | `entry_id`, `form_id`, Form-Version, `created_gmt`, `status`, verifizierter Kontext `{purpose, ref_type, ref_id, verified}`, Anonymisierungsmarker |
| `values` | alle nicht sensiblen Feldwerte (Felder mit `sensitive = false`) |
| `sensitive` | zusätzlich Felder mit `sensitive = true` |

Interne Notizen sind über die Contract-API nicht abrufbar.

**Gewährung:**

| Aufrufer | Erlaubte Scopes |
|---|---|
| Nutzer mit `form_captain_read_entries` | `meta`, `values` |
| Nutzer mit `form_captain_read_entries` + `form_captain_export_entries` | zusätzlich `sensitive` |
| Adapter-Callback bzw. Code innerhalb eines laufenden Jobs (Job-Grant: gesetzt vom Worker für genau `entry_id` des Jobs, gelöscht nach Rückkehr) | `meta`; `values` **gefiltert auf die für diese Aktion gemappten Felder**; `sensitive` nur für gemappte Felder, die in der Aktionsfreigabe ausdrücklich als sensibel bestätigt wurden |
| alle anderen | keine → `forbidden` |

Angeforderte, nicht gewährte Scopes führen zu `forbidden` für den ganzen Aufruf (kein stilles Weglassen). Unbekannte Scopes → `invalid_scope`.

## 6. Operationen

Admin-Routen sind relativ zum Namespace angegeben: `/admin/forms` = `/wp-json/form-captain/v1/admin/forms`. Kurzformen `manage_forms` usw. stehen für `form_captain_manage_forms` usw.

Spalten: **Auth** (Abschnitt 1) · **Capability** (zusätzlich zur Auth) · **Scope/Datenumfang** · **RL** (Abschnitt 4) · **Fehlercodes** (zusätzlich immer möglich: `internal_error`).

### 6.1 Öffentliche Operationen

| ID | Operation | Route / Aufruf | Auth | Capability | Scope / Datenumfang | RL | Fehlercodes |
|---|---|---|---|---|---|---|---|
| OP-PUB-01 | Submission-Token holen | `GET /form-captain/v1/forms/{public_id}/token` (optional `ctx_purpose`, `ctx_ref_type`, `ctx_ref_id`) | PUB | – | Antwort: `token`, `expires_at`, `min_submit_at`, optional `_fc_ctx` / `context_error`. Header `Cache-Control: no-store, private`. Nur `published`-Formulare. | RL-01 | `form_not_found`, `form_not_accepting`, `rate_limited` |
| OP-PUB-02 | Einsendung (JS) | `POST /form-captain/v1/forms/{public_id}/submissions` (JSON oder multipart bei Pro-Uploads) | PUB-T | – | Schreibt Entry; Antwort ohne Entry-ID/Werte (T-SUB-08) | RL-02, RL-03, RL-04 | `invalid_token`, `invalid_context`, `form_not_found`, `form_not_accepting`, `validation_failed`, `rate_limited`, `spam_suspected`, `payload_too_large`, `unsupported_media_type` |
| OP-PUB-03 | Einsendung (No-JS) | `POST /wp-admin/admin-post.php?action=form_captain_submit` (Hooks `admin_post_nopriv_form_captain_submit` und `admin_post_form_captain_submit`) | PUB-T (Token beim Rendern eingebettet) | – | wie OP-PUB-02; Antwort: 303 auf Quellseite mit `fc_status`-Fragment oder Redirect-Ziel; bei Fehler/abgelaufenem Token Neurendern des Formulars mit Werten (ohne Uploads) und frischem Token | wie OP-PUB-02 | wie OP-PUB-02 |
| OP-PUB-04 | Formular rendern (Block/Shortcode) | `[form_captain id="{public_id}"]`, Block `form-captain/form` | PUB | – | nur `published` (bei `paused`: neutrale Meldung); Draft/Archiv: leere Ausgabe, für Nutzer mit `manage_forms` Hinweis | – | – (keine Fehlerausgabe an Besucher) |
| OP-PUB-05 | Vorschau | `?fc_preview={token}` auf beliebiger Seite bzw. Admin-Vorschau | WP-ADMIN | `form_captain_manage_forms` | Vorschau-Token 32 Hex, an `user_id` gebunden, 30 min gültig (Transient); Absenden erzeugt keinen Entry/Job (P2-14) | – | `forbidden`, `invalid_token` |

### 6.2 Formulare (Admin)

| ID | Operation | Route | Auth | Capability | Scope / Datenumfang | RL | Fehlercodes |
|---|---|---|---|---|---|---|---|
| OP-FRM-01 | Liste | `GET /form-captain/v1/admin/forms` | WP-REST | `manage_forms` | Name, Status, Entry-Anzahl, Änderungsdatum | – | `unauthenticated`, `forbidden`, `invalid_nonce` |
| OP-FRM-02 | Anlegen (leer/Vorlage) | `POST /admin/forms` | WP-REST | `manage_forms` | Status immer `draft` | – | + `schema_invalid`, `too_many_fields` |
| OP-FRM-03 | Lesen | `GET /admin/forms/{id}` | WP-REST | `manage_forms` | vollständiges Schema + Einstellungen, ohne Secrets | – | + `not_found` |
| OP-FRM-04 | Speichern | `PUT /admin/forms/{id}` mit `schema_revision` | WP-REST | `manage_forms` | Integrationsaktionen nicht über diese Route änderbar (OP-FRM-10) | – | + `schema_invalid`, `too_many_fields`, `revision_conflict`, `payload_too_large` |
| OP-FRM-05 | Status setzen (publish/pause/archive/draft) | `POST /admin/forms/{id}/status` | WP-REST | `manage_forms` | Publish erzeugt `fc_form_versions`-Snapshot; Übergänge gemäß Zustandsmodell (P0-06) | – | + `conflict`, `schema_invalid` |
| OP-FRM-06 | Duplizieren | `POST /admin/forms/{id}/duplicate` | WP-REST | `manage_forms` | neue `public_id`, Status `draft`, Aktionen deaktiviert | – | + `not_found` |
| OP-FRM-07 | Löschen | `DELETE /admin/forms/{id}` | WP-REST | `manage_forms`; mit vorhandenen Entries zusätzlich `delete_entries` und Parameter `delete_entries=true` | ohne Entries: hart löschen; mit Entries ohne Parameter: `conflict` (Hinweis „archivieren“) | – | + `not_found`, `conflict`, `forbidden` |
| OP-FRM-08 | JSON-Export | `POST /admin/forms/{id}/export` (`_wpnonce` im Body, Download) | WP-REST | `manage_forms` | nur Schema/Einstellungen (T-JSON-06) | – | + `not_found` |
| OP-FRM-09 | JSON-Import | `POST /admin/forms/import` (multipart, ≤ 1 MB) | WP-REST | `manage_forms` | erzeugt `draft`, Importbericht | – | + `schema_invalid`, `schema_unsupported`, `payload_too_large`, `unsupported_media_type` |
| OP-FRM-10 | Formularaktionen (Connector/Adapter) freigeben + Feldmapping | `PUT /admin/forms/{id}/actions` | WP-REST | `manage_forms` **und** `manage_integrations` | Mapping nur auf vorhandene Feld-IDs; `sensitive`-Felder einzeln bestätigen | – | + `connector_disabled`, `connector_not_found`, `incompatible_contract`, `invalid_request` |
| OP-FRM-11 | Versionen lesen | `GET /admin/forms/{id}/versions[/{version}]` | WP-REST | `manage_forms` | Snapshots inkl. Consent-Texte | – | + `not_found` |
| OP-FRM-12 | Vorlagen auflisten | `GET /admin/templates` | WP-REST | `manage_forms` | Kontakt, Angebot, Support | – | – |
| OP-FRM-13 | CSV-Eintragsimport (Upload, Spaltenzuordnung + Trockenlauf, Import-Schritt, Abbrechen) | `admin-post.php?action=form_captain_form_entries_{upload,map,import,cancel}` mit `form`, Nonce `form_captain_form_entries_*_{id}` | WP-Cookie + Nonce | `manage_forms` **und** `edit_entries` | nur veröffentlichte/pausierte Formulare; Datei ≤ 5 MB / 10 000 Zeilen, nur temporär im privaten Upload-Verzeichnis; Status-Transient an Benutzer und Formular gebunden; keine Mails/Jobs/`entry_created_v1` | – | `forbidden`, `form_not_published`, `payload_too_large`, `too_many_rows`, `invalid_file`, `invalid_mapping`, `time_format_unknown`, `import_expired` |
| OP-FRM-14 | CSV-Eintragsimport zurücknehmen | `admin-post.php?action=form_captain_form_entries_revert` mit `form`, `fc_run` | WP-Cookie + Nonce | `manage_forms` **und** `delete_entries` | löscht genau die Entries des Laufs (`context_json.import_run`) über `EntryDeletionService` (`reason = import_reverted`), batchweise, nur ≤ 7 Tage nach dem Lauf | – | `forbidden`, `import_not_found`, `revert_expired` |

### 6.3 Entries (Admin)

| ID | Operation | Route | Auth | Capability | Scope / Datenumfang | RL | Fehlercodes |
|---|---|---|---|---|---|---|---|
| OP-ENT-01 | Liste/Suche/Filter | `GET /admin/entries?form_id&status&from&to&search&page&per_page≤100` | WP-REST | `read_entries` | Listenspalten (Formular, Datum, Status, Kontaktfelder lt. Formular), keine Notizen | – | `unauthenticated`, `forbidden`, `invalid_nonce`, `invalid_request` |
| OP-ENT-02 | Detail | `GET /admin/entries/{id}` | WP-REST | `read_entries` | alle Werte, Labels aus Entry-Version, Notizen, Mail-/Job-Status (nur Codes), Consent-Nachweis, Kontext. **Ändert keinen Status.** | – | + `not_found` |
| OP-ENT-03 | Status `new`, `read`, `archived`, `spam` setzen; Auto-Read | `PATCH /admin/entries/{id}` `{status}` bzw. `{status: "read", auto: true}` | WP-REST | `read_entries` + `edit_entries`; Auto-Read (nur `new` → `read`, ausgelöst beim Öffnen der Detailansicht) nur `read_entries` (`zustaende.md`) | Übergang gemäß `zustaende.md`; `form_captain_entry_status_changed_v1` nach Commit | – | + `not_found`, `conflict` |
| OP-ENT-04 | In Papierkorb / Wiederherstellen | `PATCH /admin/entries/{id}` `{status: "trash"}` bzw. `POST /admin/entries/{id}/restore` | WP-REST | `read_entries` + `delete_entries` | Restore setzt `previous_status` | – | + `not_found`, `conflict` |
| OP-ENT-05 | Endgültig löschen | `DELETE /admin/entries/{id}` | WP-REST | `read_entries` + `delete_entries` | Hard-Delete inkl. Werte/Notizen/Dateien, Jobs `cancelled` | – | + `not_found` |
| OP-ENT-06 | Bulk-Aktion | `POST /admin/entries/bulk` `{action, ids[≤500]}` | WP-REST | je Aktion wie OP-ENT-03/04/05 | jede ID einzeln geprüft; Antwort `{done[], skipped[{id, code}]}` | – | + `invalid_request` |
| OP-ENT-07 | Notiz anlegen | `POST /admin/entries/{id}/notes` | WP-REST | `read_entries` + `edit_entries` | Text ≤ 5 000 Zeichen, Plaintext | – | + `not_found`, `validation_failed` |
| OP-ENT-08 | Notiz löschen | `DELETE /admin/entries/{id}/notes/{note_id}` | WP-REST | eigene Notiz: `edit_entries`; fremde: `delete_entries` | Zugehörigkeit `note.entry_id` | – | + `not_found`, `forbidden` |
| OP-ENT-09 | CSV-Export | `POST /admin/entries/export` (`_wpnonce` im Body; `form_id` Pflicht, `from`, `to`, `status[]`, `columns[]`) | WP-REST | `read_entries` + `export_entries` | Streaming, T-CSV-* | RL-08 | + `export_too_large`, `invalid_request`, `rate_limited` |
| OP-ENT-10 | Datei herunterladen (Pro) | `GET /admin/files/{file_id}/download?_wpnonce=` | WP-REST | `read_entries` | nur `status = clean`; Header T-DL-03 | – | + `not_found` |
| OP-ENT-11 | Mail eines Entries erneut senden | `POST /admin/entries/{id}/jobs/{job_id}/retry` | WP-REST | `read_entries` + `edit_entries` | nur Jobtypen `mail.admin`, `mail.confirmation`; Entry muss existieren | – | + `job_not_found`, `job_not_retryable` |

### 6.4 Einstellungen und System

| ID | Operation | Route | Auth | Capability | Scope / Datenumfang | RL | Fehlercodes |
|---|---|---|---|---|---|---|---|
| OP-SET-01 | Einstellungen lesen | `GET /admin/settings/{section}` (`general`, `appearance`, `email`, `privacy`, `spam`, `access`) | WP-REST | `manage_settings` | ohne Secrets | – | `unauthenticated`, `forbidden`, `invalid_nonce`, `not_found` |
| OP-SET-02 | Einstellungen speichern | `PUT /admin/settings/{section}` | WP-REST | `manage_settings`; Section `access` zusätzlich `promote_users` | Schema-validiert je Option (P2-19); Retention-Änderung verlangt `confirm=true` nach Vorschau | – | + `validation_failed`, `conflict` |
| OP-SET-03 | Retention-Vorschau | `GET /admin/settings/privacy/retention-preview?mode&form_id` | WP-REST | `manage_settings` | nur Zahlen/Datum | – | + `invalid_request` |
| OP-SET-04 | Uninstall-Löschung bestätigen/widerrufen | `POST /admin/settings/privacy/uninstall-confirmation` `{confirm_word}` | WP-REST | `manage_settings` | `datenschutz.md` 10 | – | + `validation_failed` |
| OP-SET-05 | Testmail senden | `POST /admin/settings/email/test` | WP-REST | `manage_settings` | Empfänger = aktueller Nutzer oder Admin-E-Mail | RL-06 | + `rate_limited` |
| OP-SET-06 | Signaturschlüssel rotieren | `POST /admin/settings/secret/rotate` | WP-REST | `manage_settings` | `suite-kontext.md` 6 | – | + `invalid_request` |
| OP-SET-07 | Systemstatus | `GET /admin/system-status` | WP-REST | `manage_settings` | keine Feldwerte, IPs, Secrets (P5-22) | – | – |
| OP-SET-08 | Plugin deinstallieren | WordPress „Plugin löschen“ → `uninstall.php` | CORE | `delete_plugins` (Core) | Datenlöschung nur mit Flag + Bestätigung | – | – |

### 6.5 Integrationen und Jobs

| ID | Operation | Route | Auth | Capability | Scope / Datenumfang | RL | Fehlercodes |
|---|---|---|---|---|---|---|---|
| OP-INT-01 | Integrationen auflisten | `GET /admin/integrations` | WP-REST | `manage_integrations` | Key, Version, Vertragsversion, Status, letzte Ausführung, Fehlercode | – | `unauthenticated`, `forbidden`, `invalid_nonce` |
| OP-INT-02 | Aktivieren/Deaktivieren | `POST /admin/integrations/{key}/activate` / `deactivate` | WP-REST | `manage_integrations` | Deaktivieren pausiert offene Jobs (Status bleibt `pending`, `run_after` unverändert; Worker überspringt) | – | + `connector_not_found`, `incompatible_contract` |
| OP-INT-03 | Integrationseinstellungen | `GET/PUT /admin/integrations/{key}/settings` | WP-REST | `manage_integrations` | Secrets write-only, Ausgabe maskiert | – | + `validation_failed` |
| OP-INT-04 | Testlauf | `POST /admin/integrations/{key}/test` | WP-REST | `manage_integrations` | `test_mode = true`, kein Entry | RL-07 | + `connector_disabled`, `rate_limited` |
| OP-INT-05 | Jobs auflisten | `GET /admin/jobs?type&status&form_id` | WP-REST | `manage_integrations` | Typ, Status, Versuche, `last_error_code`, Zeiten; keine Payload-Werte | – | + `invalid_request` |
| OP-INT-06 | Job wiederholen | `POST /admin/jobs/{id}/retry` | WP-REST | `manage_integrations` (Mail-Jobs alternativ OP-ENT-11) | nur `failed`/`dead`; setzt `attempts = 0`, `status = pending` | – | + `job_not_found`, `job_not_retryable` |
| OP-INT-07 | Job abbrechen | `POST /admin/jobs/{id}/cancel` | WP-REST | `manage_integrations` | nur `pending`/`failed`/`dead` | – | + `job_not_found`, `job_not_retryable` |
| OP-INT-08 | Webhook anlegen/ändern (Pro) | `PUT /admin/forms/{id}/actions` (Aktion `webhook`) | WP-REST | `manage_forms` + `manage_integrations` | URL-Validierung T-WH-01/02 beim Speichern | – | + `validation_failed` (Feldcode `webhook_target_forbidden`) |

### 6.6 Contract v1 (PHP)

| ID | Operation | Aufruf | Auth | Capability / Grant | Scope / Datenumfang | RL | Fehlercodes |
|---|---|---|---|---|---|---|---|
| OP-API-01 | Formular lesen | `form_captain_get_form_v1( int\|string $form_id )` (ID oder `public_id`) | PHP | ohne Capability: nur `published`, ohne `hidden`-Defaults; mit `manage_forms`: alle Status | Form-DTO laut `api-v1.md` (`id`, `public_id`, `title`, `status`, `version`, `fields`). **Nie:** Empfänger, Reply-To, Secrets, Aktionskonfiguration | – | `not_ready`, `form_not_found` |
| OP-API-02 | Entry lesen | `form_captain_get_entry_v1( int $entry_id, array $scopes = ['meta'] )` | PHP | Abschnitt 5 (Capability **oder** Job-Grant für diese `entry_id`) | angeforderte Scopes | – | `not_ready`, `not_found`, `forbidden`, `invalid_scope` |
| OP-API-03 | Einsenden | `form_captain_submit_v1( int\|string $form_id, array $payload, array $context = [] )` | PHP | keine (entspricht öffentlicher Einsendung); `$context['source']` (Plugin-Slug) und `$context['idempotency_key']` (≤ 191 Zeichen) Pflicht; optional `signed_context`, `user_id` | Honeypot/Mindestzeit/Token entfallen; Validierung, Blockliste, Kontextprüfung, Speicherung, Jobs wie öffentlich; doppelter `idempotency_key` → `duplicate_request` mit Original-`entry_id` | RL-09 | `not_ready`, `invalid_request`, `form_not_found`, `form_not_accepting`, `validation_failed`, `invalid_context` (mit Detailcode), `context_expired`, `context_replayed`, `spam_suspected`, `payload_too_large`, `rate_limited`, `storage_failed` |
| OP-API-04 | Aktion registrieren | `form_captain_register_action_v1( string $key, array $definition, callable $callback )` | PHP | keine; nur in/nach `form_captain_loaded_v1`; Definition enthält `contract` (z. B. `^1.0`) | Aktion ist nach Registrierung **inaktiv**, bis OP-INT-02 + OP-FRM-10 | – | Rückgabe-Code `not_ready`, `incompatible_contract`, `invalid_request` (Diagnose) |
| OP-API-05 | Connector registrieren | Filter `form_captain_connectors_v1` | PHP | keine | Connector inaktiv bis OP-INT-02 | – | `incompatible_contract` (Diagnose) |
| OP-API-06 | Kontext signieren / Zweck registrieren | `form_captain_sign_context_v1(…)`, `form_captain_register_context_purpose_v1(…)` | PHP | keine (Vertragsregel: nur Eigentümer-Plugin) | `suite-kontext.md` | – | `not_ready`, `context_*`, `incompatible_contract` |
| OP-API-07 | Events konsumieren | `form_captain_*_v1`-Actions | PHP | – | minimaler Payload: IDs, Form-ID, Zeitstempel, Status, `request_id`; keine Feldwerte, keine IP | – | – |
| OP-API-08 | Admin-Menü erweitern | `form_captain_admin_menu_built_v1( $parent_slug )` | PHP | Unterseiten MÜSSEN eigene Form-Captain-Capability verwenden | – | – | – |

### 6.7 Adapter-Callbacks, Privacy, Cron

| ID | Operation | Aufruf | Auth | Capability / Grant | Scope / Datenumfang | RL | Fehlercodes |
|---|---|---|---|---|---|---|---|
| OP-ADP-01 | Aktions-Callback ausführen | Worker ruft `$callback( array $job ): array` (`api-v1.md`) | SYS | Job-Grant für `entry_id` | `$job` = `form_id`, `entry_id`, `idempotency_key`, `settings`, `values` (nur gemappte validierte Felder), `context` (verifiziert), `test_mode` | – | Rückgabe `{status: success\|retry\|fail, code, message, remote_id?}`; Exception → `retry` mit Code `exception` |
| OP-ADP-02 | Connector-Handler (`FormCaptain\Integrations`-Interface) | wie OP-ADP-01 | SYS | wie OP-ADP-01 | wie OP-ADP-01 | – | wie OP-ADP-01 |
| OP-PRV-01 | Privacy Exporter | Core-Tool „Personenbezogene Daten exportieren“ | CORE | `export_others_personal_data` (Core) | `datenschutz.md` 6.3 | – | – |
| OP-PRV-02 | Privacy Eraser | Core-Tool „Personenbezogene Daten löschen“ | CORE | `erase_others_personal_data` (Core) | `datenschutz.md` 6.4 | – | – |
| OP-CRN-01 | Queue abarbeiten | Cron-Event `form_captain_process_jobs` (minütlich) + Anstoß nach Submission (`spawn_cron` bzw. nicht-blockierender Loopback) | SYS | – | Claim per `lock_token`/`locked_until_gmt`, Batch 20 Jobs/20 s, Backoff laut `zustaende.md`, max. 8 Versuche → `dead` | – | Job-Codes in `fc_jobs.last_error_code` |
| OP-CRN-02 | Tägliche Bereinigung | Cron-Event `form_captain_daily_cleanup` | SYS | – | Retention, Papierkorb, Spam, alte Jobs/Logs, Secret-Rotationsende | – | – |
| OP-CRN-03 | Rate-Limit-Bereinigung | Cron-Event `form_captain_hourly_cleanup` | SYS | – | `fc_rate_limits` nach `expires_at`; Salt-Rotation 00:00 UTC | – | – |

## 7. Prüfreihenfolge in Routen

1. HTTP-Methode (Router) → 405
2. Content-Type / `Content-Length` → `unsupported_media_type`, `payload_too_large`
3. Auth: Admin-Routen Login → `unauthenticated`; Nonce → `invalid_nonce`; öffentliche Routen Rate-Limit → `rate_limited`
4. Capability (vor Objektzugriff, kein Existenz-Orakel) → `forbidden`
5. Objekt laden + Zugehörigkeit → `not_found` / `form_not_found`
6. Status-/Übergangsprüfung → `form_not_accepting`, `conflict`, `revision_conflict`
7. Token → `invalid_token`; Kontext → `invalid_context` / `context_expired` / `context_replayed`
8. Validierung → `validation_failed` / `schema_invalid`; Spam → `spam_suspected`
9. Ausführung; Events/Jobs erst nach Commit

## 8. Offene Punkte

1. Application Passwords für Admin-Routen (Automatisierung durch Agenturen) sind in v1 ausgeschlossen – Produktentscheidung bestätigen.
2. Objektbezogene Rechte (Entries nur bestimmter Formulare je Rolle) sind nicht in v1; konzept 4.1 „Editor erhält keine sensiblen Einträge ohne Zuweisung“ wird über Rollen-Capabilities erfüllt. Bestätigen.
3. `spam_suspected` öffentlich standardmäßig als Erfolg maskiert – bestätigen.
