# JSON-Formularschema v1 (P0-05)

Maschinenlesbare Fassung: [`schema/form-schema-v1.json`](../../schema/form-schema-v1.json) (JSON Schema Draft 2020-12). Die Servervalidierung (`FormCaptain\Schema\SchemaValidator`) ist maßgeblich; die JSON-Schema-Datei dient Editor, Import und Tests.

## Struktur

```json
{
  "schema_version": 1,
  "fields": [
    {
      "id": "f_8k2m1q",
      "type": "email",
      "key": "email",
      "label": "E-Mail-Adresse",
      "required": true,
      "help": "Wir antworten an diese Adresse.",
      "placeholder": "name@example.com",
      "default": "",
      "css_class": "",
      "sensitive": false,
      "validation": { "max_length": 254 },
      "condition": null
    }
  ]
}
```

## Regeln

| Regel | Festlegung |
|---|---|
| `schema_version` | Ganzzahl, aktuell `1`. Unbekannte höhere Versionen werden abgelehnt (`schema_unsupported`). |
| `id` | `^f_[a-z0-9]{6,32}$`, beim Anlegen zufällig erzeugt, **nie** geändert; eindeutig je Formular. Gelöschte IDs werden nicht wiederverwendet (Editor merkt sie in `retired_ids`). |
| `key` | `^[a-z][a-z0-9_]{0,39}$`, eindeutig, für Export-Spalten und Mail-Platzhalter `{key}`. Änderbar; Entries referenzieren `id`, nicht `key`. |
| `label` | 1–190 Zeichen, Klartext (kein HTML). Pflicht außer bei `html`, `hidden`. |
| `help`, `placeholder` | ≤ 500 bzw. ≤ 190 Zeichen Klartext. |
| `required` | bool; für `html` immer false. |
| `sensitive` | bool; sensible Felder werden standardmäßig weder in Mails noch in Connector-Payloads aufgenommen. |
| `css_class` | `^[A-Za-z0-9_\- ]{0,100}$`. |
| `retired_ids` | optionales Array bereits verwendeter IDs auf Wurzelebene. |

### Feldtypen und typspezifische Optionen

| Typ | Wert | `validation` | Weitere Eigenschaften |
|---|---|---|---|
| `text` | String | `min_length`, `max_length` (≤ 1000, Standard 190), `pattern` (`alpha`, `alnum`, `zip_de`, `zip_at`, `zip_ch`, `zip_generic`, `custom` + `custom_pattern`; weitere über Filter `form_captain_validation_patterns_v1`) | |
| `textarea` | String | `min_length`, `max_length` (≤ 10000, Standard 5000) | `rows` (2–20) |
| `email` | String (normalisiert: trim, lowercase) | `max_length` ≤ 254 | `confirmation_recipient` (bool): Ziel für Nutzerbestätigung; `privacy_lookup` (bool, Default true): Feld wird vom Privacy Exporter/Eraser zur Zuordnung genutzt |
| `tel` | String `^[0-9 +()\-/]{3,40}$` | `format`: `international` (beginnt mit `+` und Ländervorwahl, 7–15 Ziffern) | |
| `number` | Dezimalzahl als String | `min`, `max`, `step` | |
| `select` | ein Optionswert | | `options` (1–200), `multiple` (bool) |
| `radio` | ein Optionswert | | `options` (1–50) |
| `checkbox` | Array von Optionswerten | `min_choices`, `max_choices` | `options` (1–50) |
| `date` | `YYYY-MM-DD` | `min`, `max` (Datum oder `today`), `min_age` (1–120 Jahre am Tag der Einsendung; ohne Vorgabe, Rechtsprüfung je Land) | |
| `html` | – (kein Wert) | | `content`: bereinigtes HTML, Allowlist siehe unten |
| `hidden` | String ≤ 500 | | `default` ist der Wert; **nie** vertrauenswürdig |
| `consent` | `"1"` oder leer | | `consent_text` (Pflicht, Klartext mit optionalem Link-Markup), `privacy_link` (bool), `consent_revision` (int ≥ 1, wird bei jeder Textänderung vom Editor erhöht; Import setzt 1). Nie vorangekreuzt: `default` ist verboten. |

`options`: Array von `{ "value": "^[A-Za-z0-9_\-]{1,64}$", "label": "1–190 Zeichen" }`, Werte eindeutig.

Für alle Felder mit Wert (außer `hidden`): `validation.message` (≤ 190 Zeichen) ersetzt die Standard-Fehlermeldung (SET-FC-11).
Für `text`, `textarea`, `email`, `tel`, `number`, `select`, `date`: `autocomplete` aus der festen Liste
`off`, `name`, `given-name`, `family-name`, `honorific-prefix`, `organization`, `email`, `tel`, `street-address`,
`address-line1`, `address-line2`, `postal-code`, `address-level2`, `address-level1`, `country-name`, `bday`, `url` (SET-FC-33).

Eigenes Muster (`pattern: custom`): Regulärer Ausdruck ohne Begrenzer, ≤ 200 Zeichen, der ganze Wert muss passen.
Abgelehnt werden (Fehlercode `pattern_unsafe`): wiederholte Gruppen mit innerer Wiederholung oder Alternative
(`(a+)+`, `(a|aa)*`), Rückverweise, Lookarounds, Rekursion und Inline-Flags; nicht übersetzbare Ausdrücke
ergeben `pattern_invalid`.

### HTML-Allowlist (`html`, `consent_text`)

`p, br, strong, em, b, i, ul, ol, li, a[href|title|rel|target], h3, h4, span[class]`. `href` nur `http`, `https`, `mailto`. `target` erzwingt `rel="noopener noreferrer"`. Bereinigung mit `wp_kses()` beim Speichern **und** bei der Ausgabe.

### Bedingte Sichtbarkeit (Free)

```json
"condition": { "action": "show", "field": "f_a1b2c3", "operator": "equals", "value": "angebot" }
```

- Genau **eine** Regel pro Feld. `action`: `show` | `hide`. `operator`: `equals`, `not_equals`, `contains`, `is_empty`, `is_not_empty`, `checked`.
- `field` muss auf ein **vorher** im Formular stehendes Feld mit Wert verweisen (keine Zyklen, keine Vorwärtsreferenz).
- Server wertet Regeln unabhängig vom Client aus. Unsichtbare Felder: Pflichtprüfung entfällt, gesendeter Wert wird **verworfen** (nicht gespeichert).
- Verschachtelte AND/OR-Gruppen sind Pro (`condition_group`, Erweiterungspunkt `form_captain_condition_evaluators_v1`).

## Grenzen (siehe `budgets.md`)

Max. 100 Felder, 200 Optionen je Feld, Schema-JSON ≤ 256 KB.

## Migrationsregeln

1. Jede Änderung am Format erhöht `schema_version` und bekommt eine Migrationsklasse `FormCaptain\Schema\Migrations\V{n}To{n+1}` (rein, ohne Seiteneffekte, getestet).
2. Beim Laden wird ein älteres Schema in-memory migriert; beim nächsten Speichern persistiert.
3. Veröffentlichte Snapshots (`fc_form_versions`) werden nie umgeschrieben; der Renderer migriert beim Lesen.
4. Import akzeptiert Versionen ≤ aktuell; höhere → `schema_unsupported`.
5. Felder werden nie umnummeriert. Entfernte Felder bleiben in alten Snapshots; Entry-Ansicht zeigt deren Werte mit Label aus dem Snapshot und Hinweis „Feld entfernt“.

## Formulareinstellungen (`settings_json`)

```json
{
  "notification": { "enabled": true, "to": ["{admin_email}"], "reply_to_field": "f_8k2m1q", "subject": "Neue Anfrage: {form_title}", "fields": "all_non_sensitive" },
  "confirmation_mail": { "enabled": false, "subject": "", "body": "" },
  "confirmation": { "type": "message", "message": "Danke! Wir melden uns.", "page_id": 0 },
  "paused_message": "Dieses Formular nimmt derzeit keine Einsendungen an.",
  "appearance": { "mode": "inherit" },
  "retention": { "mode": "global" },
  "actions": [],
  "submit_label": "Anfrage senden",
  "texts": { "required_hint": "", "error_summary": "" },
  "limits": { "opens_at": "2026-12-01T09:00", "closes_at": "", "max_entries": 0, "closed_message": "" }
}
```

`submit_label` ≤ 60 Zeichen (SET-FC-02), `texts.*` ≤ 190 Zeichen (SET-FC-11); leer = übersetzter Standardtext,
längere Texte werden gekürzt und als `text_too_long` gemeldet. `limits` (SET-FC-18): `opens_at`/`closes_at` als
`YYYY-MM-DDTHH:MM` in der Zeitzone der Website, `max_entries` 0–1 000 000 (0 = keine Grenze; Spam und Papierkorb
zählen nicht), `closed_message` ≤ 500 Zeichen. Außerhalb des Zeitraums oder bei erreichter Höchstzahl zeigen
Renderer, Token-Endpunkt und Einsendung `form_not_accepting` mit dem Text; Filter `form_captain_form_limits_v1`.

`confirmation.type` = `message` | `page` (nur interne veröffentlichte Seite per ID, siehe P3-15). `actions`: aktivierte Connector-Aktionen mit Feld-Mapping (siehe `api-v1.md`).
