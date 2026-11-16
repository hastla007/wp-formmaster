# Signierter Suite-Kontext `_fc_ctx` (P0-15)

**Status:** Entwurf zur Freigabe · **Stand:** 16.11.2026 · **Gilt für:** Form Captain Free v1.0 (Validierung im Kern), Suite-Adapter und Connectoren (Erzeugung)
**Bezug:** konzept.md 5 (Integrationsausbau Stufe 1, WooCommerce Pro, Pilot-Connectoren) · TODO P0-15, P8-13, P8-14, P8-55, P8-64
**Verwandte Specs:** `bedrohungsmodell.md` (T-CTX-*), `rechtevertrag.md`

Zweck: Ein Suite-Plugin (z. B. Pass Captain, WooCommerce-Pro-Modul, LearnBoating-Connector) kann einem Formular einen serverseitig geprüften Bezug (Event, Bestellposition, Kurs) mitgeben. Der Browser transportiert den Wert nur; er kann ihn weder erzeugen noch ändern. **Vom Nutzer editierbare Hidden Fields, URL-Parameter oder Cookies gelten nie als Vertrauensnachweis.**

---

## 1. Format

```
_fc_ctx = B64U(payload_json) "." B64U(HMAC-SHA256(K_ctx, B64U(payload_json)))
```

- `B64U` = Base64url nach RFC 4648 §5 **ohne** Padding (`=`), Alphabet `A–Z a–z 0–9 - _`.
- Die Signatur wird über die **ASCII-Bytes des kodierten Payload-Segments** berechnet, nicht über erneut serialisiertes JSON. Damit ist keine JSON-Kanonisierung beim Prüfen nötig.
- Signatursegment: genau 43 Zeichen (32 Byte).
- Gesamtlänge ≤ 1024 Zeichen.
- Transport: verstecktes Formularfeld mit Name `_fc_ctx` (REST-JSON: Eigenschaft `_fc_ctx` auf oberster Ebene). Genau ein Wert; mehrfach übergeben → `context_malformed`.

### 1.1 Payload

JSON-Objekt mit **genau** diesen sieben Schlüsseln in dieser Reihenfolge (Erzeuger MUSS diese Reihenfolge verwenden; Prüfer verlangt die Schlüsselmenge, nicht die Reihenfolge). Kodierung mit `wp_json_encode( $p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )`.

| Schlüssel | Typ | Format / Regel | Beispiel |
|---|---|---|---|
| `purpose` | string | `^[a-z][a-z0-9_]{0,39}\.[a-z][a-z0-9_]{0,39}$` (`{owner}.{zweck}`), registriert (Abschnitt 3) | `pass.event_registration` |
| `form_id` | integer | interne numerische Formular-ID (`fc_forms.id`), > 0 | `42` |
| `ref_type` | string | `^[a-z][a-z0-9_]{0,31}$`, muss im Zweck erlaubt sein | `event` |
| `ref_id` | string | `^[A-Za-z0-9_.:-]{1,64}$` (immer String, auch bei numerischen IDs) | `"1187"`, `"order:5531:item:88"` |
| `site` | string | 32 Hex-Zeichen: `substr( hash('sha256', 'fc-site|' . N(home_url())), 0, 32 )`, N = Kleinschreibung von Schema+Host, ohne abschließenden Slash | `f68ae56830868d27726ceadfdb0a3e12` |
| `exp` | integer | Unix-Sekunden UTC; `exp − now ≤ min(86 400, purpose.max_ttl)` bei Erzeugung | `1795348800` |
| `nonce` | string | 16 Byte aus `random_bytes`, `B64U` → genau 22 Zeichen | `q7Vf3kL0bX9cT2mWz8sYhA` |

Keine weiteren Schlüssel. Personenbezogene Daten (Name, E-Mail, Bestellsummen) sind im Payload verboten – der Payload ist für den Besucher lesbar (nur signiert, nicht verschlüsselt).

### 1.2 Schlüssel

```
secret  = Konstante FORM_CAPTAIN_SECRET (wp-config.php), falls definiert und ≥ 32 Byte,
          sonst Option form_captain_secret (64 Byte random, base64, autoload = no, bei Aktivierung erzeugt)
K_ctx   = hash_hmac( 'sha256', 'form-captain/ctx/v1', wp_salt( 'auth' ) . secret, true )
```

- Domänentrennung über das Label `form-captain/ctx/v1`. Submission-Tokens verwenden dasselbe `secret` mit Label `form-captain/submit-token/v1`; ein Submission-Token ist daher nie als Kontext gültig und umgekehrt.
- Eine künftige inkompatible Formatversion verwendet ein neues Label (`…/ctx/v2`); ein v1-Prüfer lehnt solche Werte mit `context_signature_invalid` ab.
- `K_ctx` wird pro Request höchstens einmal berechnet und nie ausgegeben, geloggt oder an Filter übergeben.

## 2. Erzeugung

### 2.1 Öffentliche Funktion (Ergänzung Contract v1)

```php
/**
 * @return array{success:bool, code:string, context?:string, expires_at?:int, request_id:string}
 */
form_captain_sign_context_v1( string $purpose, int $form_id, string $ref_type, string $ref_id, int $ttl = 0 ): array;
```

> Diese Funktion ergänzt die Funktionsliste in `api-v1.md`; dort nachzutragen. Zwecke werden über den in `api-v1.md` vorgesehenen Filter `form_captain_signed_context_purposes_v1` registriert (3.1).

Ablauf:

1. Aufruf erst nach `form_captain_loaded_v1`, sonst `code = not_ready`.
2. `purpose` registriert und aktiv (Adapter geladen, Contract-Major kompatibel), sonst `context_purpose_unknown`.
3. Eigentümerschaft am Zweck ist im selben PHP-Prozess nicht technisch erzwingbar (Restrisiko R-01 im Bedrohungsmodell). Vertragsregel: Nur das als `owner` registrierte Plugin ruft die Funktion für seinen Zweck auf; Form Captain protokolliert jede Erzeugung nicht, um Hot-Paths nicht zu belasten, zählt sie aber je Zweck für die Diagnose.
4. Formular existiert und erlaubt den Zweck (Formular-Einstellung `accepted_context_purposes` enthält `purpose`), sonst `context_purpose_not_allowed`.
5. `ref_type` ∈ `purpose.ref_types`, `ref_id` passt zum Regex, sonst `context_ref_invalid`.
6. Optionaler `issue_check`-Callback des Zwecks bestätigt die Referenz zum Ausgabezeitpunkt (z. B. Event veröffentlicht; Bestellposition gehört zur aktuellen Kundin), sonst `context_ref_rejected`.
7. `ttl` = `0` → `purpose.default_ttl`; danach `ttl = min(ttl, purpose.max_ttl, 86 400)`, Mindestwert 60.
8. `exp = time() + ttl`, `nonce = B64U(random_bytes(16))`, Payload bauen, signieren, zurückgeben.

### 2.2 Ausgabewege

| Weg (`purpose.issuance`) | Einsatz | Ablauf | Cache-Verhalten |
|---|---|---|---|
| `render` | Seiten, die nicht öffentlich gecacht werden (Mein Konto, Bestellbestätigung, eingeloggte Bereiche) | Adapter ruft 2.1 serverseitig und übergibt den Wert an Block/Shortcode (`[form_captain id="…" context="…"]` nur aus PHP, Block-Attribut nicht vom Editor setzbar) bzw. an Filter `form_captain_render_context_v1`. Renderer schreibt `<input type="hidden" name="_fc_ctx">`. | Seite MUSS `nocache_headers()` senden; Adapter verantwortlich. |
| `public_endpoint` | Öffentliche, cachebare Seiten mit nicht geheimer Referenz (z. B. Eventseite) | Renderer gibt nur `data-fc-ctx-purpose`, `data-fc-ctx-ref-type`, `data-fc-ctx-ref-id` aus. Das Frontend-JS ruft `GET /form-captain/v1/forms/{public_id}/token?ctx_purpose=…&ctx_ref_type=…&ctx_ref_id=…`; die Route ruft `issue_check` und liefert neben dem Submission-Token ein frisches `_fc_ctx`. Nur erlaubt, wenn `purpose.single_use = false`. | Token-Route ist `no-store`; gecachtes HTML enthält keinen signierten Wert. |
| `link` | E-Mail-Links (z. B. WooCommerce-Gastzugang) | Adapter erzeugt Kontext und hängt ihn an **seine eigene** Landingpage; die Landingpage prüft und rendert wie `render`. Form Captain liest `_fc_ctx` nie aus Query-Strings fremder Seiten. | Landingpage nicht cachebar. |

No-JS-Fallback für `public_endpoint`: Der Renderer schreibt zusätzlich einen bei Renderzeit signierten Wert. Ist er beim Absenden abgelaufen, antwortet der No-JS-Pfad mit dem wiedergerenderten Formular (Werte erhalten, frischer Kontext, Meldung CTX-T-02).

## 3. Zwecke

### 3.1 Registrierung

```php
add_action( 'form_captain_loaded_v1', function () {
	add_filter( 'form_captain_signed_context_purposes_v1', fn( array $p ) => $p + [
		'pass.event_registration' => [ /* $definition */ ],
	] );
} );
```

Der Filter wird einmal je Request nach `form_captain_loaded_v1` ausgewertet und das Ergebnis validiert. Ungültige Definitionen oder ein bereits belegter Schlüssel (Filter darf vorhandene Einträge nicht überschreiben; Kern vergleicht vor/nach) → Zweck verworfen + Diagnose `context_purpose_duplicate` bzw. `context_purpose_invalid`.

| Schlüssel in `$definition` | Typ | Pflicht | Bedeutung |
|---|---|---|---|
| `owner` | string | ja | Plugin-Slug des Eigentümers, muss Präfix von `purpose` entsprechen (`pass`, `woo`, `voucher`, `learnboating`, …) |
| `contract` | string | ja | erwartete Vertragsversion als Constraint wie bei Aktionen (`^1.0`); inkompatibel → `incompatible_contract` |
| `label` | string | ja | übersetzbarer Name für Formular-Editor/Diagnose |
| `ref_types` | string[] | ja | erlaubte `ref_type`-Werte |
| `default_ttl` / `max_ttl` | int | ja | Sekunden, `max_ttl ≤ 86 400` |
| `single_use` | bool | ja | Einweg-Zweck mit Replay-Erkennung |
| `issuance` | string | ja | `render` \| `public_endpoint` \| `link` |
| `issue_check` | callable | bei `public_endpoint` ja | `fn(string $ref_type, string $ref_id, int $form_id): bool` |
| `submit_check` | callable | nein | `fn(string $ref_type, string $ref_id, int $form_id): bool` – Prüfung zum Absendezeitpunkt (z. B. Bestellung noch bezahlt, nicht erstattet) |
| `marks_verified` | bool | nein (Default `false`) | Entry darf als verifiziert (z. B. kaufbestätigt) gelten – nur mit `submit_check` zulässig |

### 3.2 Vorgesehene Zwecke (Phase 8, nicht im Free-v1.0-Lieferumfang)

| `purpose` | Eigentümer | `ref_type` | TTL default/max | Einweg | Ausgabe | `marks_verified` |
|---|---|---|---|---|---|---|
| `pass.event_registration` | Pass-Captain-Adapter | `event` | 12 h / 24 h | nein | `public_endpoint` | nein |
| `woo.order_item_questionnaire` | Form Captain Pro (WooCommerce-Modul) | `order_item` (`ref_id` = `{order_id}:{item_id}`) | 2 h / 24 h | ja | `render`, `link` | ja (nur mit `submit_check`: bezahlt, nicht storniert/erstattet, Status ≠ abgeschlossen) |
| `voucher.request` | Voucher-Captain-Adapter | `voucher_product`, `company_order_request` | 12 h / 24 h | nein | `public_endpoint` | nein |
| `learnboating.course_question` | LearnBoating-Connector | `course` | 12 h / 24 h | nein | `public_endpoint` | nein |

Der Gast-Zugang für WooCommerce (Tage gültig, widerrufbar) ist **kein** `_fc_ctx`: Er ist ein eigenes, gespeichertes und widerrufbares Zugangstoken des WooCommerce-Moduls (P8-64), dessen Landingpage nach erfolgreicher Prüfung einen kurzlebigen `_fc_ctx` (`link`) erzeugt.

## 4. Validierung

Ort: Submission-Service, nach Token- und Rate-Limit-Prüfung, vor der Feldvalidierung. Gilt identisch für REST, No-JS (`admin-post.php`) und `form_captain_submit_v1` (Kontext in `$context['signed_context']`).

### 4.1 Vorbedingung

- Formular hat keine `accepted_context_purposes`: Ein übergebenes `_fc_ctx` wird ignoriert und **nicht** gespeichert (kein Fehler, kein Log-Spam; Zähler im Systemstatus).
- Formular hat `context_required = true` und kein `_fc_ctx` → `context_missing`.

### 4.2 Reihenfolge der Prüfungen (verbindlich)

Die Reihenfolge ist so gewählt, dass ungeprüfte Daten erst nach erfolgreicher Signaturprüfung interpretiert werden und teure Prüfungen zuletzt kommen.

| # | Prüfung | Fehlercode (intern) |
|---|---|---|
| 1 | Länge ≤ 1024, genau ein `.`, beide Segmente nur Base64url-Zeichen, Signatursegment 43 Zeichen | `context_malformed` |
| 2 | HMAC über Segment 1 mit `K_ctx` berechnen; `hash_equals` gegen dekodiertes Segment 2. Bei Fehlschlag: erneut mit `K_ctx_previous`, falls Rotationsfenster aktiv (Abschnitt 6) | `context_signature_invalid` |
| 3 | Segment 1 dekodieren, `json_decode(..., true, 2, JSON_THROW_ON_ERROR)`; genau die sieben Schlüssel, Typen und Regexe aus 1.1 | `context_malformed` |
| 4 | `site` == eigener Site-Hash | `context_site_mismatch` |
| 5 | `now > exp + 60` (60 s Toleranz für Uhrabweichung zwischen Webknoten) | `context_expired` |
| 6 | `exp − now > 86 400 + 60` | `context_ttl_invalid` |
| 7 | `purpose` registriert und aktiv | `context_purpose_unknown` |
| 8 | `exp − now > purpose.max_ttl + 60` | `context_ttl_invalid` |
| 9 | `purpose` ∈ `accepted_context_purposes` des Formulars | `context_purpose_not_allowed` |
| 10 | `form_id` == ID des über `{public_id}` adressierten Formulars | `context_form_mismatch` |
| 11 | `ref_type` ∈ `purpose.ref_types` | `context_ref_invalid` |
| 12 | Einweg-Zweck: Nonce noch nicht verbraucht (lesende Vorprüfung, 4.3) | `context_replayed` |
| 13 | `submit_check` (falls definiert) liefert `true`; Exceptions zählen als `false` | `context_ref_rejected` |
| — | Danach reguläre Feldvalidierung. Bei `validation_failed` wird der Nonce **nicht** verbraucht. | — |
| 14 | Einweg-Zweck: Nonce-Verbrauch atomar in der Entry-Transaktion (4.3). Schlägt er fehl → Rollback | `context_replayed` |

### 4.3 Replay-Erkennung (Einweg-Zwecke)

- Speicherung in `fc_rate_limits` (`datenmodell.md`): `bucket = hash('sha256', 'ctxnonce:' . nonce)` (CHAR(64), Primärschlüssel), `form_id`, `hits = 1`, `window_start_gmt = exp + 60 s` (liegt in der Zukunft; die stündliche Bereinigung löscht erst Zeilen mit `window_start_gmt` älter als ihr längstes Fenster und damit nie vor Ablauf des Kontexts).
- Schritt 12: `SELECT 1 FROM fc_rate_limits WHERE bucket = %s`.
- Schritt 14: `INSERT IGNORE` in derselben Transaktion wie `fc_entries`; `affected_rows = 0` → Rollback, `context_replayed`.
- Ohne Transaktionsunterstützung (MyISAM): erst `INSERT IGNORE`, dann Entry speichern; schlägt die Entry-Speicherung fehl, wird die Nonce-Zeile gelöscht.
- Bereinigung mit dem stündlichen Rate-Limit-Cleanup nach `expires_at`.
- Mehrfach verwendbare Zwecke speichern keinen Nonce; Wiederholungen werden durch Rate-Limit und `request_id`-Deduplizierung (T-SUB-12) begrenzt.

### 4.4 Ergebnis

Bei Erfolg speichert der Entry den **verifizierten** Kontext in `fc_entries.context_json` (`datenmodell.md`): `{"purpose":…, "ref_type":…, "ref_id":…, "verified":true|false}`; `verified` nur nach `marks_verified` + `submit_check`. Der rohe `_fc_ctx`-String wird nicht gespeichert. Ein gleichnamiges Formularfeld oder ein Hidden Field mit Referenzwerten wird nie in `context_json` übernommen.

Contract-Payloads (`form_captain_entry_created_v1` Feld `context`, `form_captain_get_entry_v1` Scope `meta`) liefern `{purpose, ref_type, ref_id, verified}`.

## 5. Fehlercodes

Öffentlich (HTTP 400) gibt es genau drei Codes aus `api-v1.md`: `context_expired` (Meldung CTX-T-02), `context_replayed` (Meldung CTX-T-01) und für alle übrigen Fehler `invalid_context` (CTX-T-01); der Detailcode steht nur in `fc_log` (mit `request_id`, Formular-ID, `purpose` falls dekodiert, ohne Nonce und ohne `ref_id` bei Einweg-Zwecken). Contract-Rückgaben an PHP-Aufrufer (`form_captain_sign_context_v1`, `form_captain_submit_v1`) enthalten den Detailcode.

| Interner Code | Bedeutung | Öffentlich | Retry sinnvoll |
|---|---|---|---|
| `context_missing` | Pflichtkontext fehlt | `invalid_context` | nein |
| `context_malformed` | Syntax/Schema verletzt | `invalid_context` | nein |
| `context_signature_invalid` | HMAC falsch (Manipulation, fremder Schlüssel, rotiert) | `invalid_context` | nein |
| `context_site_mismatch` | Wert einer anderen Site (z. B. Staging-Kopie) | `invalid_context` | nein |
| `context_expired` | `exp` überschritten | `context_expired` | ja, nach Neuladen |
| `context_ttl_invalid` | Lebensdauer über Grenze | `invalid_context` | nein |
| `context_purpose_unknown` | Zweck nicht registriert / Adapter inaktiv | `invalid_context` | nein |
| `context_purpose_not_allowed` | Formular akzeptiert Zweck nicht | `invalid_context` | nein |
| `context_form_mismatch` | Kontext für anderes Formular | `invalid_context` | nein |
| `context_ref_invalid` | `ref_type` nicht erlaubt / `ref_id` ungültig | `invalid_context` | nein |
| `context_ref_rejected` | `issue_check`/`submit_check` verneint | `invalid_context` | nein |
| `context_replayed` | Einweg-Nonce bereits verbraucht | `context_replayed` | nein |
| `context_purpose_invalid` | Zweckdefinition ungültig (nur Diagnose) | – | – |
| `context_purpose_duplicate` | Zweck doppelt registriert (nur Diagnose) | – | – |

## 6. Rotation

| Auslöser | Wirkung |
|---|---|
| Admin-Aktion „Signaturschlüssel erneuern“ (*Einstellungen → Systemstatus*, Capability `form_captain_manage_settings`, Nonce, Bestätigung CTX-T-10) | Neues `secret` wird erzeugt; altes wird als `form_captain_secret_previous` mit `form_captain_secret_previous_until = now + 26 h` (24 h max. Kontext-TTL + 2 h Submission-Token) gespeichert. Prüfung akzeptiert in diesem Fenster beide Schlüssel; erzeugt wird nur mit dem neuen. Nach Ablauf entfernt der Daily-Cleanup den alten Wert. |
| `FORM_CAPTAIN_SECRET` in `wp-config.php` geändert | Kein Übergangsfenster (Konstante kennt keine Historie); optional `FORM_CAPTAIN_SECRET_PREVIOUS` für 26 h setzen. |
| Änderung von `wp_salt('auth')` (AUTH_KEY/AUTH_SALT) | Alle Kontexte und Submission-Tokens sofort ungültig; Besucher erhalten CTX-T-02 bzw. holen per JS ein neues Token. Dokumentiert, kein Übergangsfenster. |
| Verdacht auf Kompromittierung | Rotation + sofortiges Löschen von `…_previous` über Option „Alten Schlüssel sofort verwerfen“. |

Rotation verändert keine gespeicherten Entries; verifizierte Kontexte bleiben verifiziert (Nachweis zum Zeitpunkt der Einsendung).

## 7. Beispiel

Testwerte (nur für Testfixtures): `wp_salt('auth') = "TEST-AUTH-SALT"`, `secret = "TEST-PLUGIN-SECRET"`, `home_url() = https://example.org`.

```
K_ctx (hex)  = 0082b215a7aa555a47639f1d99facdaaa0afa0f44d806326da49c090d459cf09
site         = f68ae56830868d27726ceadfdb0a3e12
payload_json = {"purpose":"pass.event_registration","form_id":42,"ref_type":"event","ref_id":"1187","site":"f68ae56830868d27726ceadfdb0a3e12","exp":1795348800,"nonce":"q7Vf3kL0bX9cT2mWz8sYhA"}
_fc_ctx      = eyJwdXJwb3NlIjoicGFzcy5ldmVudF9yZWdpc3RyYXRpb24iLCJmb3JtX2lkIjo0MiwicmVmX3R5cGUiOiJldmVudCIsInJlZl9pZCI6IjExODciLCJzaXRlIjoiZjY4YWU1NjgzMDg2OGQyNzcyNmNlYWRmZGIwYTNlMTIiLCJleHAiOjE3OTUzNDg4MDAsIm5vbmNlIjoicTdWZjNrTDBiWDljVDJtV3o4c1loQSJ9.3Bem2h-sL6mNXOuPPI1swRN_T__3D-jdh5BXXkergos
```

(`exp` = 2026-11-22 12:00:00 UTC; Länge 280 Zeichen.) Diese Werte sind als Fixture `tests/fixtures/context/v1-valid.json` zu übernehmen; der Test berechnet `K_ctx` und Signatur unabhängig nach und vergleicht.

PHP-Referenz (Erzeugung, gekürzt):

```php
$b64u    = static fn( string $b ): string => rtrim( strtr( base64_encode( $b ), '+/', '-_' ), '=' );
$key     = hash_hmac( 'sha256', 'form-captain/ctx/v1', wp_salt( 'auth' ) . $secret, true );
$payload = $b64u( wp_json_encode( [
	'purpose'  => $purpose,
	'form_id'  => $form_id,
	'ref_type' => $ref_type,
	'ref_id'   => $ref_id,
	'site'     => $site_hash,
	'exp'      => time() + $ttl,
	'nonce'    => $b64u( random_bytes( 16 ) ),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
$ctx     = $payload . '.' . $b64u( hash_hmac( 'sha256', $payload, $key, true ) );
```

## 8. Texte

| ID | Englisch (Quelltext) | Deutsch |
|---|---|---|
| CTX-T-01 | This form could not be sent because its link is no longer valid. Please reload the page and try again. | Das Formular konnte nicht gesendet werden, weil sein Link nicht mehr gültig ist. Bitte laden Sie die Seite neu und versuchen Sie es erneut. |
| CTX-T-02 | This form has expired. Please check your entries and send it again. | Dieses Formular ist abgelaufen. Bitte prüfen Sie Ihre Angaben und senden Sie es erneut. |
| CTX-T-10 | Renewing the signing key invalidates links and open forms older than 26 hours. Continue? | Das Erneuern des Signaturschlüssels macht Links und geöffnete Formulare ungültig, die älter als 26 Stunden sind. Fortfahren? |
| CTX-T-11 | Diagnose: Context purpose „%s“ is not available because its plugin is inactive or incompatible. | Diagnose: Kontextzweck „%s“ ist nicht verfügbar, weil das zugehörige Plugin inaktiv oder inkompatibel ist. |

## 9. Testfälle

Typ: U = Unit, I = Integration (WP-Testsuite mit DB), E = E2E.

| ID | Typ | Vorgehen | Erwartung |
|---|---|---|---|
| TC-CTX-01 | U | Fixture aus Abschnitt 7 mit eingefrorener Zeit `exp − 3600` prüfen | gültig, Kontext `pass.event_registration/event/1187` |
| TC-CTX-02 | U | Ein Zeichen im Payload-Segment ändern | `context_signature_invalid` |
| TC-CTX-03 | U | Ein Zeichen in der Signatur ändern; Signatur mit 42/44 Zeichen | `context_signature_invalid` bzw. `context_malformed` |
| TC-CTX-04 | U | Kein Punkt, zwei Punkte, `+`/`/`/`=` im Wert, Länge 1025 | `context_malformed` |
| TC-CTX-05 | U | Gültig signierter Payload mit 8. Schlüssel / fehlendem Schlüssel / `form_id` als String / `ref_id` mit Leerzeichen | `context_malformed` |
| TC-CTX-06 | U | Signaturprüfung erfolgt vor `json_decode` (Spy auf Decoder bei falscher Signatur) | Decoder nicht aufgerufen |
| TC-CTX-07 | U | Zeit = `exp + 61` / `exp + 59` | `context_expired` / gültig |
| TC-CTX-08 | U | Payload mit `exp = now + 90 000` korrekt signiert | `context_ttl_invalid` |
| TC-CTX-09 | U | Zweck mit `max_ttl = 7200`, Kontext mit `exp = now + 10 000` | `context_ttl_invalid` |
| TC-CTX-10 | I | Kontext für `site` einer anderen `home_url` (gleiches Secret, Staging-Kopie) | `context_site_mismatch` in `fc_log`, öffentlich `invalid_context` |
| TC-CTX-11 | I | Kontext für Formular 42 an Formular 43 senden | `context_form_mismatch` |
| TC-CTX-12 | I | Zweck nicht in `accepted_context_purposes` | `context_purpose_not_allowed` |
| TC-CTX-13 | I | Adapter-Plugin deaktivieren, dann absenden | `context_purpose_unknown`, Diagnose CTX-T-11 |
| TC-CTX-14 | I | Einweg-Zweck zweimal absenden (jeweils mit neuem Submission-Token) | 1. Entry erstellt, 2. `context_replayed`, kein zweiter Entry, keine Jobs |
| TC-CTX-15 | I | Einweg-Zweck: 1. Versuch mit `validation_failed`, 2. Versuch korrekt | 2. Versuch erfolgreich (Nonce nicht verbraucht) |
| TC-CTX-16 | I | Zwei parallele Requests mit demselben Einweg-Kontext | genau ein Entry; der andere `context_replayed` |
| TC-CTX-17 | I | `submit_check` liefert `false` (Bestellung erstattet) / wirft Exception | `context_ref_rejected`, kein Entry |
| TC-CTX-18 | I | Formular ohne Kontextzwecke erhält gültiges `_fc_ctx` | Entry ohne Kontextspalten, kein Fehler |
| TC-CTX-19 | I | Hidden Field `event_id=999` + gültiger Kontext `event/1187` | Entry-Kontext `1187`, Hidden-Wert nur als unverifizierter Feldwert (falls Feld existiert) |
| TC-CTX-20 | I | Rotation: Kontext mit altem Schlüssel innerhalb / nach 26 h | gültig / `context_signature_invalid` |
| TC-CTX-21 | I | `form_captain_sign_context_v1` vor `form_captain_loaded_v1` / für nicht registrierten Zweck | `not_ready` / `context_purpose_unknown` |
| TC-CTX-22 | I | `public_endpoint`: Token-Route mit `ctx_*`-Parametern, `issue_check` = false | Token ohne Kontext, Fehlercode `context_ref_rejected` in Antwortfeld `context_error` |
| TC-CTX-23 | I | `public_endpoint` mit Einweg-Zweck registrieren | Zweck verworfen, Diagnose `context_purpose_invalid` |
| TC-CTX-24 | I | Submission-Token als `_fc_ctx` übergeben (Domänentrennung) | `context_signature_invalid` bzw. `context_malformed` |
| TC-CTX-25 | E | Eventseite mit Vollseiten-Cache, zwei verschiedene Browser | jeder erhält eigenen Kontext über Token-Route; HTML enthält keinen signierten Wert |
| TC-CTX-26 | E | No-JS mit abgelaufenem Render-Kontext | Formular wird mit Werten und Meldung CTX-T-02 neu gerendert; zweiter Versuch erfolgreich |
| TC-CTX-27 | I | `fc_log` nach Fehlschlag prüfen | Detailcode + `request_id`, kein Nonce, kein vollständiges `_fc_ctx` |
