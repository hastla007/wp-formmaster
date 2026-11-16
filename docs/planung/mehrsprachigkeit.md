# Mehrsprachigkeit (P9-07)

**Status:** Planung. Umsetzung erst nach Freigabe dieser Zuordnung und der Testmatrix. Free v1.0 ist übersetzungsfertig (Textdomain `form-captain`, de_DE/de_AT), übersetzt aber **keine Formularinhalte**.
**Ziel:** Ein Formular kann auf mehrsprachigen Websites in der Sprache der aufgerufenen Seite angezeigt werden, ohne Feld-IDs, Entries oder Exporte je Sprache aufzuspalten. Unterstützt werden zunächst die Mehrsprachigkeits-Plugins WPML und Polylang über einen gemeinsamen Adapter.

## 1. Abgrenzung

| Ebene | Zuständig | In P9-07 |
|---|---|---|
| Plugin-Oberfläche (Admin, Fehlermeldungen, Standard-Mails) | Textdomain `form-captain`, Sprachpakete | unverändert |
| **Formularinhalte** (vom Betreiber eingegebene Texte) | Übersetzungsadapter | **ja** |
| Feldwerte der Einsendung | werden nie übersetzt | – |
| Optionswerte (`options[].value`) | sprachneutral, stabil | nicht übersetzbar |

## 2. Zuordnungsmodell (Entscheidung vorgeschlagen)

**Variante A – ein Formular, übersetzte Zeichenketten (empfohlen).** Ein `fc_forms`-Datensatz, eine Feldstruktur, gleiche `public_id` in allen Sprachen. Übersetzbare Texte werden beim Veröffentlichen beim Mehrsprachigkeits-Plugin registriert und beim Rendern in der aktuellen Sprache aufgelöst.

**Variante B – ein Formular je Sprache, verknüpft.** Getrennte Formulare mit eigener Struktur, über eine Übersetzungsgruppe verbunden. Nachteil: Entries und CSV je Sprache getrennt, Feld-IDs unterschiedlich, Mails/Aktionen mehrfach zu pflegen. Nur als Ausnahme, wenn Sprachen tatsächlich unterschiedliche Felder benötigen – dann ohne Adapter als getrennte Formulare.

Für A gilt:

| Übersetzbarer Text | Schlüssel (`name`) | Kontext |
|---|---|---|
| Formulartitel (öffentlich, falls angezeigt) | `title` | `form-captain/{public_id}` |
| `label`, `help`, `placeholder` je Feld | `{field_id}.label` / `.help` / `.placeholder` | wie oben |
| Optionslabels | `{field_id}.option.{value}` | wie oben |
| `html.content` | `{field_id}.content` | wie oben, Allowlist nach Übersetzung erneut angewendet |
| `consent_text` | `{field_id}.consent_text` | wie oben |
| `confirmation.message`, `paused_message` | `settings.confirmation.message`, `settings.paused_message` | wie oben |
| `confirmation.page_id` | Seite der jeweiligen Sprache über die Übersetzungsbeziehung der Seite | – |
| Bestätigungsmail Betreff/Text | `settings.confirmation_mail.subject` / `.body` | wie oben |
| Admin-Mail | **nicht** übersetzt; Sprache = Sprache des Empfängers bzw. Site-Sprache | – |

- Registrierung erfolgt beim **Veröffentlichen** aus dem Snapshot (`fc_form_versions`); jeder registrierte Text trägt den Hash des Quelltexts. Ändert sich der Quelltext, gilt die Übersetzung als **veraltet**: Ausgabe in Quellsprache, Hinweis im Editor („3 Übersetzungen veraltet“).
- Fehlende Übersetzung → Quellsprache (kein leeres Label).
- Validierung und Bedingungen arbeiten ausschließlich auf IDs und Optionswerten; sie sind sprachunabhängig.

## 3. Datenmodell-Ergänzungen

- `fc_entries.locale` VARCHAR(20) NULL – Sprache der Einsendung (aus dem Adapter, sonst `determine_locale()`); Migration additiv.
- `consent_json` ergänzt um `locale` und Hash des **angezeigten** (übersetzten) Consent-Texts; `consent_revision` bleibt formularweit und wird erhöht, wenn sich Quelltext **oder** eine Übersetzung des Consent-Texts ändert.
- Keine Speicherung der Übersetzungen in Form-Captain-Tabellen in Variante A; sie liegen beim Mehrsprachigkeits-Plugin. Folge: JSON-Export eines Formulars enthält nur die Quellsprache (optional später: Übersetzungen als Anhang).

## 4. Laufzeitverhalten

- Sprache ermitteln: Adapter (`current_language()`), Fallback `determine_locale()`.
- Token-Endpunkt (`GET /forms/{public_id}/token`) und Submission sind sprachunabhängig; Fehlermeldungen in der Sprache des Requests (Parameter `lang` wird nur gegen die vom Adapter gemeldeten aktiven Sprachen akzeptiert).
- Seitencaches: Sprachvarianten werden vom Mehrsprachigkeits-Plugin über URL/Domain getrennt; Form Captain fügt keine eigenen Sprachcookies hinzu.
- Bestätigungsmail in `fc_entries.locale` (`switch_to_locale()` während des Jobs), Admin-Mail in Site- bzw. Benutzersprache.
- CSV-Export: Spalten nach `key`, Spalte `locale`; Optionslabels in der Sprache des exportierenden Benutzers, Optionswerte immer zusätzlich verfügbar.
- Block/Shortcode: gleiche `public_id` in allen Sprachversionen einer Seite; keine Duplikation beim Übersetzen der Seite nötig.

## 5. Adapter

```php
interface TranslationProviderInterface {
    public function id(): string;                           // 'wpml' | 'polylang'
    public function is_active(): bool;
    public function current_language(): ?string;
    public function languages(): array;                     // aktive Sprachcodes
    public function register( string $context, string $name, string $value ): void;
    public function translate( string $context, string $name, string $value, ?string $lang = null ): string;
    public function translated_post_id( int $post_id, string $lang ): ?int; // für confirmation.page_id
}
```

- WPML: String-Registrierung und -Übersetzung über die dokumentierten Hooks (`wpml_register_single_string`, `wpml_translate_single_string`, `wpml_current_language`, `wpml_object_id`).
- Polylang: `pll_register_string`, `pll_translate_string`, `pll_current_language`, `pll_get_post`.
- Registrierung über Filter `form_captain_translation_providers_v1`; weitere Anbieter als separate Erweiterung.
- Nur genau ein aktiver Provider; sind mehrere aktiv, Systemstatus-Warnung und kein Adapter.
- Exakte API-Nutzung ist vor Umsetzung gegen die aktuelle Entwicklerdokumentation beider Plugins zu verifizieren (Mindestversionen festhalten).

## 6. Testmatrix

| Dimension | Werte |
|---|---|
| Provider | keiner, WPML (Mindest- und aktuelle Version), Polylang (Mindest- und aktuelle Version) |
| Sprachen | de_DE (Quelle), en_US, de_AT; eine RTL-Sprache (ar) für Darstellung |
| URL-Modus | Verzeichnis (`/en/`), Subdomain, Parameter |
| Einbettung | Block, Shortcode, Widget-Bereich |
| Cache | ohne, Seitencache aktiv |

| ID | Fall | Erwartung |
|---|---|---|
| ML-01 | Formular veröffentlichen | alle Texte aus Tabelle 2 registriert, keine Feldwerte |
| ML-02 | Seite in Sprache B aufrufen | Labels/Optionen/Consent in B; fehlende → Quellsprache |
| ML-03 | Quelltext ändern, erneut veröffentlichen | Übersetzung veraltet, Ausgabe Quellsprache, Editor-Hinweis |
| ML-04 | Einsendung in B | `locale = B`, Werte = Optionswerte, Consent-Hash des B-Texts |
| ML-05 | Bestätigungsmail | Sprache B; Admin-Mail Site-Sprache |
| ML-06 | `confirmation.page_id` | Weiterleitung auf Übersetzung der Seite in B, sonst Originalseite |
| ML-07 | CSV-Export | eine Datei, Spalte `locale`, gleiche Spaltenstruktur für alle Sprachen |
| ML-08 | Privacy Export/Eraser | Entries aller Sprachen gefunden |
| ML-09 | Provider deaktiviert | Formulare in Quellsprache, keine Fehler |
| ML-10 | zwei Provider aktiv | Warnung, kein Adapter, Quellsprache |
| ML-11 | Seitencache + Sprachwechsel | korrekte Sprache, Token gültig |
| ML-12 | `html`/`consent_text`-Übersetzung mit unzulässigem HTML | bereinigt nach Allowlist |

## 7. Offene Entscheidungen

- Variante A als verbindlich bestätigen (Produktverantwortung).
- Gehört der Adapter in Free oder Pro? Vorschlag: Free (Grundfunktion für mehrsprachige Sites, kein künstliches Limit); Entscheidung offen.
- Übersetzungen im Formular-JSON-Export mitliefern (ja/nein).
- Mindestversionen der unterstützten Mehrsprachigkeits-Plugins.
