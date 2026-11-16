# Übersetzungen (Textdomain `form-captain`)

| Datei | Inhalt |
|---|---|
| `form-captain.pot` | Vorlage, aus dem Quelltext erzeugt (Quelltexte Englisch) |
| `form-captain-de_DE.po` / `.mo` | Deutsch (Sie-Form) |
| `form-captain-de_AT.po` / `.mo` | Deutsch (Österreich) – derzeit inhaltlich identisch mit de_DE, nur der Header `Language: de_AT` unterscheidet sich (österreichische Varianten wie „Jänner“ nur, wo solche Begriffe vorkommen) |
| `form-captain-<locale>-<md5>.json` | JavaScript-Übersetzungen (Jed-Format) für `wp_set_script_translations()` |

PHP lädt die `.mo`-Dateien über `load_plugin_textdomain()`, JavaScript die JSON-Dateien über
`wp_set_script_translations( $handle, 'form-captain', FORM_CAPTAIN_DIR . 'languages' )`
(gesetzt für `form-captain-editor`, `form-captain-admin-settings` und den Block-Editor-Skript-Handle).

## Workflow

`WP` steht im Folgenden für `php -d memory_limit=1G /tmp/claude-0/tools/i18n/vendor/wp-cli/wp-cli/php/boot-fs.php`
(oder eine installierte WP-CLI, `wp`). Alle Befehle laufen im Plugin-Verzeichnis.

1. **POT neu erzeugen** (nach Textänderungen im Code):

   ```sh
   $WP i18n make-pot . languages/form-captain.pot --domain=form-captain --exclude=assets/build,vendor,node_modules,tests --allow-root
   ```

   `assets/build` wird ausgeschlossen, damit die Quellreferenzen auf `assets/src/*.js` zeigen.

   Ohne Zugriff auf `develop.svn.wordpress.org` bricht make-pot beim Lesen von `block.json` ab. Dann mit
   `--skip-block-json` (und `--exclude=…,build,dist,tools`) erzeugen und die fünf Block-Texte aus
   `blocks/form/block.json` (Kontexte `block title`, `block description`, `block keyword`) wieder oben in POT und PO
   einfügen; die Übersetzungen dazu stehen in der PO-Datei unter `#: blocks/form/block.json`.

2. **PO-Dateien aktualisieren und übersetzen:**

   ```sh
   msgmerge --update --backup=none languages/form-captain-de_DE.po languages/form-captain.pot
   ```

   Neue/geänderte Einträge übersetzen (Platzhalter `%s`, `%1$s`, `%d`, `{form_title}` und HTML-Tags exakt erhalten,
   Pluralformen `msgstr[0]`/`msgstr[1]` füllen, Markierung `fuzzy` entfernen). Terminologie: `docs/spec/datenschutz.md`
   (Texte DS-T-*), `docs/handbuch/*.md`; „submission“ = Einsendung, „entry“ = Eintrag, „Form Captain“ wird nicht übersetzt.
   Danach `form-captain-de_AT.po` aus de_DE übernehmen (Header `Language: de_AT` beibehalten).

3. **Prüfen:**

   ```sh
   msgfmt --check -o /dev/null languages/form-captain-de_DE.po
   msgattrib --untranslated languages/form-captain-de_DE.po   # muss leer sein
   msgattrib --only-fuzzy   languages/form-captain-de_DE.po   # muss leer sein
   ```

4. **MO kompilieren:**

   ```sh
   $WP i18n make-mo languages --allow-root
   ```

5. **JSON für JavaScript erzeugen:**

   WordPress sucht `form-captain-<locale>-<md5>.json`, wobei `<md5>` der MD5-Hash des Skriptpfads relativ zum
   Plugin-Verzeichnis ist. `Asset::url()` liefert bevorzugt `assets/build/<name>.js`, die POT-Referenzen zeigen auf
   `assets/src/<name>.js`. Deshalb wird mit `--use-map` für beide Pfade je eine JSON-Datei erzeugt:

   ```sh
   $WP i18n make-json languages --allow-root '--use-map={
     "assets/src/editor.js":["assets/src/editor.js","assets/build/editor.js"],
     "assets/src/block-editor.js":["assets/src/block-editor.js","assets/build/block-editor.js"],
     "assets/src/admin-settings.js":["assets/src/admin-settings.js","assets/build/admin-settings.js"]
   }'
   ```

   Die PO-Dateien werden dabei nicht verändert (diese WP-CLI-Version kennt keine `--purge`/`--no-purge`-Option
   und entfernt keine JS-Strings aus der PO-Datei). Ergebnis je Locale:

   | Skript | md5 | JSON |
   |---|---|---|
   | `assets/build/editor.js` | `3516157eb7baab25ffc8992a5ca346b0` | `form-captain-de_DE-3516157eb7baab25ffc8992a5ca346b0.json` |
   | `assets/build/block-editor.js` | `9d09560ffe828a5f2d588abef2a22bfa` | `form-captain-de_DE-9d09560ffe828a5f2d588abef2a22bfa.json` |
   | `assets/build/admin-settings.js` | `53e1e53b83764a9cf1408ad60010c7c5` | `form-captain-de_DE-53e1e53b83764a9cf1408ad60010c7c5.json` |
   | `assets/src/editor.js` | `e37b3836d3ad52129031da6c0ab50d3e` | `form-captain-de_DE-e37b3836d3ad52129031da6c0ab50d3e.json` |
   | `assets/src/block-editor.js` | `364c7be5ede9b74fc327750e9b7b4241` | `form-captain-de_DE-364c7be5ede9b74fc327750e9b7b4241.json` |
   | `assets/src/admin-settings.js` | `c48cb7f605ed26d60c664af1b1f8f4e6` | `form-captain-de_DE-c48cb7f605ed26d60c664af1b1f8f4e6.json` |

   Hash prüfen: `printf %s assets/build/editor.js | md5sum`. Kommt ein neues Skript mit `wp-i18n` hinzu, den Eintrag in
   der Map ergänzen und `wp_set_script_translations()` für dessen Handle aufrufen.
   `frontend.js` und `forms-admin.js` verwenden kein `wp-i18n`; ihre Texte kommen aus PHP.
