# Projekt-Connectoren: Vertragsentwürfe BoatingSchool-Lead-Intake und LearnBoating-Kurskontext

**Status:** **Entwurf** vom 16.11.2026 (P8-42, P8-52), aus Sicht von Form Captain. Verbindlich wird der Vertrag erst, wenn ihn das jeweilige Projekt-Repository übernimmt und versioniert (dort P8-44 bzw. P8-53).
**Voraussetzungen:**
- Freigabe des Inhabers für die Pilot-Connectoren;
- ~~Bestätigung von P8-41~~ **bestätigt am 16.11.2026** am Code (boatingschool `main` d152f21, `routes/commerce.php`): `/api/commerce/v1` hat nur Katalog (`GET /catalog`), Shopkonten (`/accounts…`), Bestellpositionen (`/orders/lines…`) und Chargen (`/batches…`), keinen Lead- oder Interessenten-Eingang. Der Lead-Endpunkt muss also neu und getrennt entstehen (A.1);
- P8-51: Anwendungsfall Kursfrage.

**Bezug:** konzept.md „Pilot-Connectoren“ und 12.8, docs/CONNECTORS.md (Ergebnisse, Idempotenz, Retry), docs/spec/suite-kontext.md, Entscheidung Nr. 17 (endgültig 16.11.2026: jeder Connector ein eigenes Produkt mit eigenem Präfix, nicht Teil von Form Captain Pro; TODO P8-88, P8-89).

---

## Teil A – BoatingSchool: Lead-Intake (P8-42)

### A.0 Umsetzung in BoatingSchool (16.11.2026)

Umgesetzt auf boatingschool Branch `claude/sleepy-ptolemy-n32guj` (`docs/integrations/form-captain-leads.md`, Version 1). Abweichungen von diesem Entwurf (dort Abschnitt 10), verbindlich ist die Fassung in BoatingSchool:
1. Schlüssel liegen in der Server-`.env` (zwei aktive plus Widerrufsliste), nicht im Superadmin-Bereich angelegt; „zuletzt benutzt“ kommt aus dem Cache.
2. Neuer Code `403 site_not_allowed` für eine optionale Liste erlaubter Websites.
3. `DELETE /leads/{id}` antwortet bei unbekannter ID `404 not_found`.
4. `400 bad_request` auch bei fehlendem/ungültigem `Idempotency-Key`, falschem Content-Type und Query-Parametern.
5. Wiederholung nach Löschung des Leads liefert die alte Lead-ID mit `"status": "deleted"` und legt nichts neu an.
6. `202 accepted_merged` trägt Lead-ID und Status.
7. Signiert wird der vollständige Request-Pfad mit führendem Schrägstrich.
Zusätzlich löscht der Connector bei Einwilligungswiderruf den Lead auf der Plattform (Standard an, abschaltbar).

### A.1 Endpunkt

`POST /api/integrations/v1/form-captain/leads`, nur HTTPS, `Content-Type: application/json; charset=utf-8`, Body höchstens 16 KB.

Der Endpunkt ist **getrennt** von `/api/commerce/v1`: eigene Route, eigene Middleware, eigener Schlüssel, keine Commerce-Rechte. Ein Aufruf legt **nur** einen Lead in der Superadmin-Inbox an. Er legt nie einen Tenant, ein Schulkonto, App- oder Shopkonten an und stellt keine Codes aus.

### A.2 Authentisierung (HMAC)

| Header | Inhalt |
|---|---|
| `X-FC-Key-Id` | Kennung des Schlüssels, z. B. `fc-lead-2026-11` (ermöglicht Rotation) |
| `X-FC-Timestamp` | Unix-Sekunden UTC |
| `X-FC-Nonce` | 32 Hex-Zeichen, zufällig je Anfrage |
| `X-FC-Signature` | `v1=` + hex( HMAC-SHA256( secret, "v1\n{timestamp}\n{nonce}\nPOST\n{path}\n" + hex(SHA-256(body)) ) ) |
| `Idempotency-Key` | siehe A.4 |

**Server-Regeln:**
- `|now − timestamp| ≤ 300 s`, sonst `401 signature_expired`.
- Nonce je Key-Id 10 Minuten speichern; Wiederholung → `409 replay_detected`.
- Vergleich in konstanter Zeit.
- Unbekannte Key-Id → `401 key_unknown`.

**Rotation:** Bis zu zwei aktive Schlüssel je Website. Zuerst wird der neue Schlüssel im Superadmin angelegt, dann im Connector eingetragen. Der alte bleibt bis zum Widerruf gültig; empfohlen sind höchstens 7 Tage Überlappung. Der Superadmin zeigt je Schlüssel „zuletzt benutzt“.

### A.3 Body (JSON-Schema-Entwurf `form-captain-lead/1`)

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "form-captain-lead/1",
  "type": "object",
  "additionalProperties": false,
  "required": ["schema", "source", "contact", "consent", "submitted_at"],
  "properties": {
    "schema":       { "const": "form-captain-lead/1" },
    "source": {
      "type": "object", "additionalProperties": false,
      "required": ["site_url", "form_id", "entry_id"],
      "properties": {
        "site_url":   { "type": "string", "format": "uri", "maxLength": 200 },
        "page_url":   { "type": "string", "format": "uri", "maxLength": 500 },
        "form_id":    { "type": "integer", "minimum": 1 },
        "entry_id":   { "type": "integer", "minimum": 1 },
        "plugin_version": { "type": "string", "maxLength": 20 }
      }
    },
    "purpose":      { "enum": ["demo", "consultation", "school_access_info"] },
    "contact": {
      "type": "object", "additionalProperties": false,
      "required": ["email"],
      "properties": {
        "name":         { "type": "string", "maxLength": 120 },
        "email":        { "type": "string", "format": "email", "maxLength": 190 },
        "phone":        { "type": "string", "maxLength": 40 },
        "organisation": { "type": "string", "maxLength": 160 },
        "role":         { "type": "string", "maxLength": 80 }
      }
    },
    "school_size":  { "enum": ["1-2", "3-10", "11-50", "50+"] },
    "message":      { "type": "string", "maxLength": 4000 },
    "consent": {
      "type": "object", "additionalProperties": false,
      "required": ["given_at", "text_revision"],
      "properties": {
        "given_at":      { "type": "string", "format": "date-time" },
        "text_revision": { "type": "integer", "minimum": 1 },
        "text_hash":     { "type": "string", "pattern": "^[a-f0-9]{64}$" }
      }
    },
    "submitted_at": { "type": "string", "format": "date-time" }
  }
}
```

- **Erlaubte Felder** sind genau diese. Der Connector bildet sie aus einem expliziten Feld-Mapping (P8-47). Unbekannte Eigenschaften lehnt der Server ab (`422 schema_invalid`), statt sie still zu verwerfen.
- **Nie übertragen:** sensible Felder, versteckte Felder, IP-Adressen, User-Agent, interne Notizen, Werte anderer Formularfelder.
- `consent` verweist auf den Einwilligungsnachweis im Form-Captain-Entry. Der Einwilligungstext selbst bleibt in WordPress, übertragen werden nur Revision und optional der Hash.

### A.4 Idempotenz

- `Idempotency-Key` = der Schlüssel des Form-Captain-Jobs (`form-captain:{site_id}:{entry_id}:{action_key}:{instance_id}`, docs/CONNECTORS.md 4).
- Der Server speichert Schlüssel und Ergebnis mindestens 30 Tage. Eine Wiederholung mit gleichem Schlüssel und gleichem Body-Hash liefert **dieselbe** Antwort (`200` mit derselben `lead_id`, Header `Idempotent-Replay: true`).
- Gleicher Schlüssel mit anderem Body-Hash → `409 idempotency_conflict`.

### A.5 Antworten und Fehlercodes

| HTTP | `code` | Bedeutung | Ergebnis im Connector (docs/CONNECTORS.md) |
|---|---|---|---|
| 201 | `created` | Lead angelegt, Body `{ "lead_id": "L-…", "status": "new" }` | `success`, `remote_id` = `lead_id` |
| 200 | `created` | idempotente Wiederholung | `success`, gleiche `remote_id` |
| 400 | `bad_request` | kein JSON, zu groß | `fail` |
| 401 | `signature_invalid` / `signature_expired` / `key_unknown` | Authentisierung | `fail` (`signature_expired` einmal als `retry`, wegen möglicher Uhrabweichung) |
| 403 | `key_revoked` | Schlüssel widerrufen | `fail` |
| 409 | `replay_detected` | Nonce wiederverwendet | `retry` mit neuer Nonce |
| 409 | `idempotency_conflict` | gleicher Schlüssel, anderer Inhalt | `fail` |
| 422 | `schema_invalid` | Body verletzt das Schema; `errors[]` mit JSON-Pointer, ohne Werte | `fail` |
| 429 | `rate_limited` | Grenze überschritten, `Retry-After` | `retry` |
| 5xx / Timeout | – | Server- oder Netzfehler | `retry` (Backoff des Kerns, höchstens 8 Versuche) |

Fehlerantworten enthalten nie Werte aus dem Body.

### A.6 Rate-Limits

- Je Key-Id 60 Anfragen pro Minute und 1 000 pro Tag.
- Je Absender-E-Mail-Hash 5 neue Leads pro Tag. Eine Überschreitung legt keinen weiteren Lead an und liefert `202 accepted_merged` mit der bestehenden `lead_id`, damit der Connector nicht endlos wiederholt.

### A.7 Verbindungstest

`POST /api/integrations/v1/form-captain/ping` mit derselben Signatur und leerem Body `{}` liefert `200 { "ok": true, "schema": "form-captain-lead/1", "key_id": "…" }`. Kein Lead entsteht. Der Connector nutzt diesen Endpunkt für „Verbindung testen“ (P8-46).

### A.8 Datenschutz im Zielsystem

- Lead-Inbox mit Aufbewahrungsfrist, Export und Löschung (P8-43).
- Löschung in Form Captain (`form_captain_entry_deleted_v1`) → der Connector ruft `DELETE /api/integrations/v1/form-captain/leads/{lead_id}` (signiert, idempotent, `204` oder `404`). Eine Löschung in BoatingSchool wirkt nicht auf den Form-Captain-Entry zurück.

---

## Teil B – LearnBoating: Kurskontext (P8-52)

### B.1 Erweiterungspunkt im Lernplugin

Das Lernplugin `bootspruefung-learning` stellt **lokal** zwei Dinge bereit: eine Funktion und einen Filter. Es gibt keine HTTP-Rundreise.

```php
// Vertragsversion; Major-Wechsel = inkompatibel.
define( 'BOOTSPRUEFUNG_FORM_CONTEXT_VERSION', '1.0.0' );

/**
 * Öffentlicher Kurskontext.
 *
 * @param array{course_code?:string,post_id?:int} $query genau einer der Schlüssel
 * @return array{course_code:string,label:string,permalink:string,status:string}|null
 *         null, wenn unbekannt, unveröffentlicht oder nicht anfragbar
 */
function bootspruefung_form_context_v1( array $query ): ?array;

/** Liste aller anfragbaren Kurse für eine Auswahl (höchstens 200, sortiert nach label). */
function bootspruefung_form_courses_v1(): array; // list<array{course_code,label,permalink,status}>
```

| Feld | Typ | Regel |
|---|---|---|
| `course_code` | string | `^[A-Z0-9][A-Z0-9_-]{1,31}$`, stabil über Versionen |
| `label` | string | öffentlicher Kursname, höchstens 160 Zeichen, ohne HTML |
| `permalink` | string | öffentliche URL der Kursseite |
| `status` | string | `open` (anfragbar) oder `waitlist`; andere Kurse werden nicht ausgegeben |

**Nicht enthalten:** Preise, Termine, Lernfortschritt, Prüfungsversuche, Bestellungen, Gutscheine, Benutzerdaten.
**Ohne Form Captain** funktioniert das Lernplugin unverändert. Die Funktionen sind reine Lesezugriffe ohne Seiteneffekte.

### B.2 Nutzung durch den Connector

1. Der Connector registriert den Zweck `learnboating.course_question` (`ref_type` `course`, Ausgabe `render`, `public_endpoint`; nicht einmalig; `marks_verified` ja) über `form_captain_register_context_purpose_v1()`.
2. **Kursseite:** Der Connector ermittelt den Kurs serverseitig über `bootspruefung_form_context_v1( [ 'post_id' => get_queried_object_id() ] )` und gibt den signierten Kontext (`_fc_ctx`) aus.
3. **Auswahl im Formular:** Die Kursauswahl wird gegen `bootspruefung_form_courses_v1()` geprüft. `submit_check` lehnt unbekannte oder geschlossene Kurse ab (`context_ref_invalid`).
4. Gespeichert wird im Entry nur `context_json` = `{"purpose":"learnboating.course_question","ref_type":"course","ref_id":"{course_code}","verified":true}`. Das Label wird zur Anzeige frisch gelesen.
5. Fehlt der Vertrag oder hat er eine andere Major-Version: Der Connector deaktiviert seine Aktion sichtbar mit dem Code `requirements_not_met`. Das Formular funktioniert ohne Kursbezug weiter (P8-58).

### B.3 Fehlerfälle

| Fall | Verhalten |
|---|---|
| Kurs nach Ausgabe des Kontexts geschlossen | `submit_check` schlägt fehl → neutrale Meldung, Eingaben bleiben erhalten, kein Entry mit falschem Bezug |
| Kurs wird nach der Einsendung entfernt | Entry behält `ref_id`, die Anzeige zeigt „Kurs nicht mehr verfügbar“ |
| Lernplugin deaktiviert | Aktion inaktiv, keine Fatal Errors (`function_exists`-Prüfung) |

---

## Offene Punkte für den Inhaber und die Projekt-Repositories

1. P8-41 im BoatingSchool-Repository bestätigen; danach diesen Teil A dorthin übernehmen.
2. Werte für `purpose` und `school_size` mit dem tatsächlichen B2B-Angebot abgleichen (P8-40).
3. Aufbewahrungsfrist der Lead-Inbox festlegen (P8-43, Rechtsprüfung).
4. Name der Lernplugin-Funktionen (`bootspruefung_…`) und Prefix-Regeln im learnboating-Repository bestätigen (P8-53).
