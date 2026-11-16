# WooCommerce Pro: Käuferfragebogen – Grenze, Bestellvertrag, Feld- und Kontext-Allowlist

**Status:** Spezifikation 1.0 vom 16.11.2026 (P8-60, P8-61, P8-63). Grundlage für die Umsetzung im Add-on `form-captain-pro` (Repository captain-pro) und für die Abnahme P8-74 und P8-87.
**Bezug:** konzept.md Abschnitte „WooCommerce Pro: Käuferfragebogen nach dem Kauf“ und 12.9, docs/spec/suite-kontext.md (Zweck `woo.order_item_questionnaire`), docs/spec/api-v1.md, docs/handbuch/kompatibilitaet.md (Free-Isolation).
**Normativ:** Die Wörter MUSS, DARF NICHT, SOLL und DARF sind verbindlich. Wo diese Datei und das Konzept voneinander abweichen, gilt diese Datei. Abschnitt 6 nennt die Stellen, die noch an einer echten WooCommerce-Installation zu prüfen sind.

---

## 1. Free/Pro-Grenze und Releaseumfang (P8-60)

### 1.1 Free (`form-captain`)

- Free enthält **keinen** WooCommerce-Code: keine `woocommerce_*`-Hooks, keine `wc_*()`-Aufrufe, keine Woo-Tabellen. Abgesichert durch `tests/integration/Compat/WooCommerceIsolationTest.php`. Daran ändert dieses Modul nichts.
- Erlaubt bleibt nur die informative Erkennung im Dashboard (WooCommerce aktiv ja/nein).
- Free deklariert deshalb **keine** WooCommerce-Feature-Kompatibilität (HPOS, Cart/Checkout Blocks). Das Free-Plugin berührt diese Bereiche nicht.
- Was der Kern für das Modul bereitstellt (alles schon in Contract v1, siehe api-v1.md):
  - signierter Kontext: `form_captain_sign_context_v1()`, Zweck-Registrierung, `context_json` am Entry;
  - Ausgabe des Formulars: `form_captain_get_render_v1()`;
  - Ereignisse: `form_captain_entry_created_v1`, `_entry_deleted_v1`, `_consent_withdrawn_v1`;
  - `form_captain_entry_detail_sections_v1` für den Bestellbezug in der Entry-Ansicht;
  - Queue-Aufträge über registrierte Aktionen.

### 1.2 Pro v1 (`form-captain-pro`, Recht `form.woocommerce_questionnaires`)

| Bestandteil | In Pro v1 |
|---|---|
| Produktzuordnung im Produkteditor (ein veröffentlichtes Formular je Produkt, optional oder „vor Bearbeitung erforderlich“, Standard aus); Variationen erben vom Elternprodukt | ja |
| Einfrieren von Formular-ID, veröffentlichter Version und Pflicht-Kennzeichen je Bestellposition (Abschnitt 2.2) | ja |
| Freigabe nach bestätigter Zahlung, Sperre bei Storno oder Vollerstattung (Abschnitt 2) | ja |
| Käuferzugang: Bestellbestätigung, „Mein Konto → Bestellung“, Link in der Kunden-E-Mail; Gastzugang mit widerrufbarem Token (P8-64, P8-81) | ja |
| Status je Bestellposition im Auftrag, Link zum Entry, Korrektur anfordern | ja |
| eine optionale Erinnerung (P8-82) | ja |
| WooCommerce-Bereich unter Form Captain → Integrationen (Aktivität, Versionen, Lizenzstatus) | ja |
| Deklaration HPOS (`custom_order_tables`) und Cart/Checkout Blocks (`cart_checkout_blocks`) über `FeaturesUtil::declare_compatibility()`, **erst nach** bestandener Matrix P6-22 (P8-86) | ja |

**Nicht in Pro v1:** Felder in Cart oder Checkout (klassisch und Blocks), Zahlungen, Änderungen an Bestell- oder Zahlungsstatus, harte Sperren für Download oder Versand, mehrere Bögen je Stückzahl, Abonnements, Buchungen, Bundles, Multisite. Jede dieser Erweiterungen braucht eine eigene Spezifikation.

### 1.3 Lizenz

- Ohne gültiges Recht `form.woocommerce_questionnaires`: keine neuen Produktzuordnungen, keine neuen Freigaben für Bestellpositionen ohne eingefrorene Zuordnung.
- Schon eingefrorene, offene Fragebögen bleiben für Käufer erfüllbar und für Shopmitarbeiter sichtbar (P8-83).
- Lizenzende, Deaktivierung oder Deinstallation von Pro löscht weder Entries noch Auftragsdaten (P8-75).

---

## 2. Bestell- und Statusvertrag (P8-61)

### 2.1 Begriffe

- **Position:** eine `WC_Order_Item_Product` mit eingefrorener Zuordnung (2.2).
- **Berechtigt:** Für die Position darf ein Zugang bestehen und eine Antwort angenommen werden (2.3).
- **Positionsstatus:** gespeichert an der Position (2.5). Er ist ein Status des Moduls, kein WooCommerce-Status.

### 2.2 Zuordnung einfrieren

1. Beim Anlegen der Position MUSS das Modul die Zuordnung des Produkts festhalten: Formular-ID, veröffentlichte Versions-ID (`published_version_id`) und das Kennzeichen „vor Bearbeitung erforderlich“. Bei einer Variation gilt die Zuordnung des Elternprodukts.
   - Hook für klassischen und Block-Checkout: `woocommerce_checkout_create_order_line_item` (siehe 6.1).
2. Positionen ohne Checkout-Hook (manuell im Admin angelegt, per REST oder Import) werden bei der ersten Berechtigungsprüfung eingefroren, mit dem dann gültigen Stand. Das Feld `frozen_via` hält fest, auf welchem Weg.
3. Spätere Änderungen an Produkt oder Formular, das Archivieren des Formulars oder neue Formularversionen ändern eingefrorene Positionen nicht (P8-78, P8-84). Fehlt die eingefrorene Version (gelöscht), zeigt der Auftrag „Formular nicht mehr verfügbar“ mit Supportweg. Es entsteht kein neuer Zugang zu einer anderen Version.
4. Hat das Produkt beim Anlegen der Position keine Zuordnung, wird die Position nie berechtigt, auch wenn später eine Zuordnung dazukommt.

### 2.3 Berechtigung (verbindliche Prüfung)

Eine Position ist **berechtigt**, wenn alle Bedingungen gelten:

| # | Bedingung | Quelle (nur WooCommerce-APIs, HPOS-fähig) |
|---|---|---|
| 1 | Auftrag existiert und ist kein Entwurf | `wc_get_order()`; Status ≠ `checkout-draft` |
| 2 | Zahlung bestätigt **oder** kostenlose Bestellung abgeschlossen | `$order->is_paid()` (Status aus `wc_get_is_paid_statuses()`, Standard `processing`, `completed`); für Gesamtsumme 0: `$order->get_total() == 0` **und** Status in `wc_get_is_paid_statuses()` |
| 3 | Auftrag weder storniert, fehlgeschlagen, ausstehend, in Wartestellung noch vollständig erstattet | Status ∉ {`pending`, `on-hold`, `failed`, `cancelled`, `refunded`} |
| 4 | Position nicht vollständig erstattet | `abs( $order->get_qty_refunded_for_item( $item_id ) ) < $item->get_quantity()` |
| 5 | Position hat eine eingefrorene Zuordnung (2.2) | Positionsdaten |
| 6 | Positionsstatus erlaubt Antworten | `pending` oder `requested_again` (2.5) |

- Eigene Bezahlt-Status von Plugins zählen nur, wenn sie über den Filter `woocommerce_order_is_paid_statuses` gemeldet sind. Das Modul führt keine eigene Statusliste.
- Eine **Teilerstattung** (Bedingung 4 knapp erfüllt) lässt die Position berechtigt. Der Hinweistext im Produkteditor erklärt: ein Bogen je Position, unabhängig von der Menge (P8-80). Eine Erstattung an einer anderen Position ändert nichts.
- Die Prüfung MUSS zu drei Zeitpunkten laufen: bei der Freigabe (2.4), beim Ausgeben des Zugangs (`issue_check`) und beim Annehmen der Antwort (`submit_check`, suite-kontext.md 3.1).

### 2.4 Auslöser und Idempotenz

| Ereignis (WooCommerce) | Wirkung | Idempotenzschlüssel |
|---|---|---|
| `woocommerce_payment_complete`, `woocommerce_order_status_processing`, `woocommerce_order_status_completed` | Für jede berechtigte Position im Zustand `not_requested`: Wechsel auf `pending`, dann ein Queue-Auftrag für die Käufer-Mail | Positionsstatus-Übergang plus Auftragsschlüssel `woo.questionnaire_mail:{order_id}:{item_id}:{request_count}` |
| `woocommerce_order_status_cancelled`, `_refunded`, `_failed` | Offene Positionen (`pending`, `requested_again`) auf `revoked`; offene Mail- und Erinnerungsaufträge stornieren; Gast-Tokens widerrufen | Übergang nur aus offenen Zuständen |
| `woocommerce_order_refunded` (auch teilweise) | Für Positionen, die jetzt Bedingung 4 verletzen: wie Storno | wie oben |
| `woocommerce_order_status_changed` nach `on-hold` oder `pending` aus einem bezahlten Status | Offene Positionen bleiben `pending`, Zugang und Erinnerung sind gesperrt, solange 2.3 nicht erfüllt ist; keine Statusänderung | – |

- Mehrfach ausgelöste Hooks (Zahlungsanbieter mit Webhook **und** Rücksprung, mehrere Statuswechsel) DÜRFEN NICHT zu mehreren Mails oder Zugängen führen. Der Übergang `not_requested → pending` erfolgt per Vergleich-und-Setzen auf den Positionsdaten (gespeichert über `$item->save()`). Der Queue-Auftrag ist über seinen Schlüssel eindeutig (UNIQUE `idempotency_key` in `fc_jobs`).
- Die Verarbeitung MUSS nach dem Speichern des Auftrags durch WooCommerce laufen. Die Hooks oben feuern danach. Das Modul schreibt nie direkt in Bestelltabellen oder Order-Meta, sondern nur über CRUD (P8-68).

### 2.5 Positionsstatus und Übergänge

| Von → nach | Auslöser | Folge |
|---|---|---|
| (neu) → `not_requested` | Zuordnung eingefroren (2.2) | – |
| `not_requested` → `pending` | Berechtigung erstmals erfüllt (2.4) | Käufer-Mail (Queue), Zugang sichtbar |
| `pending` / `requested_again` → `completed` | Antwort angenommen (`submit_check` bestanden, Entry gespeichert) | `entry_id` an der Position; Hinweis im Auftrag |
| `completed` → `requested_again` | Shopmitarbeiter fordert Korrektur an (Recht `edit_shop_orders` **und** `form_captain_read_entries`) | neue Mail, `request_count + 1`; der alte Entry bleibt, der neue ersetzt die Referenz, der alte wird im Auftrag als „ersetzt“ verlinkt (P8-69) |
| `pending` / `requested_again` → `revoked` | Storno, Vollerstattung, fehlgeschlagen (2.4) | Zugang gesperrt, offene Aufträge storniert |
| `completed` → `completed` | Storno oder Erstattung nach Abschluss | keine Änderung; der Entry folgt Retention und Löschung von Form Captain (P8-72) |
| beliebig → Referenz verwaist | Entry gelöscht (Retention, Erasure, manuell; `form_captain_entry_deleted_v1`) | `entry_id` entfernt, Status `completed` bleibt mit Vermerk „Antwort gelöscht“; keine neue Anforderung |

„Vor Bearbeitung erforderlich“ ist **nur ein Hinweis** (P8-70). Es gibt keinen Übergang, der Bestell- oder Zahlungsstatus, Downloads oder Versand ändert.

### 2.6 Daten an der Position (WooCommerce, über CRUD)

Ein Meta-Eintrag `_fc_questionnaire` (versteckt, mit Unterstrich) an der Bestellposition:

```json
{ "v": 1, "form_id": 12, "version_id": 34, "required": true,
  "status": "pending", "entry_id": null, "request_count": 1,
  "frozen_via": "checkout", "updated_gmt": "2026-11-16T10:00:00Z" }
```

Keine Antworten, Feldwerte, Tokens oder personenbezogenen Daten. Gast-Tokens liegen nur als Hash in einer eigenen Tabelle des Moduls (P8-64).

---

## 3. Unterstützte Felder und Kontext-Allowlist (P8-63)

### 3.1 Formularfelder

| Feldtyp | Im Käuferfragebogen | Regel |
|---|---|---|
| Text, Absatz, E-Mail, Telefon, Zahl, Auswahl, Optionsfeld, Checkbox, Datum, Überschrift/HTML-Hinweis | ja | wie im Formular konfiguriert |
| Einwilligung | ja | Einwilligungsnachweis wie bei jeder Einsendung (`consent_json`) |
| Versteckter Wert | nur fester Wert aus dem Formular | DARF NICHT aus der URL oder aus Bestelldaten vorbelegt werden; zählt nie als Bestellbezug |
| Pro-Feldtypen (Upload, Mehrschritt) | ja, wenn das jeweilige Pro-Recht aktiv ist | Uploads folgen P7-08 und P7-09; Mehrschritt-Fortsetzungslinks gelten nur zusammen mit gültigem Zugang |

- Pflichtfelder, Validierung und Bedingungen gelten wie bei jeder Einsendung, serverseitig geprüft gegen die **eingefrorene** Version.
- Ein Formular wird für den Fragebogen nur angeboten, wenn es veröffentlicht ist und den Zweck `woo.order_item_questionnaire` in `accepted_context_purposes` erlaubt. Das Modul setzt diese Einstellung beim Zuordnen und weist darauf hin.

### 3.2 Kontext: was serverseitig abgeleitet wird

| Wert | Herkunft | Gespeichert |
|---|---|---|
| `order_id`, `item_id` | aus dem geprüften Zugang (Konto-Eigentümerschaft oder Gast-Token), nie aus Formulardaten | `fc_entries.context_json`: `{"purpose":"woo.order_item_questionnaire","ref_type":"order_item","ref_id":"{order_id}:{item_id}","verified":true}` |
| Produkt-/Variations-ID, Formularversion | aus `_fc_questionnaire` und der Position | nicht im Entry; beim Anzeigen aus dem Auftrag gelesen |
| Produktname, Bestellnummer zur Anzeige | beim Rendern aus WooCommerce | nein (nur Anzeige) |
| Kunden-Benutzer-ID | `$order->get_customer_id()`; Prüfung „aktueller Benutzer = Kunde“ für den Kontozugang | `fc_entries.user_id` nur bei eingeloggtem Kunden |

- Der rohe `_fc_ctx` und das Gast-Token werden nie gespeichert oder protokolliert.
- Versteckte Felder, Query-Parameter oder Cookies mit Bestell- oder Positionsnummern werden ignoriert (suite-kontext.md 4.4).

### 3.3 Was nie übertragen oder gespeichert wird

Preise, Steuern, Zahlungsart und Zahlungsdaten, Liefer- und Rechnungsadresse, Bestellnotizen, Kunden-E-Mail und Telefon (es sei denn, der Käufer gibt sie selbst in ein Formularfeld ein), andere Positionen des Auftrags, WooCommerce-Sitzungsdaten.

### 3.4 Vorbelegung

- Standard: keine Vorbelegung.
- Optional DARF der Verkäufer je Zuordnung erlauben, Name und E-Mail der Rechnungsadresse in genau zugeordnete, **sichtbare** Felder vorzubelegen. Der Käufer sieht und ändert die Werte vor dem Absenden.
- Keine Vorbelegung versteckter Felder, keine anderen Bestelldaten.

### 3.5 Ereignisse und Weitergabe

- Das Modul ergänzt `form_captain_event_value_allowlist_v1` nicht. Feldwerte aus Fragebögen gehen nur an Connectoren, die der Betreiber ausdrücklich konfiguriert.
- Für eigene Ereignisse (`form_captain_pro_woo_questionnaire_*_v1`) gilt dieselbe Allowlist-Regel: IDs und Status, keine Werte.

---

## 4. Abnahme dieser Spezifikation

Die Umsetzung gilt als vertragstreu, wenn die Fälle aus P8-74 und P8-87 (Regressionstests) grün sind. Mindestens:

- Gratisbestellung, doppelt ausgelöste Zahlung, Teil- und Vollerstattung;
- Änderung des Produktfragebogens nach dem Kauf, Archivieren des Formulars;
- Erneuerung des Gastlinks, doppelte Erinnerung;
- Pro-Lizenzende mit offenen Fragebögen;
- HPOS an und aus, klassischer und Block-Checkout.

## 5. Datenschutz

- Retention, Export und Löschung der Antworten folgen Form Captain. Der Auftrag behält höchstens den Status und eine verwaiste Referenz (2.5).
- Die Auskunft (Exporter) von Form Captain enthält den Bestellbezug (`order_id:item_id`), aber keine Bestelldaten.
- Anonymisieren eines Auftrags durch WooCommerce (Privacy-Eraser für Bestellungen) löscht die Entries nicht automatisch. Der Systemstatus des Moduls zeigt solche Positionen, damit der Betreiber sie nach seiner Regel behandelt (siehe offene Frage 6.3).

## 6. Vor der Umsetzung an einer echten WooCommerce-Installation zu prüfen

**Ergebnis vom 16.11.2026** (WooCommerce 11.3.0, WordPress 7.1.4; captain-pro `docs/FORM-PRO.md`): 6.1 ja, der Hook feuert auch beim Store-API-Checkout (Position mit `frozen_via: checkout`); 6.2 eine 0-Euro-Bestellung mit `payment_complete()` steht auf `completed` und ist berechtigt; 6.3 bleibt beim Inhaber (umgesetzt ist der Vorschlag: keine automatische Löschung).

1. **Einfrier-Hook im Block-Checkout:** Feuert `woocommerce_checkout_create_order_line_item` auch beim Store-API-Checkout (Cart/Checkout Blocks) für jede Position? Wenn nicht, gilt der Fallback 2.2 Nr. 2 (Einfrieren bei der ersten Berechtigungsprüfung), mit Test in P8-74.
2. **Gratisbestellungen:** Welchen Status setzt die aktuelle WooCommerce-Version nach einem 0-Euro-Checkout (`processing` oder `completed`)? Prüfung über `is_paid()` und den Fall in 2.3 Nr. 2.
3. **Entscheidung für den Inhaber:** Sollen Entries zu einem Auftrag gelöscht werden, wenn WooCommerce den Auftrag über den Privacy-Eraser anonymisiert? Vorschlag: nein, Form-Captain-Retention bleibt maßgeblich; der Hinweis im Systemstatus genügt.
