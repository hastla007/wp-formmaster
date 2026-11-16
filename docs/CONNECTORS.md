# Connector-Entwicklerleitfaden (Contract v1)

Dieser Leitfaden beschreibt, wie ein eigenständiges WordPress-Plugin („Connector“) Form-Captain-Einsendungen an ein anderes System übergibt. Verbindliche Grundlage: [`docs/spec/api-v1.md`](spec/api-v1.md), [`docs/spec/rechtevertrag.md`](spec/rechtevertrag.md), ADR [0006](adr/0006-connectoren.md). Ein lauffähiges Beispiel liegt in [`connectors/form-captain-reference-connector/`](../connectors/form-captain-reference-connector/).

## 1. Voraussetzungen

- WordPress ≥ 6.8, PHP ≥ 8.3, Form Captain mit `form_captain_contract_version()` Major `1`.
- Plugin-Header `Requires Plugins: form-captain` (empfohlen). Ohne Form Captain bleibt der Connector still (keine Fatal Errors, keine Admin-Fehler).
- Nur öffentliche API verwenden: Funktionen `form_captain_*_v1`, Filter/Actions mit Suffix `_v1`, Interface `FormCaptain\Integrations\ConnectorInterface`. Klassen, Tabellen und Optionen von Form Captain sind **intern** und können sich ohne Ankündigung ändern.

## 2. Registrierung

```php
add_action( 'form_captain_loaded_v1', static function ( string $contract ): void {
	if ( 1 !== (int) $contract ) { return; }            // Major prüfen
	require_once __DIR__ . '/includes/class-my-connector.php';
	add_filter( 'form_captain_connectors_v1', static fn( array $c ) => $c + [ 'my' => new My_Connector() ] );
} );
```

- Registrierung **nur in/nach** `form_captain_loaded_v1` (`plugins_loaded`, Prio 20). Der Kern sammelt Connectoren an `init` (Prio 5) ein.
- `$c + […]` statt `array_merge`: vorhandene Keys dürfen nicht überschrieben werden.
- Der Kern prüft: Key `^[a-z0-9_]+$` und identisch mit `key()`, Interface implementiert, `contract()` kompatibel (gleiche Major, Kern ≥ verlangte Minor), `requirements()['ok']`.
- Zustände in *Form Captain → Integrationen*: **Erkannt** (neu, deaktiviert), **Aktiv**, **Deaktiviert**, **Inkompatibel** (`incompatible_contract`), **Plugin fehlt** (Opt-in vorhanden, Plugin entfernt/inaktiv).
- Jeder Connector ist nach Installation **deaktiviert** (Opt-in, P8-26). Nur aktive Connectoren führen Aktionen aus; Aktionen werden trotzdem registriert, damit der Editor sie mit Grund „nicht verfügbar“ anzeigen kann.

### Interface

```php
interface ConnectorInterface {
	public function key(): string;            // stabil
	public function label(): string;
	public function version(): string;        // Plugin-Version
	public function contract(): string;       // z. B. '1.0'
	public function capabilities(): array;    // Aktions-Keys
	public function requirements(): array;    // ['ok' => bool, 'messages' => string[]]
	public function settings_schema(): array; // globale Connector-Einstellungen (Doku/Anzeige)
	public function register_actions(): void; // ruft form_captain_register_action_v1()
}
```

## 3. Formularaktionen

```php
form_captain_register_action_v1( 'my.create_lead', [
	'label'     => __( 'Create lead', 'my' ),
	'version'   => '1.0.0',
	'contract'  => '^1.0',
	'connector' => 'my',
	'settings'  => [ [ 'key' => 'pipeline', 'type' => 'text', 'label' => 'Pipeline', 'required' => true ] ],
	'mapping'   => [
		[ 'key' => 'email', 'label' => 'E-Mail', 'types' => [ 'email' ], 'required' => true ],
		[ 'key' => 'phone', 'label' => 'Telefon', 'types' => [ 'tel' ] ],
		[ 'key' => 'iban',  'label' => 'IBAN', 'types' => [ 'text' ], 'allow_sensitive' => true ], // nur wenn fachlich nötig
	],
	'events'    => [ 'entry_created' ],
], [ $this, 'create_lead' ] );
```

Fehlercodes der Registrierung (`WP_Error`): `not_ready` (vor `form_captain_loaded_v1`), `invalid_request` (Key-Format, Duplikat, fehlendes `label`/`contract`, ungültige Setting-/Mapping-Keys), `incompatible_contract`.

Setting-Typen: `text`, `textarea`, `email`, `url`, `number`, `select` (`options`), `checkbox`. Nicht deklarierte Settings werden verworfen.

Der Administrator aktiviert die Aktion je Formular im Editor (`settings.actions[]` mit `instance_id`, `action_key`, `enabled`, `settings`, `mapping`), Rechte `manage_forms` + `manage_integrations`.

## 4. Ausführung, Payload und Scopes

Nach dem Commit einer Einsendung (kein Spam) legt der Kern je aktivierter Aktion einen Outbox-Job `connector.action` an. Idempotency-Key: `form-captain:{site_id}:{entry_id}:{action_key}:{instance_id}` (UNIQUE). Der Callback läuft **nur im Worker** (WP-Cron oder `wp form-captain jobs run`), nie im Submission-Request.

```php
$job = [
	'form_id' => 7, 'entry_id' => 123,
	'idempotency_key' => 'form-captain:1:123:my.create_lead:a_0123456789',
	'settings'  => [ 'pipeline' => 'b2b' ],      // nur deklarierte Settings, bereinigt
	'values'    => [ 'email' => 'a@example.org' ], // nur gemappte, validierte Felder
	'context'   => [ 'purpose' => …, 'ref_type' => …, 'ref_id' => …, 'verified' => bool ] | [],
	'test_mode' => false,
	// zusätzlich (Minor-additiv): 'action_key', 'instance_id', 'attempt'
];
```

- **Unbekannte Schlüssel ignorieren** – neue optionale Schlüssel kommen in Minor-Versionen hinzu.
- `values` enthält ausschließlich die im Mapping zugeordneten Felder der Formularversion des Entries. Nie enthalten: nicht gemappte Felder, IP, User-Agent, interne Notizen, vollständige Objekte.
- Sensible Felder (`sensitive: true`) werden nur übergeben, wenn der Mapping-Eintrag `allow_sensitive => true` deklariert. Feldtypen `file`, `password`, `payment`, `signature`, `html` werden **nie** übergeben (`sensitive_field`).
- Während des Callbacks gilt ein Job-Grant: `form_captain_get_entry_v1( $job['entry_id'], ['meta','values'] )` liefert nur die gemappten Felder dieses Entries; andere Entries → `forbidden`.

### Rückgabe

```php
return [ 'status' => 'success'|'retry'|'fail', 'code' => 'created', 'message' => '…', 'remote_id' => 'L-42' ];
```

| Rückgabe | Wirkung |
|---|---|
| `success` | Job `succeeded`, `remote_id` (≤ 191 Zeichen) wird gespeichert und in Integrationen angezeigt |
| `retry` | temporärer Fehler → `failed`, Backoff `min(2^n·60 s, 6 h)` ±10 %, max. 8 Versuche, danach `dead` |
| `fail` | endgültiger Fehler → sofort `dead` (Dead-letter) |
| Exception | wie `retry` mit Code `exception` (Meldung wird redigiert) |
| sonstiges | `retry` mit Code `invalid_result` |

`code`: `[a-z0-9_.-]`, ≤ 60 Zeichen; `message` ≤ 255 Zeichen, **ohne personenbezogene Daten** (wird trotzdem redigiert).

### Idempotenz (Pflicht)

Derselbe Job kann mehrfach zugestellt werden (Timeout nach Erfolg, Admin-Retry, Worker-Absturz). Der Connector muss den `idempotency_key` an das Zielsystem weitergeben bzw. lokal merken und bei Wiederholung **dasselbe** Ergebnis (gleiche `remote_id`) liefern – nie ein zweites Objekt.

## 5. Fehlercodes und Diagnose

Vom Kern gesetzte Codes (Integrationen zeigt je Code den nächsten Schritt):

| Code | Bedeutung | Ergebnis |
|---|---|---|
| `connector_disabled` | Connector deaktiviert | retry bis `dead`; bei Reaktivierung werden diese Dead-Jobs automatisch neu eingeplant |
| `connector_not_found` / `action_not_found` | Plugin fehlt/inaktiv | retry bis `dead` (keine verlorenen Jobs) |
| `requirements_not_met` | Konfiguration unvollständig | retry |
| `incompatible_contract` | Major passt nicht | fail |
| `entitlement_missing` | Pro-Recht fehlt/abgelaufen | fail |
| `entry_deleted`, `entry_anonymized`, `entry_spam` | Entry nicht mehr zustellbar | fail, Callback wird nicht aufgerufen |
| `action_removed`, `action_disabled` | Aktion im Formular entfernt/aus | fail |
| `mapping_incomplete`, `mapping_type_mismatch`, `sensitive_field` | Mapping ungültig | fail (nach Korrektur manuell wiederholen) |
| `exception`, `invalid_result`, `lock_expired` | technischer Fehler | retry |

Eigene Codes des Connectors (z. B. `remote_unavailable`, `remote_rejected`, `timeout`) erscheinen unverändert in der Job-Liste.

## 6. Retry und Dead-letter

- Standard 8 Versuche (Budget `job_max_attempts`), danach `dead`. Dead-Jobs bleiben 90 Tage sichtbar.
- Manuell: *Integrationen → Integrations-Jobs → Wiederholen/Abbrechen* (`manage_integrations` + Nonce) oder `wp form-captain jobs retry <id>`.
- Externe Fehler ändern nie den gespeicherten Entry, die Spamprüfung oder die native Admin-Mail.

## 7. Testlauf und Testmodus

*Integrationen → Test ausführen* ruft den Callback mit `test_mode = true`, `entry_id = 0`, Beispielwerten je Mapping-Typ und ohne Job auf (5 Läufe/10 min je Nutzer und Aktion). Im Testmodus **dürfen keine realen Nebenwirkungen** entstehen (Vertragspflicht) – nur Erreichbarkeit/Konfiguration prüfen.

## 8. Löschung, Anonymisierung, Consent

- Form Captain ist Eigentümer von Entry und Aufbewahrung. Beim Löschen feuert `form_captain_entry_deleted_v1( $entry_id, $form_id, $reason )` mit `manual`, `retention`, `erasure`, `anonymized` oder `form_deleted`. Offene Jobs des Entries werden `cancelled`.
- Der Connector entscheidet nach seinem Vertrag mit dem Zielsystem, ob er Remote-Daten löscht. **Keine ungefragte Remote-Löschung**; das Referenzbeispiel entfernt nur lokale Personendaten.
- Consent-Widerruf: v1 hat keinen eigenen Ablauf; Admins löschen/anonymisieren den Entry (→ Event oben). Code `consent_withdrawn` ist für künftige Versionen reserviert.

## 9. Secrets

- Zugangsdaten verwaltet der Connector selbst (eigene Option mit `autoload = no` oder Konstante in `wp-config.php`), nie im Formular-Export, nie in Logs, im Admin nur maskiert („••••1234“), write-only.
- Rotation: neues Secret parallel zum alten akzeptieren (Übergangsfenster), danach altes löschen. Bei Deinstallation eigene Secrets entfernen.
- Form-Captain-Signaturschlüssel: *Einstellungen → Systemstatus → Signaturschlüssel erneuern* (altes Secret 26 h gültig, optional sofort verwerfen).

## 10. Kompatibilität

- SemVer des Vertrags: Minor = additiv (neue optionale Schlüssel/Funktionen), Major = neue Namensfamilie `_v2`; `_v1` bleibt ≥ 12 Monate parallel.
- `contract()` des Connectors und `contract` der Aktion als Constraint (`1.0`, `^1.0`). Eine höhere verlangte Minor als der Kern bietet → `incompatible_contract`.
- Deprecations werden mit `_deprecated_hook()` angekündigt.

## 11. Tests

Das Contract-Testkit liegt in `tests/integration/Contract/` (`ConnectorContractAssertions`, Fixtures `tests/fixtures/contract/v1/*.json`):

- `assertConnectorShape()`, `assertJobArrayShape()`, `assertActionResultShape()`, `assertEntryEventShape()` (unbekannte Schlüssel erlaubt), `assertOnlyMappedValues()`, `assertIdempotentCallback()`.
- Helfer `make_entry()`, `enqueue_action_job()`, `action_instance()`.
- Zwei-Plugin-Beispiel: `ReferenceConnectorTest` (Opt-in, Timeout nach Erfolg, Ausfall, Dead-letter, Deaktivierung, Entry-Löschung, Testmodus, inkompatible Version).

## 12. Release-Checkliste

- [ ] Key stabil, Contract-Constraint gesetzt, Major-Check beim Laden.
- [ ] Registrierung nur in/nach `form_captain_loaded_v1`; ohne Form Captain kein Fehler.
- [ ] Callback idempotent (Wiederholung → gleiche `remote_id`), Timeouts ≤ 10 s (max. 30 s).
- [ ] `retry` nur für temporäre, `fail` für endgültige Fehler; Codes dokumentiert.
- [ ] Testmodus ohne Nebenwirkungen.
- [ ] Nur benötigte Felder im Mapping; `allow_sensitive` nur begründet.
- [ ] Keine Personendaten/Secrets in Meldungen, Logs, Optionen mit autoload.
- [ ] Löschverhalten (Event `form_captain_entry_deleted_v1`) dokumentiert.
- [ ] Contract-Testkit grün gegen aktuelle und Mindestversion von Form Captain.
- [ ] Datenfluss (Ziel, Felder, Zweck) in der Plugin-Beschreibung und für die Datenschutzerklärung dokumentiert.
