# WordPress.org-Einreichung (P6-12 – Vorbereitung)

**Gilt für:** Kernplugin `form-captain` (Free). `form-captain-pro` wird **nicht** auf WordPress.org veröffentlicht.
**Maßgebliche Quelle:** [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/), ergänzend [Plugin-Readme](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/), [Plugin-Assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/), [SVN-Nutzung](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/), [Plugin-Check](https://wordpress.org/plugins/plugin-check/).
**Release-Ablauf und SVN-Befehle:** [`RELEASE.md`](RELEASE.md) Abschnitt 5.

## 1. Offene Entscheidungen

**Grundsatzentscheidung 16.11.2026:** Einreichung auf WordPress.org erfolgt **nach** einem externen Sicherheitsreview (docs/ENTSCHEIDUNGEN.md #10; Empfehlungen in docs/SECURITY-REVIEW.md Kapitel 5).

| # | Entscheidung | Optionen / Hinweis | Verantwortlich | Status |
|---|---|---|---|---|
| E-1 | WordPress.org-Konto für Einreichung und SVN-Commit | Organisationskonto vs. persönliches Konto; 2FA Pflicht; mindestens zwei Committer | Produktverantwortung | **offen** |
| E-2 | Plugin-Slug `form-captain` verfügbar? | Vor Einreichung prüfen (`https://wordpress.org/plugins/form-captain/` und Suche nach ähnlichen Namen). Der Slug wird bei Freigabe aus dem `Plugin Name` abgeleitet und ist danach nicht änderbar; ggf. in der Einreichung Wunsch-Slug angeben. Fallback-Slug festlegen. | Produktverantwortung | **offen** |
| E-3 | Markenprüfung „Form Captain“ | Name darf keine fremde Marke verletzen und nicht mit „WordPress“/„WP“ beginnen (Richtlinie 17); Captain-Suite-Markenrechte klären | Produktverantwortung | **offen** |
| E-4 | Supportkanal | WordPress.org-Forum (Pflicht, aktiv zu betreuen) + optional eigener Kanal für Pro; siehe `BETRIEB.md` 2.1 | Produktverantwortung | **entschieden:** Forum für Free, Ticket per E-Mail für Pro |
| E-5 | `Contributors:` in readme.txt | WordPress.org-Benutzernamen aus E-1 | Produktverantwortung | **offen** |
| E-6 | `Plugin URI` / `Author URI` | derzeit GitHub-Repository; eigene Produktseite? Pro-Hinweis darf auf eigene Seite verlinken | Produktverantwortung | **offen** |
| E-7 | Donate-Link | ja/nein | Produktverantwortung | offen |
| E-8 | Sicherheitskontakt im readme | `[SECURITY-KONTAKT]` aus `BETRIEB.md` 2.6 | Produktverantwortung | **entschieden:** eigene `security@…`-Adresse – Adresse noch einzutragen |

## 2. Richtlinien-Checkliste

Nummern beziehen sich auf die Detailed Plugin Guidelines.

| Nr. | Richtlinie (Kurzfassung) | Umsetzung in Form Captain | Prüfung |
|---|---|---|---|
| 1 | GPL-kompatible Lizenz für Code und Assets | `License: GPL-2.0-or-later`; keine Fremdbibliotheken im Release; eigene Assets (Icons/Banner) GPL oder kompatibel | `bin/check-release.sh` Header; Asset-Herkunft dokumentiert |
| 2 | Entwickler verantwortlich für alle Dateien | Release-ZIP per Allowlist (`bin/build-release.sh`), keine Fremdkopien | Dateiliste im Release-Ticket |
| 3 | Stabile Version im Verzeichnis | Tags nie ändern, `Stable tag` aktuell | `RELEASE.md` 5 |
| 4 | Lesbarer Code, keine Verschleierung | Quellen `assets/src` werden mit ausgeliefert, Build reproduzierbar, Build-Werkzeug in readme dokumentiert | Build-Hinweis in readme (Abschnitt 3) |
| 5 | Keine Trialware, keine gesperrten Funktionen | Free hat keine Lizenzprüfung, keinen Lizenzclient, keine Limits; Pro ist separates Add-on (konzept.md 1, 7). Pro-Hinweiszeilen in den Einstellungen (S1, 23.11.2026) sind reiner Text ohne Eingabefeld und ohne Funktionscode – im Free steckt nichts, was freigeschaltet würde; echte Felder meldet nur das Add-on über `form_captain_settings_fields_v1` an | Kein Lizenzclient und keine Rechteschlüssel-Namen im Free; die einzigen bekannten Stellen mit Lizenzbezug sind erklärt: `requires.entitlement` einer Formularaktion (Vertrag v1, Filter `form_captain_entitlement_granted`, Standard „nicht gewährt“, der Kern prüft keine Lizenz und kennt keine Schlüssel – ein allgemeines Tor, keine Lizenzprüfung; mit S1 vereinbar nach Entscheidung 22 (6), SET-FC-P6-02), die Erkennung des eigenständigen Plugins „License Captain“ im Dashboard und zwei Kommentare. Wächtertest `SettingsHintsTest` prüft das über den ganzen Kern (`src/`, `includes/`, `templates/`, `blocks/`, `assets/src/`): keine Schlüsselnamen, kein Lizenzclient, keine neue Stelle mit Lizenzbezug; Hinweiszeilen ohne Eingabeelement |
| 6 | SaaS erlaubt, wenn echter Dienst | Kernbetrieb ohne externen Dienst; Connectoren nur opt-in | – |
| 7 | Kein Tracking ohne Zustimmung | keine Telemetrie (P9-04); keine externen Requests ohne Konfiguration; Captcha-Provider opt-in | Netzwerkmitschnitt bei Installation/Einrichtung = keine externen Requests |
| 8 | Kein Ausführen von Fremdcode, kein eigener Updater | Free hat keinen Updater; Updates nur über WordPress.org | Code-Suche `pre_set_site_transient_update_plugins` im Free = 0 |
| 9 | Nichts Illegales/Unethisches | keine gefälschten Bewertungen, keine Keyword-Stuffing-Tags | readme-Review |
| 10 | Keine Links/Credits im Frontend ohne Opt-in | kein „Powered by“ im Formular-Output | E2E-Prüfung Frontend-Markup |
| 11 | Admin-Dashboard nicht kapern | keine globalen Upgrade-Banner (AC-019); Pro-Hinweise sparsam, kontextbezogen, wegklickbar (P6-09); Pro-Hinweiszeilen nur am Ende der Reiter der eigenen Einstellungsseite, grau, ohne Pop-up oder Zähler, abschaltbar über „Pro-Hinweise zeigen“ (`general.show_pro_hints`); keine Hinweise außerhalb der Form-Captain-Seiten außer Fehlern | manueller Review aller Admin-Seiten; `SettingsHintsTest` |
| 12 | readme ohne Spam | max. 5 Tags, keine Konkurrenznamen, keine Keyword-Listen | Abschnitt 3 |
| 13 | Standard-WordPress-Bibliotheken nutzen | Admin-UI mit `wp.element`/`wp.components` (ADR 0002), kein gebündeltes jQuery/React | Build-Manifest |
| 14 | Häufige Commits vermeiden | SVN nur für Releases, nicht als Entwicklungs-Repository | – |
| 15 | Versionsnummer je Release erhöhen | `RELEASE.md` 1 | – |
| 16 | Vollständiges Plugin bei Einreichung | Free v1.0 vollständig (nach Phase 6) | Gesamtabnahme |
| 17 | Marken und Projekte respektieren | E-3 | – |
| 18 | Verzeichnis kann Plugins entfernen/ändern | Supportpflicht ernst nehmen | – |

**Zusätzliche technische Prüfungen**
- [x] Plugin Check (offizielles Prüf-Plugin) auf dem Release-ZIP ohne Fehler; Warnungen begründet im Release-Ticket. *Lauf 16.11.2026 mit Plugin Check 2.2.0 auf `form-captain-1.0.0.zip` (Version im Header auf 1.0.0 gesetzt, WP 6.8.3): **0 Fehler**, 68 Warnungen. Behoben: fehlender `ABSPATH`-Schutz in drei Dateien (deshalb steht `includes/functions.php` nicht mehr im Composer-Autoload; das Plugin lädt sie selbst). Beim Dev-Build meldet Plugin Check erwartbar „Stable tag mismatch“ (1.0.0 ≠ 1.0.0-dev). Begründung der verbleibenden Warnungen in Abschnitt 2.1.*
- [x] Alle Ausgaben escaped, Eingaben sanitized, Nonces + Capabilities (P5-07, P5-08). *Nachweis: PHPCS (WordPress-Extra) 0 Fehler, Sicherheitsreview docs/SECURITY-REVIEW.md.*
- [x] Textdomain `form-captain` = Slug; keine `load_plugin_textdomain`-Pflicht für WordPress.org-Übersetzungen, Sprachpakete über translate.wordpress.org.
- [x] Keine Dateien außerhalb von Allowlist (Tests, `vendor/`, `node_modules/`, `docs/`) – `bin/check-release.sh` OK (126 Dateien, 16.11.2026).
- [x] `uninstall.php` löscht Daten nur nach expliziter Zustimmung (P5-06): Option `delete_data_on_uninstall` **und** Bestätigungszeitpunkt nötig.
- [x] Keine Remote-Assets (Fonts, Skripte, Bilder von CDNs) im Admin oder Frontend. *Nachweis: keine `wp_remote_*`-Aufrufe im Kern; E2E-Test „Seite ohne Formular lädt keine Form-Captain-Assets“.*
- [x] Externe Dienste (Captcha, Connectoren) im readme-Abschnitt „External services“ mit Zweck, übertragenen Daten, Zeitpunkt und Links zu Bedingungen/Datenschutz des Anbieters. In v1.0 ohne aktivierbaren externen Dienst: Abschnitt mit Aussage „keine externen Dienste“.

### 2.1 Verbleibende Plugin-Check-Warnungen (begründet)

| Anzahl | Code | Begründung |
|---:|---|---|
| 43 | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | Tabellennamen aus `$wpdb->prefix` und festen Konstanten (`Database\Tables`), WHERE-Fragmente aus festen Spaltennamen mit Platzhaltern; Werte gehen immer über `$wpdb->prepare()`. Kein Benutzerwert fließt ungeprüft in SQL (Sicherheitsreview docs/SECURITY-REVIEW.md). |
| 13 | `WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber` / `UnfinishedPrepare` | Fehlalarm: WHERE-Klauseln mit Platzhaltern werden dynamisch zusammengesetzt und die Parameter als Array übergeben (`EntryRepository`, `FormRepository`, `JobRepository`); der Sniff kann die Platzhalter im Variableninhalt nicht zählen. Abgedeckt durch Integrationstests der Filter. |
| 10 | `WordPress.DB.DirectDatabaseQuery.DirectQuery` / `NoCaching` | Eigene Tabellen (Migrator, Logger) ohne WordPress-API; Caching ist bei Migrations- und Schreibabfragen nicht sinnvoll. |
| 1 | `load_plugin_textdomain` | Das Plugin liefert de_DE, de_DE_formal, de_CH und de_AT selbst aus, bevor Sprachpakete auf translate.wordpress.org existieren. Nach Freigabe der Übersetzungen dort kann der Aufruf entfallen. |
| 1 | `set_time_limit()` | Nur beim CSV-Export großer Datenmengen, mit `function_exists`-Prüfung; der Export läuft zusätzlich in Stapeln. |

## 3. readme.txt

`readme.txt` liegt vor (P6-08) und ist am 16.11.2026 gegen die Liste unten abgeglichen. Pflichtinhalt:

```
=== Form Captain ===
Contributors: [E-5]
Tags: forms, contact form, form builder, submissions, gdpr
Requires at least: 7.0
Tested up to: [aktuelle geprüfte WP-Version]
Requires PHP: 8.5
Stable tag: x.y.z
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

[Kurzbeschreibung, max. 150 Zeichen, ohne Markup]

== Description ==
== Installation ==
== Frequently Asked Questions ==
== Screenshots ==
== Changelog ==
== Upgrade Notice ==
```

- [x] Kurzbeschreibung ≤ 150 Zeichen (125).
- [x] Höchstens 5 Tags; keine fremden Produktnamen.
- [x] Description: Funktionsumfang Free (konzept.md 4.1), was **nicht** enthalten ist, Hinweis auf Pro als separates Add-on (ein Absatz, sachlich).
- [x] Abschnitt **Privacy / Datenschutz**: gespeicherte Daten (Tabellen, keine IP), Retention-Default 12 Monate, Exporter/Eraser, Backups-Hinweis (DS-T-30), keine Telemetrie.
- [x] Abschnitt **External services** (siehe oben).
- [x] Abschnitt **Source code / Build**: Quellen in `assets/src`, Build mit `npm ci && npm run build` (esbuild), Repository-Link.
- [x] FAQ: Mailzustellung/SMTP, Rechte/Rollen, Multisite (Daten je Website, Netzwerkseite, Super-Admin-Zugriff auf Einsendungen standardmäßig aus), Deinstallation und Daten, Einsendungen exportieren, Unterschied Free/Pro.
- [x] Screenshots-Abschnitt: nummerierte Beschriftungen passend zu `screenshot-N.png` (8 Einträge = Screenshot-Liste in Abschnitt 4).
- [x] Changelog-Format `= x.y.z =` (wird von `bin/build-release.sh` ausgelesen).
- [ ] Validierung mit dem offiziellen Readme-Validator (wordpress.org, aus dieser Umgebung nicht erreichbar). `Contributors: captainsuite` ist ein Platzhalter bis E-5; `Tested up to` vor dem Release auf die geprüfte WP-Version setzen.

## 4. Assets (SVN-Verzeichnis `/assets`, nicht im ZIP)

| Asset | Datei | Maße | Format | Inhalt |
|---|---|---|---|---|
| Banner | `banner-772x250.png` | 772 × 250 | PNG/JPG | Produktname, Claim (konzept.md 1.1 Positionierung), keine Screenshots-Kopie |
| Banner (HiDPI) | `banner-1544x500.png` | 1544 × 500 | PNG/JPG | wie oben |
| Banner RTL (optional) | `banner-772x250-rtl.png` | 772 × 250 | PNG/JPG | nur falls Text gespiegelt werden muss |
| Icon | `icon-128x128.png` | 128 × 128 | PNG/JPG | Produktsymbol, auf kleinen Größen lesbar |
| Icon (HiDPI) | `icon-256x256.png` | 256 × 256 | PNG/JPG | wie oben |
| Icon (Vektor, optional) | `icon.svg` | – | SVG | zusätzlich, nicht als Ersatz der PNGs |
| Screenshots | `screenshot-1.png` … | empfohlen ≥ 1200 px breit | PNG/JPG | echte Screenshots der veröffentlichten Version, **keine** Konzept-Mockups aus `docs/visuals` |

**Screenshot-Liste (Vorschlag):**
1. Dashboard mit Einrichtungs-Checkliste
2. Formularübersicht
3. Formular-Editor mit Feldeinstellungen
4. Öffentliches Formular im Standard-Theme (Twenty Twenty-Five)
5. Einsendungen mit Filter und Status
6. Einsendungsdetail mit Notiz
7. Einstellungen Datenschutz & Aufbewahrung
8. Systemstatus

- [x] Screenshots mit Beispieldaten ohne echte Personen/E-Mail-Adressen (`example.com`). *Erstellt 16.11.2026 in `.wordpress-org/` (screenshot-1 bis -8, 1280 px breit), dazu Icon 128/256 + SVG und Banner 772/1544; Erzeugung reproduzierbar mit `tools/wporg-assets/`.*
- [ ] Deutsche Screenshots zusätzlich möglich über lokalisierte Assets erst nach Übersetzungsfreigabe; Standard: Englisch (Quelltextsprache).
- [ ] Dateigrößen gering halten (komprimierte PNGs; derzeit 3–220 KB je Datei), MIME-Typ in SVN korrekt (`svn propset svn:mime-type image/png`). Vor dem ersten SVN-Commit Screenshots mit der dann freigegebenen Version neu erzeugen.

## 5. Einreichung und SVN-Ablauf

1. Offene Entscheidungen E-1 bis E-6, E-8 getroffen.
2. Release-Checkliste `RELEASE.md` 2.1–2.3 für `1.0.0` abgeschlossen, Plugin Check sauber.
3. ZIP über das Einreichungsformular auf WordPress.org hochladen (eingeloggt mit Konto aus E-1); Hinweis an das Review-Team: „Premiumfunktionen ausschließlich in separatem Add-on außerhalb des Verzeichnisses; Free ohne Lizenzprüfung.“
4. Review-Rückmeldungen per E-Mail beantworten; Korrekturen als neues ZIP mit gleicher Version (vor Freigabe zulässig).
5. Nach Freigabe: SVN-Zugang aktiv; Erstcommit nach `RELEASE.md` 5 (`trunk`, `tags/1.0.0`, `/assets`).
6. Committer in den Plugin-Einstellungen eintragen (mindestens zwei Personen, E-1).
7. Plugin-Seite prüfen: Beschreibung, Screenshots, Banner/Icon, Download installierbar.
8. Forum-Benachrichtigungen für Supportverantwortliche aktivieren (E-4); Antwortziel festlegen.
9. Übersetzungsprojekt auf translate.wordpress.org: de_DE-Übersetzung einspielen oder PTE (Project Translation Editor) beantragen.
