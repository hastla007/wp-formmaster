# ADR 0002 – Admin-UI und Asset-Build (P0-16, P1-13)

**Entscheidung**

- Listen, Detailseiten, Einstellungen und Dashboard serverseitig gerendert (`WP_List_Table`, Settings API) – robust, barrierearm, ohne Build nötig.
- Formular-Editor als JavaScript-App auf Basis der WordPress-Pakete `wp.element`, `wp.components`, `wp.i18n`, `wp.apiFetch` (von WordPress geliefert, nicht gebündelt). Speichern über Admin-REST (`form-captain/v1/admin/forms`).
- Gutenberg-Block `form-captain/form` mit `block.json` (apiVersion 3), dynamischem Render-Callback (serverseitig) und Editor-Skript.
- Frontend-JS (Progressive Enhancement) als Vanilla-JS ohne Abhängigkeiten, nur geladen, wenn ein Formular auf der Seite gerendert wird.
- Build: Quellen in `assets/src/` und `blocks/*/src`, Ausgabe in `assets/build/`. Werkzeug: `esbuild` (eine Dev-Abhängigkeit, schnell, reproduzierbar). Quellen sind ES2019 ohne JSX (`wp.element.createElement` via Alias `el`), damit auch ohne Build lesbar. `npm run build` erzeugt minifizierte Dateien und `assets/build/manifest.json` mit Inhalts-Hashes als Versionsparameter.
- Build-Ausgabe wird committet, damit das Plugin direkt aus dem Repository lauffähig ist; CI prüft, dass `npm run build` keine Änderungen erzeugt.
- CSS: minimale Basisstile mit CSS Custom Properties, Modus `inherit` lädt nur Layout-/Fehlerstile.
