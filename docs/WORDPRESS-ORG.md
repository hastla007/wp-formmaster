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
| 5 | Keine Trialware, keine gesperrten Funktionen | Free hat keine Lizenzprüfung, keinen Lizenzclient, keine Limits; Pro ist separates Add-on (konzept.md 1, 7) | Code-Suche nach Lizenz-/Premium-Prüfungen im Free = 0 Treffer |
| 6 | SaaS erlaubt, wenn echter Dienst | Kernbetrieb ohne externen Dienst; Connectoren nur opt-in | – |
| 7 | Kein Tracking ohne Zustimmung | keine Telemetrie (P9-04); keine externen Requests ohne Konfiguration; Captcha-Provider opt-in | Netzwerkmitschnitt bei Installation/Einrichtung = keine externen Requests |
| 8 | Kein Ausführen von Fremdcode, kein eigener Updater | Free hat keinen Updater; Updates nur über WordPress.org | Code-Suche `pre_set_site_transient_update_plugins` im Free = 0 |
| 9 | Nichts Illegales/Unethisches | keine gefälschten Bewertungen, keine Keyword-Stuffing-Tags | readme-Review |
| 10 | Keine Links/Credits im Frontend ohne Opt-in | kein „Powered by“ im Formular-Output | E2E-Prüfung Frontend-Markup |
| 11 | Admin-Dashboard nicht kapern | keine globalen Upgrade-Banner (AC-019); Pro-Hinweise sparsam, kontextbezogen, wegklickbar (P6-09); keine Hinweise außerhalb der Form-Captain-Seiten außer Fehlern | manueller Review aller Admin-Seiten |
| 12 | readme ohne Spam | max. 5 Tags, keine Konkurrenznamen, keine Keyword-Listen | Abschnitt 3 |
| 13 | Standard-WordPress-Bibliotheken nutzen | Admin-UI mit `wp.element`/`wp.components` (ADR 0002), kein gebündeltes jQuery/React | Build-Manifest |
| 14 | Häufige Commits vermeiden | SVN nur für Releases, nicht als Entwicklungs-Repository | – |
| 15 | Versionsnummer je Release erhöhen | `RELEASE.md` 1 | – |
| 16 | Vollständiges Plugin bei Einreichung | Free v1.0 vollständig (nach Phase 6) | Gesamtabnahme |
| 17 | Marken und Projekte respektieren | E-3 | – |
| 18 | Verzeichnis kann Plugins entfernen/ändern | Supportpflicht ernst nehmen | – |

**Zusätzliche technische Prüfungen**
- [ ] Plugin Check (offizielles Prüf-Plugin) auf dem Release-ZIP ohne Fehler; Warnungen begründet im Release-Ticket.
- [ ] Alle Ausgaben escaped, Eingaben sanitized, Nonces + Capabilities (P5-07, P5-08).
- [ ] Textdomain `form-captain` = Slug; keine `load_plugin_textdomain`-Pflicht für WordPress.org-Übersetzungen, Sprachpakete über translate.wordpress.org.
- [ ] Keine Dateien außerhalb von Allowlist (Tests, `vendor/`, `node_modules/`, `docs/`) – `bin/check-release.sh`.
- [ ] `uninstall.php` löscht Daten nur nach expliziter Zustimmung (P5-06).
- [ ] Keine Remote-Assets (Fonts, Skripte, Bilder von CDNs) im Admin oder Frontend.
- [ ] Externe Dienste (Captcha, Connectoren) im readme-Abschnitt „External services“ mit Zweck, übertragenen Daten, Zeitpunkt und Links zu Bedingungen/Datenschutz des Anbieters. In v1.0 ohne aktivierbaren externen Dienst: Abschnitt mit Aussage „keine externen Dienste“.

## 3. readme.txt

Datei liegt noch nicht vor (P6-08). Pflichtinhalt:

```
=== Form Captain ===
Contributors: [E-5]
Tags: forms, contact form, form builder, submissions, gdpr
Requires at least: 6.8
Tested up to: [aktuelle geprüfte WP-Version]
Requires PHP: 8.3
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

- [ ] Kurzbeschreibung ≤ 150 Zeichen.
- [ ] Höchstens 5 Tags; keine fremden Produktnamen.
- [ ] Description: Funktionsumfang Free (konzept.md 4.1), was **nicht** enthalten ist, Hinweis auf Pro als separates Add-on (ein Absatz, sachlich).
- [ ] Abschnitt **Privacy / Datenschutz**: gespeicherte Daten (Tabellen, keine IP), Retention-Default 12 Monate, Exporter/Eraser, Backups-Hinweis (DS-T-30), keine Telemetrie.
- [ ] Abschnitt **External services** (siehe oben).
- [ ] Abschnitt **Source code / Build**: Quellen in `assets/src`, Build mit `npm ci && npm run build` (esbuild), Repository-Link.
- [ ] FAQ: Mailzustellung/SMTP, Rechte/Rollen, Multisite nicht unterstützt (v1.0), Deinstallation und Daten, Einsendungen exportieren, Unterschied Free/Pro.
- [ ] Screenshots-Abschnitt: nummerierte Beschriftungen passend zu `screenshot-N.png`.
- [ ] Changelog-Format `= x.y.z =` (wird von `bin/build-release.sh` ausgelesen).
- [ ] Validierung mit dem offiziellen Readme-Validator.

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

- [ ] Screenshots mit Beispieldaten ohne echte Personen/E-Mail-Adressen (`example.com`).
- [ ] Deutsche Screenshots zusätzlich möglich über lokalisierte Assets erst nach Übersetzungsfreigabe; Standard: Englisch (Quelltextsprache).
- [ ] Dateigrößen gering halten (komprimierte PNGs), MIME-Typ in SVN korrekt (`svn propset svn:mime-type image/png`).

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
