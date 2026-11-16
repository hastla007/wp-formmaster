# Release-Prozess Form Captain (Free)

**Bezug:** P9-03 (Release-Checkliste), P6-11 (Upgrade-/Rollback-/Deinstallationspfad), P6-14 (Upgrade-Matrix, Doku-Teil), P6-10/P6-18 (Build und ZIP-Prüfung).
**Geltungsbereich:** Kernplugin `form-captain`. Für `form-captain-pro` gilt dieselbe Checkliste plus die mit **[Pro]** markierten Punkte; Verteilung dort über den License-Captain-Updater statt WordPress.org.
**Betrieb nach dem Release:** siehe [`BETRIEB.md`](BETRIEB.md); WordPress.org-Einreichung: [`WORDPRESS-ORG.md`](WORDPRESS-ORG.md).

## 1. Versionierung

| Stelle | Inhalt | Wann ändern |
|---|---|---|
| `form-captain.php` Header `Version:` | Plugin-Version (SemVer, z. B. `1.0.1`) | jedes Release |
| `form-captain.php` Konstante `FORM_CAPTAIN_VERSION` | identisch zum Header | jedes Release |
| `readme.txt` `Stable tag:` | identisch zum Header | jedes Release |
| `readme.txt` `Tested up to:` / `Requires at least:` / `Requires PHP:` | geprüfte WP-Version / 6.8 / 8.3 | wenn sich die Testmatrix ändert |
| `Migrator::TARGET_VERSION` | Datenbank-Schemaversion (int, unabhängig von der Plugin-Version) | nur bei neuer Migration |
| `FORM_CAPTAIN_CONTRACT_VERSION` | Version des öffentlichen Vertrags (`api-v1.md`) | nur bei Vertragsänderung; Minor = additiv, Major = Breaking (nicht in 1.x) |
| Formular-JSON `schema_version` | Formularschema (`formularschema.md`) | nur mit Migrationsklasse `V{n}To{n+1}` |

SemVer-Regel: Patch = Fehlerbehebung ohne Schemaänderung; Minor = neue Funktionen und/oder additive Migration; Major = entfernte/inkompatible öffentliche Schnittstelle (für 1.x ausgeschlossen). Vorabversionen tragen `-rc.N`; `-dev` wird nie veröffentlicht.

> `readme.txt` existiert im Repository noch nicht (P6-08). `bin/build-release.sh` erzeugt die Release-Notizen aus dem Changelog-Abschnitt `= x.y.z =` der `readme.txt`; ohne Datei bleiben sie leer und `bin/check-release.sh` schlägt fehl.

## 2. Release-Checkliste

Jeder Punkt wird im Release-Ticket mit Datum, Person und Nachweis (Log, Screenshot, Hash) abgehakt. Ein offener Punkt blockiert das Release.

### 2.1 Vorbereitung

- [ ] Release-Branch `release/x.y.z` vom Hauptzweig; keine offenen Merges anderer Arbeitspakete in diesem Zweig.
- [ ] Versionsnummer an allen Stellen aus Abschnitt 1 gesetzt; `grep -rn "1.0.0-dev"` liefert keine Treffer in ausgelieferten Dateien.
- [ ] Changelog in `readme.txt` unter `== Changelog ==` als `= x.y.z =` mit Kategorien *Neu*, *Geändert*, *Behoben*, *Sicherheit*, *Migration*. Migrationen mit Hinweis auf Dauer/Risiko; Sicherheitsfixes ohne Exploit-Details bis zur Veröffentlichung.
- [ ] `== Upgrade Notice ==` für Releases mit Migration oder Verhaltensänderung (max. 300 Zeichen).
- [ ] Neue/geänderte Schemamigration: Migrationsschritt ist idempotent, hat Verify-Schritt und Integrationstest (siehe Abschnitt 4); Wiederherstellungsweg für irreversible Schritte ist in Abschnitt 3.5 dieses Dokuments für die Version eingetragen, **bevor** das Release gebaut wird (P9-13).
- [ ] Übersetzungen: POT neu erzeugt, de_DE/de_AT vollständig (P6-07).

### 2.2 Qualitätsprüfungen (lokal und CI grün)

```bash
composer install
npm ci
npm run build:check      # Build-Ausgabe entspricht dem committeten Stand (ADR 0002)
composer lint            # php -l über alle Dateien
composer phpcs           # WordPress Coding Standards
composer phpstan         # Level 6
composer test            # test:unit + test:integration
npm run e2e              # Hauptablauf (P6-13)
bin/check-secrets.sh     # keine Secrets im Arbeitsbaum
```

- [ ] Alle Befehle ohne Fehler; CI-Matrix (PHP 8.3/8.4, WP 6.8/aktuell, MariaDB 10.6/10.11, MySQL 8.0/8.4) grün (ADR 0005).
- [ ] Upgrade-Matrix aus Abschnitt 4 für alle Ausgangsversionen durchlaufen (automatisierter Teil in CI, manueller Teil protokolliert).
- [ ] WooCommerce-Basiskompatibilität (P6-03): mit aktivem WooCommerce keine Eingriffe in Warenkorb/Checkout/HPOS.
- [ ] **[Pro]** Lizenz-/Entitlementtest: aktivieren, Entitlement entziehen, Offline-Cache (14 Tage) abgelaufen, Lizenz abgelaufen → Free-Formulare und Entries intakt, Pro-Aktionen pausiert, keine Pro-Updates.

### 2.3 Build und Paketprüfung

```bash
SOURCE_DATE_EPOCH=$(git log -1 --format=%ct) bin/build-release.sh x.y.z
bin/check-release.sh dist/form-captain-x.y.z.zip
```

- [ ] `dist/form-captain-x.y.z.zip`, `dist/form-captain-x.y.z.zip.sha256` und `dist/RELEASE-NOTES-x.y.z.md` vorhanden.
- [ ] `bin/check-release.sh` meldet `Release check OK` (Dateiliste, Rechte 644/755, PHP-Syntax, Textdomain, Header, keine Tests/Secrets/Dev-Dateien, Prüfsumme).
- [ ] Reproduzierbarkeit: Zweiter Build mit gleichem `SOURCE_DATE_EPOCH` auf anderer Maschine ergibt denselben SHA-256. Abweichung = Release-Blocker.
- [ ] SHA-256 im Release-Ticket und in den GitHub-Release-Notizen eingetragen: `sha256sum -c form-captain-x.y.z.zip.sha256`.
- [ ] ZIP-Installation auf frischem WordPress 6.8 / PHP 8.3 und auf aktueller WP-Version: aktivieren, Assistent, Testformular absenden, Entry sichtbar.
- [ ] **[Pro]** Updater-Metadaten signiert; Paketgröße und SHA-256 im Updatefeed stimmen mit dem ZIP überein (konzept.md Abschnitt 8, Punkt 5).

### 2.4 Upgrade-Test, Backup und Rollback-Plan

- [ ] Upgrade von der letzten veröffentlichten Version auf Staging mit anonymisierten Beispieldaten (Verfahren: `BETRIEB.md` Abschnitt 4).
- [ ] Backup der Staging-Datenbank (`fc_*`, `form_captain_*`) und des Plugin-Verzeichnisses **vor** dem Upgrade erstellt und Restore einmal geprüft (`BETRIEB.md` Abschnitt 1).
- [ ] Rollback-Plan für dieses Release ausgefüllt (Vorlage Abschnitt 3.5): letzte funktionierende Version, deren ZIP + SHA-256, Schemaversion vorher/nachher, ob Downgrade ohne DB-Restore möglich ist.
- [ ] Systemstatus nach Upgrade: Schemaversion = `TARGET_VERSION`, kein `form_captain_migration_state`, keine verwaisten Werte, Queue ohne neue `dead`-Jobs.

### 2.5 Veröffentlichung

- [ ] Git-Tag `vx.y.z` (signiert) auf dem Release-Commit; GitHub-Release mit ZIP, `.sha256` und Release-Notizen.
- [ ] WordPress.org-SVN gemäß Abschnitt 5.
- [ ] **[Pro]** Paket im License-Captain-Katalog (`form-captain-pro`) hochladen, Updatefeed freischalten, zuerst für Staging-Lizenzen (falls vom Server unterstützt), dann allgemein.
- [ ] Nach Veröffentlichung: Update auf einer Referenzinstallation über den WordPress-Updater (nicht per ZIP) durchführen und Systemstatus prüfen.
- [ ] 72 h Beobachtung: Supporteingang und Forum auf Upgrade-Fehler (`migration_failed`, `schema_newer_than_plugin`) prüfen; Entscheidung „Release hält / Hotfix / Rückzug“ dokumentieren.

## 3. Upgrade-, Downgrade- und Rollback-Verhalten

### 3.1 Upgrade

- Beim Laden prüft das Plugin `Migrator::needs_migration()` (`form_captain_db_version` < `Migrator::TARGET_VERSION`) und führt `Migrator::migrate()` aus.
- Schritte laufen aufsteigend; jeder Schritt ist idempotent und wird über `verify()` geprüft. Erst nach erfolgreichem Verify wird `form_captain_db_version` auf die Schrittversion gesetzt.
- Vor jedem Schritt schreibt der Migrator `form_captain_migration_state = {target, started_gmt, error: null}` (autoload = no); nach Abschluss aller Schritte wird die Option gelöscht.
- Einstellungen: neue Defaults werden ergänzt, bestehende Werte nie überschrieben (P5-21).
- Formularschemata werden nicht in der DB-Migration umgeschrieben; ältere `schema_version` wird beim Laden in-memory migriert und erst beim nächsten Speichern persistiert. Veröffentlichte Snapshots (`fc_form_versions`) bleiben unverändert (`formularschema.md`, Migrationsregeln).

### 3.2 Abgebrochene oder fehlgeschlagene Migration

| Situation | Erkennbar an | Verhalten | Admin-Aktion |
|---|---|---|---|
| Schritt wirft Fehler | `form_captain_migration_state.error = migration_failed`, `detail` (≤ 200 Zeichen, ohne Nutzdaten) | `form_captain_db_version` bleibt auf letztem erfolgreichem Schritt; `migrate()` liefert `false` | Systemstatus zeigt Fehler; Ursache beheben (z. B. DB-Rechte), Seite neu laden → Migration läuft ab dem offenen Schritt erneut |
| Prozess abgebrochen (Timeout, Fatal, Serverneustart) | `form_captain_migration_state` vorhanden, `error = null`, `started_gmt` älter als 10 min | nächster Request startet den offenen Schritt erneut (idempotent) | keine; bei wiederholtem Abbruch Migration per WP-CLI (Abschnitt 3.6) mit höherem Zeitlimit |
| Tabelle fehlt nach Schritt | Verify wirft `missing_table:{name}` → `migration_failed` | wie oben | DB-Benutzer braucht `CREATE`/`ALTER`; danach erneut laden |

Solange `form_captain_db_version < TARGET_VERSION` gilt, nimmt das Plugin **keine** Einsendungen an, die neue Strukturen benötigen; öffentliche Formulare zeigen die neutrale Meldung zu `storage_failed` (HTTP 503), Eingaben bleiben im Browser erhalten. Admin-Seiten bleiben lesbar.

> Umsetzungshinweis: Die Sperre der Submission bei offener Migration und die Erkennung „abgebrochen nach 10 min“ sind in `Migrator` noch nicht implementiert; sie sind Abnahmekriterium von P6-11.

### 3.3 Downgrade (ältere Plugin-Version auf neuerem Schema)

- `migrate()` erkennt `form_captain_db_version > TARGET_VERSION`, schreibt `form_captain_migration_state = {error: schema_newer_than_plugin, installed: n}` und bricht ab.
- **Daten werden nicht angefasst:** keine Tabellen gelöscht, keine Spalten entfernt, keine Version zurückgesetzt.
- Systemstatus zeigt: „Die Datenbank wurde von einer neueren Form-Captain-Version angelegt (Schema n). Bitte die neuere Version wieder installieren oder ein Backup von vor dem Update einspielen.“
- Ob die ältere Version mit dem neueren Schema arbeiten darf, entscheidet das Release: Additive Migrationen (neue Spalten/Tabellen/Indizes) sind abwärtsverträglich und im Rollback-Plan als „Downgrade ohne DB-Restore möglich“ zu markieren. Nicht additive Migrationen (Umbenennung, Typänderung, Datenumbau) sind „nur mit DB-Restore“.
- Für 1.x gilt: Migrationen sind additiv zu planen; ein nicht additiver Schritt braucht eine Produktentscheidung und einen Restore-Weg (P9-13).

### 3.4 Rollback

Rollback = Rückkehr zur letzten funktionierenden Plugin-Version **und** zu einem konsistenten Datenstand.

1. Wartungsmodus aktivieren bzw. betroffene Formulare pausieren (Status `paused`), damit keine Einsendungen verloren gehen oder in ein später verworfenes Schema geschrieben werden.
2. Plugin-Verzeichnis durch das ZIP der letzten funktionierenden Version ersetzen (SHA-256 vorher prüfen). Nicht deaktivieren/deinstallieren – `uninstall.php` würde bei gesetzter Löschoption Daten entfernen.
3. Rollback-Plan prüfen:
   - *Downgrade ohne DB-Restore möglich:* fertig; Systemstatus zeigt `schema_newer_than_plugin` als Hinweis. Beim späteren erneuten Upgrade läuft keine Migration erneut (Version bereits erreicht).
   - *Nur mit DB-Restore:* Tabellen `fc_*` und Optionen `form_captain_*` aus dem Backup vor dem Update einspielen (`BETRIEB.md` Abschnitt 1.3). Einsendungen zwischen Update und Restore vorher als CSV sichern und mit dem Kunden klären, ob sie nachgetragen werden.
4. Cron/Queue prüfen: `wp cron event run form_captain_process_jobs`; Jobs mit Status `dead` aus dem fehlerhaften Zeitraum sichten, nicht pauschal neu starten (Gefahr doppelter Mails).
5. Formulare wieder veröffentlichen, Testeinsendung, Systemstatus grün.
6. Ergebnis und Dauer im Release-Ticket dokumentieren.

### 3.5 Vorlage Rollback-Plan je Release

| Feld | Wert |
|---|---|
| Release | x.y.z |
| Letzte funktionierende Version (Free / Pro) | |
| ZIP + SHA-256 der Rückfallversion | |
| Schemaversion vorher → nachher | |
| Migrationsart | keine / additiv / nicht additiv |
| Downgrade ohne DB-Restore möglich | ja / nein (Begründung) |
| Wiederherstellungsweg bei nicht additiver Migration | |
| Geschätzte Dauer Migration bei 100 000 Entries | |
| Verantwortlich für Rollback-Entscheidung | |

### 3.6 Deinstallation

- Standard: `uninstall.php` entfernt nur geplante Cron-Hooks; Tabellen, Optionen und Capabilities bleiben.
- Datenlöschung nur, wenn `privacy.delete_data_on_uninstall = true` **und** `privacy.delete_data_confirmed_gmt` gesetzt ist; dann: alle `fc_*`-Tabellen, Optionen `form_captain_settings`, `form_captain_db_version`, `form_captain_migration_state`, `form_captain_secret`, `form_captain_connectors`, `form_captain_activation_redirect`, `form_captain_worker_lock` sowie Capabilities.
- Test: beide Varianten in der Upgrade-Matrix (U-12, U-13).

### 3.7 WP-CLI (geplant)

| Befehl | Zweck | Status |
|---|---|---|
| `wp form-captain jobs run` | Queue per System-Cron abarbeiten | ADR 0004, zu implementieren |
| `wp form-captain status` | aggregierter Status inkl. Schema/Migration (siehe `BETRIEB.md` 5) | zu implementieren |
| `wp form-captain migrate` | Migration ohne Web-Timeout ausführen bzw. fortsetzen | Vorschlag, zu implementieren |

## 4. Upgrade-Matrix (Testplan P6-14)

**Testdaten (repräsentativ, anonymisiert):** 5 Formulare (Vorlagen `contact`, `quote`, `support`, `all-fields` mit allen 12 Feldtypen, `conditional`), je Formular 2 veröffentlichte Versionen, 10 000 Entries in allen Status (`new`, `read`, `archived`, `spam`, `trash`), davon 50 anonymisiert und 100 mit abgelaufenem `expires_gmt`; Notizen; Jobs in allen Status (`pending`, `running` mit abgelaufenem Lock, `failed`, `dead`, `succeeded`); angepasste Einstellungen (Retention 90 Tage, eigene Absenderadresse, Blocklist). **[Pro]** 20 `fc_files`-Zeilen mit physischen Dateien.

**Ausgangsversionen:** jede veröffentlichte Minor-Version ab 1.0.0 (letzter Patch je Minor) sowie die direkte Vorversion. Bis zum ersten Release: Schema 0 (frische Installation) und Schema 1 aus `1.0.0-rc.1`.

**Prüfung nach jedem Fall (Soll):**
- `SHOW CREATE TABLE` aller `fc_*` identisch zu einer frischen Installation (AC-002).
- Anzahl Formulare, Versionen, Entries je Status, Werte, Notizen und Jobs identisch zu vorher (Zählabfrage vorher/nachher).
- Stichprobe von 20 Entries: Werte byte-identisch (Hash über `entry_id, field_id, value`).
- `form_captain_db_version = TARGET_VERSION`, `form_captain_migration_state` nicht vorhanden.
- Einstellungen unverändert, neue Schlüssel mit Default ergänzt.
- Systemstatus ohne Fehler; Integritätsbericht `orphan_values = 0`.

| ID | Fall | Durchführung | Erwartung | Automatisierbar |
|---|---|---|---|---|
| U-01 | Frische Installation aktuelle Version | Aktivieren | Schema = Referenz | ja (Integration) |
| U-02 | Upgrade direkte Vorversion → aktuell | Vorversion installieren, Testdaten laden, ZIP ersetzen, Admin aufrufen | Soll-Prüfung erfüllt | ja |
| U-03 | Upgrade älteste unterstützte Version → aktuell (alle Zwischenschritte) | wie U-02 | alle Schritte nacheinander, Soll erfüllt | ja |
| U-04 | Upgrade über WordPress-Updater statt ZIP | Update aus Plugin-Liste | wie U-02; kein Doppellauf bei parallelen Requests | manuell |
| U-05 | Parallele Requests während Migration | 10 gleichzeitige Admin-/Frontend-Requests beim ersten Aufruf | Migration läuft genau einmal pro Schritt bzw. idempotent; keine doppelten Indizes | ja |
| U-06 | Unterbrochene Migration (Prozessabbruch) | Schritt per Test-Hook nach Teilausführung mit Fatal beenden | `migration_state` mit `error = null` bleibt; Version unverändert; Formulare nehmen keine Einsendung an (503) | ja |
| U-07 | Wiederaufnahme nach U-06 | nächster Request bzw. `wp form-captain migrate` | offener Schritt erneut, idempotent abgeschlossen; Soll erfüllt; State gelöscht | ja |
| U-08 | Fehlgeschlagene Migration (fehlende DB-Rechte) | DB-User ohne `ALTER` | `migration_failed` mit `detail`; Systemstatus-Hinweis; nach Rechtekorrektur U-07 | teilweise |
| U-09 | Downgrade ohne Restore | aktuelle Version → Vorversion (ZIP ersetzen) | `schema_newer_than_plugin`; keine Datenänderung (Zählung + Hash identisch); bei additivem Schema Formulare funktionsfähig | ja |
| U-10 | Rollback mit DB-Restore | Upgrade, 20 neue Einsendungen, Restore Backup, Vorversion | Datenstand = Backup; die 20 Einsendungen vorher als CSV gesichert; DS-T-31-Hinweis bei abgelaufenen Entries | manuell |
| U-11 | Erneutes Upgrade nach Downgrade | nach U-09 wieder aktuelle Version | keine erneute Migration, State gelöscht, Soll erfüllt | ja |
| U-12 | Deinstallation Standard | löschen über Plugin-Liste | Tabellen/Optionen vorhanden, Cron-Hooks entfernt | ja |
| U-13 | Deinstallation mit bestätigter Datenlöschung | Option + Bestätigung setzen, löschen | alle `fc_*` und `form_captain_*` entfernt, Capabilities entfernt | ja |
| U-14 | MyISAM-Altinstallation | Tabellen vorab als MyISAM | Migration läuft; Systemstatus warnt (Engine) | ja |
| U-15 | Queue über Upgrade hinweg | offene `pending`/`failed`/`running`-Jobs vor Upgrade | nach Upgrade weiterverarbeitet; abgelaufene Locks → `failed/lock_expired`; keine doppelte Mail (Idempotenz-Key) | ja |
| U-16 | Formularschema älterer `schema_version` | Formular mit Version n-1 laden/rendern/speichern | in-memory migriert, Snapshots unverändert, nach Speichern aktuelle Version | ja (Unit) |
| U-17 | **[Pro]** Pro-Version älter/neuer als Core | Core aktualisieren, Pro alt lassen und umgekehrt | Add-on-Kernel meldet Versionskonflikt, Pro-Features pausieren, Free intakt | manuell |

Ergebnisprotokoll je Release: Tabelle mit ID, Ausgangsversion, Datenbank (MariaDB/MySQL-Version), Ergebnis, Abweichung, Ticket.

## 5. WordPress.org-SVN

Voraussetzungen und offene Entscheidungen (Konto, Slug): [`WORDPRESS-ORG.md`](WORDPRESS-ORG.md).

```bash
svn co https://plugins.svn.wordpress.org/form-captain svn-form-captain
cd svn-form-captain
# 1. trunk durch Inhalt des geprüften ZIPs ersetzen (nicht aus dem Arbeitsbaum kopieren)
rm -rf trunk/* && unzip -q ../dist/form-captain-x.y.z.zip -d /tmp/fc && cp -R /tmp/fc/form-captain/. trunk/
svn add --force trunk && svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm
# 2. Tag aus trunk anlegen
svn cp trunk tags/x.y.z
# 3. Assets (Banner, Icon, Screenshots) nur bei Änderung
#    liegen in /assets auf SVN-Root-Ebene, nicht im Plugin-ZIP
svn ci -m "Release x.y.z"
```

- [ ] `trunk/readme.txt` `Stable tag: x.y.z` zeigt auf den neuen Tag; Tag-Inhalt = ZIP-Inhalt (Diff gegen entpacktes ZIP leer).
- [ ] Nach ca. 15 min: Plugin-Seite zeigt neue Version, Download-ZIP vom Verzeichnis enthält dieselben Dateien (Dateiliste vergleichen; der Hash weicht wegen Neupackung durch WordPress.org ab).
- [ ] Tags werden nie nachträglich geändert; Korrektur = neue Patch-Version.
