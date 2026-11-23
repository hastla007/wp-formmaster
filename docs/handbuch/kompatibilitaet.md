# Kompatibilität (P6-03, P6-04)

Automatisiert abgedeckt durch `tests/integration/Compat/WooCommerceIsolationTest.php` und `tests/integration/Compat/MultipleFormsTest.php`. Punkte, die eine echte Fremd-Plugin-Installation brauchen, stehen als Checkliste am Ende und werden vor jedem Release manuell auf einer Staging-Seite geprüft.

## WooCommerce (P6-03)

Form Captain Free ist **kein** WooCommerce-Plugin und greift nicht in den Shop ein:

- **Keine Hooks** auf `woocommerce_*`- oder `wc_*`-Actions/Filter. Der Test durchsucht nach dem Plugin-Start alle registrierten Hooks (`$wp_filter`) nach Callbacks aus Form-Captain-Klassen oder -Dateien.
- **Keine WooCommerce-APIs:** keine Aufrufe von `wc_*()`, `WC()`, `WC_*`-Klassen oder `Automattic\WooCommerce\…` (statischer Quelltext-Test über `src/`, `includes/`, `blocks/`, `templates/`, `form-captain.php`, `uninstall.php`).
- **Keine WooCommerce-Tabellen:** weder klassische Bestellungen (`shop_order`-Beiträge, `woocommerce_order_items`) noch HPOS-Tabellen (`wc_orders`, `wc_order_*`), Lookup- oder Session-Tabellen. Zusätzlich werden alle SQL-Abfragen eines Render-, Einsende- und Job-Durchlaufs mitgeschnitten und geprüft.
- **Warenkorb, klassischer Checkout und Checkout-Blocks:** Free registriert keine Felder, Validierungen, Skripte oder Store-API-Erweiterungen dafür. Form-Captain-Assets werden nur auf Seiten geladen, die ein Formular enthalten.
- **HPOS:** Da Free keine Bestelldaten liest oder schreibt, ist keine HPOS-Kompatibilitätserklärung nötig; Free blockiert die Aktivierung von HPOS nicht.
- **Ohne WooCommerce** funktioniert alles (Testumgebung läuft ohne WooCommerce). Einzige Erwähnung im Code ist die informative Plugin-Erkennung im Dashboard (`src/Admin/DashboardPage.php`), die nichts aktiviert.

Das Pro-Fragebogenmodul für WooCommerce wird separat in Phase 8 und der WooCommerce-Matrix abgenommen.

## Editoren und mehrere Formulare (P6-04)

| Fall | Ergebnis (automatisiert) |
|---|---|
| Classic Editor: Shortcode `[form_captain id="…"]` im Beitragsinhalt (`the_content`, inkl. `wpautop`) | wird ersetzt, kein `<p>` um das Formular, absendbar |
| Block-Editor: Block `form-captain/form` zwischen anderen Blöcken (`do_blocks`) | wird gerendert, Blockkommentar entfernt, absendbar |
| Drei verschiedene Formulare plus ein Formular doppelt auf einer Seite (Shortcode + Block gemischt) | alle Element-IDs eindeutig, jedes `label[for]` zeigt auf ein Feld, jede Instanz hat ein eigenes Token, alle vier Instanzen einzeln absendbar |
| Token eines Formulars an ein anderes Formular gesendet | abgelehnt (`invalid_token`) |

## Seiten-Caching

Formular-HTML enthält ein signiertes Token mit Ausstellzeit. Für gecachte Seiten gilt:

- Die REST-Antworten (`/token`, `/submissions`) senden `Cache-Control: no-store` und werden nicht gecacht.
- `frontend.js` holt beim ersten Fokus ein frisches Token, wenn das Token älter als 30 Minuten ist, und vor dem Absenden, wenn es älter als 115 Minuten ist.
- **Abgelaufenes Token aus dem Cache** (älter als `token_ttl`, 2 Stunden): Einsendung wird mit `invalid_token` abgelehnt, ein frisch geholtes Token funktioniert (Test `test_page_cache_with_stale_token_recovers_via_token_endpoint`).
- **Gleiches gültiges Token für mehrere Besucher:** Der Server behandelt die zweite Einsendung mit demselben Token als Wiederholung (`duplicate_request`, HTTP 200) – es entsteht kein zweiter Entry, keine zweite Mail, Daten werden nie überschrieben. **Achtung:** Ist die gecachte Seite jünger als 30 Minuten, verwendet `frontend.js` das gecachte Token weiter; die Einsendung eines zweiten Besuchers würde dann als Wiederholung verworfen. Empfehlung bis zur Anpassung von `frontend.js`: Seiten mit Formular vom Seiten-Cache ausnehmen oder die Cache-Lebensdauer dieser Seiten unter wenigen Minuten halten.
- Ohne JavaScript wird immer das Token aus dem HTML verwendet; gecachte Seiten ohne JS sind daher nur eingeschränkt nutzbar.

## Checkliste für manuelle Prüfung (vor jedem Release)

### Caching-Plugins (z. B. WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed Cache, Cloudflare APO)

- [ ] Seite mit Formular wird gecacht; zwei Besucher (verschiedene Browser) senden nacheinander ab → **zwei** Entries.
- [ ] Seite älter als 2 Stunden im Cache → Absenden funktioniert (Token-Refresh).
- [ ] `/wp-json/form-captain/v1/…` wird vom Cache/CDN **nicht** gecacht (Header `no-store` respektiert, ggf. Ausnahme-Regel anlegen).
- [ ] JS-Optimierung (Minify/Combine/Defer/„Delay JS“) bricht `frontend.js` nicht: Validierung, Fehlermeldungen, Bedingungen und Erfolgsmeldung funktionieren; sonst Handle `form-captain-frontend` von der Optimierung ausnehmen.
- [ ] No-JS-Fallback (`admin-post.php`) funktioniert bei aktivem Cache.

### Security-Plugins (z. B. Wordfence, iThemes/Solid Security, All In One WP Security, Sucuri)

- [ ] REST-API für nicht angemeldete Besucher nicht blockiert, mindestens die Routen `form-captain/v1/forms/*/token` und `…/submissions` erlaubt.
- [ ] WAF blockiert normale Einsendungen nicht (Text mit Sonderzeichen, URLs, Umlaute); bei Blockade Regel-Ausnahme für die Submission-Route.
- [ ] Hinter Proxy/CDN: richtige Client-IP für Rate-Limits. Form Captain nutzt `REMOTE_ADDR`; liefert der Proxy die echte IP nur per Header, wähle unter *Einstellungen → Spamschutz → Besucheradresse* `CF-Connecting-IP`, `X-Forwarded-For` (erste Adresse) oder `X-Real-IP` – nur für Header, die der eigene Proxy zuverlässig überschreibt. Alternativ setzt der Server (z. B. `mod_remoteip`) die Adresse oder ein Snippet über den Filter `form_captain_client_ip` (der Filter gewinnt).
- [ ] `admin-post.php` ist erreichbar (No-JS-Fallback, Export, Entry-Aktionen).
- [ ] Login-/Admin-Härtung beeinträchtigt die Admin-Seiten (Editor, Entries, Export-Download) nicht.

### SMTP-/Mail-Plugins (z. B. WP Mail SMTP, FluentSMTP, Post SMTP)

- [ ] Admin-Benachrichtigung und Bestätigung kommen an; Absender aus den Form-Captain-Einstellungen wird übernommen oder bewusst vom SMTP-Plugin erzwungen.
- [ ] Testmail unter Form Captain → Einstellungen → E-Mail funktioniert.
- [ ] HTML-Mail mit Textalternative wird korrekt dargestellt; Reply-To aus dem Formularfeld bleibt erhalten.
- [ ] Bei SMTP-Fehler: Job wird wiederholt und erscheint nach Dead-letter in der Job-Liste; Hook `form_captain_notification_failed_v1` feuert.
- [ ] Mail-Logs des SMTP-Plugins enthalten keine sensiblen Felder (diese werden von Form Captain nie verschickt).

### Editoren und Themes

- [ ] Classic-Editor-Plugin aktiv: Shortcode einfügen, Vorschau, Veröffentlichen.
- [ ] Block-Editor: Block einfügen, Formular wählen, Vorschau im Editor, Full-Site-Editing-Template.
- [ ] Zwei Formulare auf einer Seite in einem Standard- und einem Block-Theme: Fokus, Fehlerzusammenfassung und Erfolgsmeldung beziehen sich nur auf das jeweilige Formular.
- [ ] Page-Builder (z. B. Elementor Shortcode-Widget) rendert den Shortcode.
