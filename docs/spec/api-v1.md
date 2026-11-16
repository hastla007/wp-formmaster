# Öffentliche API v1 (P0-09, P8-01, P8-24)

Nur das in diesem Dokument Aufgeführte ist öffentliche API. Klassen unter `FormCaptain\` außerhalb von `FormCaptain\Integrations\` (Interfaces) und `FormCaptain\Api\` sowie Tabellen sind **intern**.

## Version

```php
FORM_CAPTAIN_CONTRACT_VERSION // '1.1.0' (seit 16.11.2026; 1.0.0 → 1.1.0 additiv, siehe „Erweiterungspunkte für Pro-Funktionen“)
form_captain_contract_version(): string
```

SemVer: Minor = additive Erweiterungen (neue optionale Payload-Schlüssel, neue Funktionen); Major = inkompatibel, neue Namensfamilie `_v2`, `_v1` bleibt mindestens 12 Monate parallel bestehen. Deprecations werden mit `_deprecated_hook()` angekündigt und im Changelog geführt.

## Lebenszyklus

| Hook | Wann | Argumente |
|---|---|---|
| `form_captain_loaded_v1` (action) | `plugins_loaded` Prio 20, nachdem Kern bereit | `string $contract_version` |
| `form_captain_admin_menu_built_v1` (action) | nach Registrierung des Menüs | `string $parent_slug` |

Adapter registrieren sich ausschließlich in oder nach `form_captain_loaded_v1`.

## Ereignisse (alle nach Commit)

| Action | Argumente |
|---|---|
| `form_captain_form_created_v1` | `array $form` (Form-DTO) |
| `form_captain_form_updated_v1` | `array $form`, `string $change` (`saved`, `published`, `paused`, `unpublished`, `archived`) |
| `form_captain_entry_created_v1` | `array $event` (Entry-Event, s. u.) |
| `form_captain_entry_status_changed_v1` | `int $entry_id`, `string $old`, `string $new`, `int $form_id` |
| `form_captain_entry_deleted_v1` | `int $entry_id`, `int $form_id`, `string $reason` (`manual`, `retention`, `erasure`, `anonymized`, `form_deleted`, `consent_withdrawn`, `import_reverted`) |
| `form_captain_entries_imported_v1` | `string $import_id`, `int $form_id`, `int $count` – nach jedem Import-Schritt mit neu angelegten Entries (CSV-Eintragsimport, `docs/planung/import.md` 7); für importierte Entries gibt es kein `entry_created_v1` |
| `form_captain_consent_withdrawn_v1` | `int $entry_id`, `int $form_id`, `string $source` (`admin`, `api`, `cli`, `privacy_request`, `system`), `array $event` (4. Argument, optional per `accepted_args`; s. u.) |
| `form_captain_notification_failed_v1` | `int $entry_id`, `int $form_id`, `string $job_type`, `string $error_code` |

`form_captain_entry_created_v1` wird **nicht** für Spam gefeuert.

### Entry-Event-Payload

```php
[
  'contract'   => '1.0.0',
  'entry_id'   => 123,
  'form_id'    => 7,
  'form_public_id' => 'a1b2c3d4e5f6g7h8',
  'form_version' => 3,
  'status'     => 'new',
  'created_gmt'=> '2026-11-16T10:00:00Z',
  'request_id' => '9f…',            // Korrelations-ID
  'context'    => ['ref_type' => 'event', 'ref_id' => '42'] | null,
  'values'     => [ 'email' => '…' ], // NUR Felder aus der Allowlist (Filter form_captain_event_value_allowlist_v1, Standard: leer)
]
```

Nie enthalten: IP, User-Agent, vollständige WP-/Woo-Objekte, sensible Felder.

### Consent-Widerruf-Payload (`form_captain_consent_withdrawn_v1`, seit Kern-Schema v2)

Gefeuert **einmal** je Entry, nachdem `consent_withdrawn_gmt` gespeichert und offene `mail.*`-/`connector.*`-Jobs storniert wurden, und **vor** einer optionalen Anonymisierung/Löschung (die ihrerseits `entry_deleted_v1` feuert). Fixture: `tests/fixtures/contract/v1/consent-withdrawn.json`.

```php
[
  'contract'       => '1.0.0',
  'entry_id'       => 123,
  'form_id'        => 7,
  'form_public_id' => 'a1b2c3d4e5f6g7h8',
  'withdrawn_gmt'  => '2026-11-16T10:00:00Z',
  'source'         => 'admin',
  'then'           => 'keep' | 'anonymize' | 'delete', // geplanter Folgeschritt
]
```

Nie enthalten: Feldwerte, Kontext, Consent-Text, IP, User-Agent. Adapter dürfen Daten im Fremdsystem nur löschen, wenn das vertraglich konfiguriert ist; Standard ist Kennzeichnen. Nach dem Widerruf erhalten Aktionen für diesen Entry keine Jobs mehr (`fail` mit Code `consent_withdrawn`).

## Funktionen

```php
form_captain_get_form_v1( int|string $form_id ): array|WP_Error
form_captain_get_entry_v1( int $entry_id, array $scopes = ['meta'] ): array|WP_Error
form_captain_submit_v1( int|string $form_id, array $payload, array $context = [] ): array
form_captain_register_action_v1( string $key, array $definition, callable $callback ): true|WP_Error
form_captain_get_render_v1( int|string $form_id, array $args = [] ): string
form_captain_sign_context_v1( string $purpose, int $form_id, string $ref_type, string $ref_id, int $ttl = 0 ): array
form_captain_register_context_purpose_v1( string $purpose, array $definition ): true|WP_Error
form_captain_validate_v1( int|string $form_id, array $values, array $only = [] ): array|WP_Error // seit 1.1.0: prüft gegen die veröffentlichte Version, speichert nichts; nur Fehler der Felder in $only (leer = alle); Rückgabe {valid, errors, codes}
```

Signierter Suite-Kontext: siehe [`suite-kontext.md`](suite-kontext.md).

- `get_form_v1`: Form-DTO `{id, public_id, title, status, version, fields:[{id,key,type,label,required,sensitive,options}]}`; erfordert `form_captain_manage_forms` **oder** `published`-Status (dann ohne `hidden`-Defaults).
- `get_entry_v1`: Scopes `meta` (Status, Zeiten, Form-ID, `anonymized`/`anonymized_gmt`, `consent_withdrawn`/`consent_withdrawn_gmt` – additiv seit Schema v2), `values` (alle nicht sensiblen Werte), `sensitive` (auch sensible). Erfordert `form_captain_read_entries`; `sensitive` zusätzlich `form_captain_export_entries`. Im Cron-/Job-Kontext prüft der Kern die Rechte über die vom Admin freigegebene Aktionskonfiguration.
- `submit_v1`: programmatische Einsendung durch vertrauenswürdigen Servercode; durchläuft dieselbe Validierung, aber kein Honeypot/Mindestzeit. `$context` kann `['signed_context' => '…']` enthalten. Rückgabe: Ergebnisobjekt.
- `get_render_v1`: HTML für Einbettung (z. B. Pass Captain).

### Ergebnisobjekt

Zusätzlich: `detail_code` (nur bei vertrauenswürdigen PHP-Aufrufen, z. B. genauer Kontext-Fehlercode) und in REST-Antworten `error_codes` (field_id => Fehlercode, maschinenlesbar neben den übersetzten `errors`).

```php
['success' => bool, 'code' => 'created'|'validation_failed'|…, 'form_id' => 7, 'entry_id' => 123|null, 'request_id' => '…', 'errors' => ['f_x' => 'message']]
```

### Formular-Einstellungen für Suite-Kontext und Ausgabe

| Schlüssel | Bedeutung |
|---|---|
| `accepted_context_purposes` | Liste der Kontext-Zwecke, die dieses Formular akzeptiert (leer = keiner) |
| `context_required` | bool – Einsendung ohne gültigen signierten Kontext wird abgelehnt (`invalid_context`) |
| `submit_label` | Beschriftung des Absende-Buttons |

## HTTP-Statuscodes der öffentlichen Submission

201 neuer Entry, 200 wiederholter Request (gleiches Ergebnis, keine neuen Jobs), 400 Validierungs-/Token-/Kontextfehler, 403, 404, 405 falsche Methode, 409 Formular nimmt nicht an, 413 zu groß, 415 falscher Content-Type, 429 Rate-Limit, 503 Speicherfehler. Zu schnelle Einsendungen (Mindestzeit) werden als Spam (`too_fast`) gespeichert und dem Besucher neutral bestätigt.

## Fehlercodes (stabil)

`forbidden`, `invalid_nonce`, `invalid_token`, `form_not_found`, `form_not_accepting`, `validation_failed`, `rate_limited`, `spam_suspected`, `payload_too_large`, `too_many_fields`, `storage_failed`, `schema_invalid`, `schema_unsupported`, `revision_conflict`, `incompatible_contract`, `connector_disabled`, `connector_not_found`, `invalid_context`, `context_expired`, `context_replayed`, `job_not_found`, `entry_not_found`, `not_ready`, `context_purpose_unknown`, `invalid_scope`, `invalid_request`, `conflict`, `job_not_retryable`, `method_not_allowed`, `export_too_large`, `internal_error`.

## Formularaktionen und Connectoren

### `form_captain_register_action_v1( $key, $definition, $callback )`

`$key`: `^[a-z0-9_]+\.[a-z0-9_]+$` (z. B. `reference.create_ticket`). `$definition`:

```php
[
  'label'        => 'Ticket anlegen',
  'version'      => '1.0.0',
  'contract'     => '^1.0',           // erwartete Vertrags-Major
  'connector'    => 'reference',      // Connector-Key oder null
  'settings'     => [ ['key' => 'queue', 'type' => 'text', 'label' => 'Queue', 'required' => true] ],
  'mapping'      => [ ['key' => 'email', 'label' => 'E-Mail', 'types' => ['email'], 'required' => true] ],
  'events'       => ['entry_created'],
  'requires'     => [],               // z. B. ['entitlement' => 'form.workflows'] nur für Pro
]
```

Callback-Signatur: `function( array $job ): array` mit

```php
$job = ['form_id', 'entry_id', 'idempotency_key', 'action_key', 'instance_id', 'attempt', 'settings' => [...], 'values' => [mapping_key => value], 'context' => [...], 'test_mode' => bool]
```

Rückgabe: `['status' => 'success'|'retry'|'fail', 'code' => '…', 'message' => '…', 'remote_id' => '…']`. Exceptions werden als `retry` mit Code `exception` gewertet.

Idempotency-Key: `form-captain:{site_id}:{entry_id}:{action_key}:{action_instance_id}`.

### Connector-Registry `form_captain_connectors_v1` (Filter)

```php
add_filter( 'form_captain_connectors_v1', fn( array $c ) => $c + [ 'reference' => new MyConnector() ] );
```

Interface `FormCaptain\Integrations\ConnectorInterface`:

```php
public function key(): string;               // stabil, ^[a-z0-9_]+$
public function label(): string;
public function version(): string;           // Plugin-Version
public function contract(): string;          // unterstützte Vertragsversion, z. B. '1.0'
public function capabilities(): array;       // Liste Aktions-Keys
public function requirements(): array;       // ['ok' => bool, 'messages' => [...]]
public function settings_schema(): array;    // globale Connector-Einstellungen
public function register_actions(): void;    // ruft form_captain_register_action_v1
```

Connectoren sind nach Installation **deaktiviert**; nur aktivierte Connectoren dürfen Aktionen ausführen. Inkompatible Major-Version → Zustand `incompatible`, Code `incompatible_contract`.

## Weitere Filter

| Filter | Zweck |
|---|---|
| `form_captain_field_types_v1` | zusätzliche Feldtypen (Pro) |
| `form_captain_condition_evaluators_v1` | zusätzliche Bedingungslogik (Pro) |
| `form_captain_event_value_allowlist_v1` | Feld-Keys, die in Entry-Events landen dürfen |
| `form_captain_delete_entry_files_v1` | Pro löscht Dateien zu Entry |
| `form_captain_entry_detail_sections_v1` | zusätzliche Abschnitte in Entry-Detail |
| `form_captain_csv_columns_v1` | zusätzliche CSV-Spalten (nur Metadaten) |
| `form_captain_spam_checks_v1` | zusätzliche Spamprüfungen (z. B. Captcha-Provider, opt-in) |
| `form_captain_signed_context_purposes_v1` | registrierte Kontext-Zwecke |
| `form_captain_accepted_context_purposes_v1` | (seit Contract 1.1.0) Kontext-Zwecke, die ein Formular annimmt: `( string[] $purposes, int $form_id )`. Für das Add-on, dem ein Zweck gehört und das das Formular zuordnet (z. B. Käuferfragebogen an einem Produkt); Signatur, TTL und Prüfungen bleiben unverändert. |
| `form_captain_pro_active` | Pro meldet sich (nur für sparsame Hinweise im Free) |

## Erweiterungspunkte für Pro-Funktionen (seit Contract 1.1.0)

Für Uploads, Mehrschritt-Formulare und erweiterte Bedingungen/Routing (P7-07, P7-08, P7-10). Alle Punkte sind additiv. Ohne Erweiterung verhält sich der Kern wie in 1.0.0. Getestet in `tests/integration/Contract/ExtensionPointsTest.php`.

| Hook | Art | Argumente → Rückgabe | Regeln des Kerns |
|---|---|---|---|
| `form_captain_render_field_v1` | Filter | `null`, `array $field`, `array $ctx` (`id`, `name`, `value`, `required`, `invalid`, `aria-describedby`) → HTML des Bedienelements | Nur für Feldtypen aus `form_captain_field_types_v1`. Wrapper, Label, Hilfe- und Fehlerelement samt IDs liefert der Kern. Ohne Rückgabe entfällt das Feld. Die Erweiterung escaped ihr HTML selbst. |
| `form_captain_validate_field_v1` | Filter | `null`, `array $field`, `string\|array\|null $value`, `array $ctx` (`file`: hochgeladene Datei im `$_FILES`-Format oder `null`, `form`) → `array{value:string\|string[]\|null}` oder `array{code:string}` | Nur für Erweiterungs-Feldtypen. Ohne Validator: Pflichtfeld → `required`, sonst kein Wert. Werte höchstens 5000 Zeichen. Fehlercodes `^[a-z][a-z0-9_]{1,40}$`. |
| `form_captain_field_error_message_v1` | Filter | `''`, `string $code`, `array $field` → Text | Für eigene Fehlercodes. HTML wird entfernt. |
| `form_captain_normalize_field_v1` | Filter | `null`, `array $raw_field`, `array $ids` (Feld-ID → Typ, bis einschließlich dieses Felds) → `array{settings?:array, errors?:array<string,string>}` | Gespeichert unter `field.x` (höchstens 8 KB JSON). Fehler erscheinen als `fields.N.x.<key>`. Der Kern wertet `x` nie aus. |
| `form_captain_normalize_form_settings_v1` | Filter | `null`, `array $x`, `array $schema` → wie oben | Gespeichert unter `settings.x` (höchstens 16 KB). Ohne Erweiterung bleibt ein gespeichertes `x` unverändert erhalten; Lizenzende löscht nichts. |
| `form_captain_form_attributes_v1` | Filter | `array()`, `array $form` → Attribute | Angenommen werden nur `data-fc-x-*`, zusätzliche Klassen und `enctype="multipart/form-data"`. Kern-Attribute sind nicht überschreibbar. |
| `form_captain_field_attributes_v1` | Filter | `array()`, `array $field` → Attribute | Nur `data-fc-x-*` und zusätzliche Klassen (z. B. Schrittnummer, Bedingungsgruppe für eigenes JavaScript). |

**Ereignis im Browser:** Nach erfolgreicher Einsendung über das Frontend-Skript löst das Formular-Element das DOM-Ereignis `fc:submitted` aus (`bubbles: true`, ohne Daten).

**Gruppierte Bedingungen im Browser:** Trägt ein Feld das Attribut `data-fc-x-condition` mit JSON `{ "action": "show"|"hide", "match": "all"|"any", "rules": [ { "field", "operator", "value" } ] }`, wertet das Frontend-Skript des Kerns diese Gruppe statt `data-fc-condition` aus. Es gelten dieselben Operatoren und dieselbe Regel für ausgeblendete Bezugsfelder. Maßgeblich bleibt die Serverprüfung der Erweiterung über `form_captain_condition_evaluators_v1`.
| `form_captain_notification_recipients_v1` | Filter | `string\|array $to`, `array $form`, `int $entry_id`, `array $values` → Empfängerliste | Routing. Das Ergebnis durchläuft dieselbe Prüfung wie konfigurierte Adressen (Header-Injection, `is_email`). |
| `form_captain_confirmation_v1` | Filter | `array $confirmation` (Form wie `settings.confirmation`), `array $form`, `int $entry_id`, `array $values` (geprüfte Werte, Feld-ID → Wert; bei Doppelversand die gespeicherten) → Bestätigung | Bedingte Bestätigung. Eine Seite muss veröffentlicht und intern sein, sonst bleibt es bei der Meldung. |
| `form_captain_display_value_v1` | Filter | `string $display`, `array $field`, `string\|array $value` → Klartext | Nur Erweiterungs-Feldtypen. Gilt für Admin-Detail, CSV und Mail. HTML wird entfernt, die Aufrufer escapen. |

**Einstellungen im Formular-Editor:** Filter `form_captain_editor_extensions_v1` (`array $panels` → Panels).

- **Panel:** `key` (`^[a-z][a-z0-9_]{1,40}$`, eindeutig), `scope` (`field` oder `form`), `label`, `description`, `types` (Feldtypen, für die das Panel erscheint; leer = alle), `controls`, `messages` (Fehlercode → Text; Meldungen des Kerns haben Vorrang).
- **Control:** `key`, `type` (`text`, `textarea`, `number`, `checkbox`, `select` mit `options`, `rules`, `json`), `label`, `help`.
- **Speicherort:** Der Editor speichert den Wert unter `field.x[<control key>]` bzw. `settings.x[<control key>]`. Prüfung und Fehler (`x.<key>…`) kommen von `form_captain_normalize_field_v1` / `form_captain_normalize_form_settings_v1`.
- **`rules`:** eine Gruppe `{ action, match, rules: [ { field, operator, value } ] }` auf frühere Felder; im Formular-Scope ohne `action`.
- **`json`:** für Strukturen ohne eigenes Bedienelement.
- **Bereinigung:** Unbekannte Typen, doppelte Schlüssel und Panels ohne Controls verwirft der Kern; Texte werden von HTML befreit. Test: `ExtensionPointsTest::test_editor_extension_panels_are_cleaned`.

**Dateien:** Formulareingaben heißen `fc[<field_id>]`. REST-Einsendung (`multipart/form-data`) und admin-post geben `$_FILES` über `FormCaptain\Support\UploadedFiles::by_field()` als `field_id → Datei` an den Validator weiter (höchstens 20 Dateien, eine je Feld). Mit `enctype="multipart/form-data"` (über `form_captain_form_attributes_v1`) sendet das Frontend-Skript des Kerns `FormData` statt JSON. Dateigrößen zählen nicht gegen `request_bytes`; dieses Limit gilt dann nur für die übrigen Felder. Der Kern verschiebt, speichert oder öffnet hochgeladene Dateien nie. Ablage, Prüfung, Quarantäne und Löschung (`form_captain_delete_entry_files_v1`) liegen bei der Erweiterung.
