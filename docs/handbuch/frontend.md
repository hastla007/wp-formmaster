# Formulare einbetten (Block, Shortcode, Caching, SEO)

## Einbettung

**Block:** Im Block-Editor den Block „Form Captain“ einfügen und in der Seitenleiste ein veröffentlichtes Formular wählen. Die Vorschau im Editor zeigt das Formular so, wie Besucher es sehen. Ist das Formular inzwischen pausiert, archiviert oder gelöscht, erscheint im Editor ein gelber Hinweis.

**Shortcode:** `[form_captain id="a1b2c3d4e5f6g7h8"]` – als `id` die öffentliche ID (16 Zeichen, siehe Formularliste) oder die numerische ID. Optional `title="yes"` zeigt den Formulartitel als Überschrift. Ungültige IDs erzeugen für Besucher keine Ausgabe; Formular-Verwalter sehen einen Hinweis.

Block und Shortcode nutzen denselben Renderer; die Ausgabe ist identisch.

| Formularstatus | Besucher sehen | Formular-Verwalter sehen zusätzlich |
|---|---|---|
| veröffentlicht | Formular | – |
| pausiert | Pausenmeldung des Formulars | Hinweis „pausiert“ |
| Entwurf / archiviert / gelöscht | nichts | Hinweis mit Grund |

## Barrierefreiheit

Jedes Feld hat ein sichtbares Label, Pflichtfelder sind mit `*` markiert (plus `aria-required`), Hilfetexte und Fehlermeldungen sind per `aria-describedby` verknüpft. Nach dem Absenden erscheint oben eine Fehlerübersicht mit Sprunglinks (wird fokussiert und angesagt) bzw. eine Erfolgsmeldung. Einwilligungsfelder sind nie vorausgewählt.

## Sprache und Anrede

Form Captain bringt Deutsch in vier Fassungen mit; welche gilt, richtet sich nach der Sprache der Website (*Einstellungen → Allgemein → Sprache der Website*):

| WordPress-Sprache | Anrede | Besonderheit |
|---|---|---|
| Deutsch (`de_DE`) | „du“ | wie das Standard-Deutsch von WordPress |
| Deutsch (Sie) (`de_DE_formal`) | „Sie“ | |
| Deutsch (Schweiz) (`de_CH`) | „Sie“ | „ss“ statt „ß“ |
| Deutsch (Österreich) (`de_AT`) | „du“ | wie `de_DE` |

Die Anrede gilt für alles, was Form Captain selbst schreibt: Verwaltung, Fehlermeldungen im Formular, Status des Spamschutzes, Double-Opt-in-Mail und -Meldungen. Texte, die du im Formular selbst einträgst (Beschriftungen, Hilfetexte, Erfolgsmeldung, E-Mail-Texte), erscheinen unverändert – schreibe sie in derselben Anrede wie die Website.

## Ohne JavaScript

Das Formular funktioniert auch ohne JavaScript: Es wird an `wp-admin/admin-post.php` gesendet, danach leitet WordPress zurück auf die Seite (Parameter `fc_r` – nur ein zufälliger Schlüssel, keine Daten). Bei Fehlern werden Meldungen und Eingaben (ohne sensible Felder und Einwilligung) für 10 Minuten wieder angezeigt. Ist der eingebaute Proof of Work aktiv, kann der Browser ohne JavaScript nicht rechnen: Die Einsendung wird trotzdem angenommen, aber strenger begrenzt und im Eintrag als „Spamprüfung ohne Proof of Work“ markiert (siehe [Einstellungen](einstellungen.md)).

## Caching

Die Formularausgabe enthält **keinen WordPress-Nonce** und ist damit für Seiten-Caches geeignet. Das eingebettete Submission-Token ist 2 Stunden gültig; das Frontend-Skript holt beim ersten Fokus automatisch ein frisches Token, wenn das gecachte älter als 30 Minuten ist (`GET /wp-json/form-captain/v1/forms/{id}/token`, nie gecacht).

Empfehlungen:

- Die REST-Routen `/wp-json/form-captain/v1/` und `wp-admin/admin-post.php` nicht cachen (die meisten Cache-Plugins tun das ohnehin nicht).
- Seiten-URLs mit dem Parameter `fc_r` vom Cache ausnehmen.
- JavaScript-Optimierung (Zusammenfassen, Verzögern) ist möglich; das Skript `form-captain-frontend` hat keine Abhängigkeiten.

CSS und JavaScript werden nur auf Seiten geladen, die ein Formular enthalten. Unter *Einstellungen → Darstellung* übernimmt „Theme erben“ Schrift und Farben des Themes; „Basis“ ergänzt eine schlichte Grundgestaltung mit Akzentfarbe.

## Spamschutz

Standardmäßig aktiv und ohne Drittanbieter: unsichtbares Honeypot-Feld, Mindest-Ausfüllzeit (3 s), Rate-Limit (5 Einsendungen je 10 Minuten, Formular und IP-Hash – die IP selbst wird nie gespeichert) und optional eine Blockliste (*Einstellungen → Spamschutz*, ein Begriff je Zeile; `@domain.tld` sperrt E-Mail-Domains). Erkannter Spam wird mit Status „Spam“ gespeichert, löst aber keine Mails oder Integrationen aus.

## SEO und Yoast SEO

Form Captain verändert **keine** SEO-Daten: Seitentitel, Meta-Beschreibung, Canonical, Robots-Regeln, Open Graph, Sitemaps und strukturierte Daten pflegen Sie wie gewohnt in WordPress bzw. Yoast SEO. Formulare erzeugen keine eigenen URLs, keine Sitemap-Einträge und kein eigenes JSON-LD – sie sind eingebetteter Seiteninhalt.

Ein Formular ersetzt keinen hilfreichen Seiteninhalt: Erklären Sie auf der Seite, worum es geht und was nach dem Absenden passiert. Ist Yoast SEO aktiv, berücksichtigt die Inhaltsanalyse beim Shortcode nur die sichtbaren Feldbeschriftungen und Hilfetexte – nie eingegebene Werte. Ohne Yoast funktioniert alles unverändert.

## Für Entwickler

Suite-Plugins können einen signierten Kontext übergeben (`form_captain_sign_context_v1()`, Shortcode-Attribut `context` nur aus PHP bzw. Filter `form_captain_render_context_v1`). Details: `docs/spec/suite-kontext.md`.
