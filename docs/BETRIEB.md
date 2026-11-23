# Betrieb Form Captain

**Bezug:** P5-13 (Backup/Restore), P5-14 (Datenlebenszyklus, Verweis), P9-02 (Support- und Fehlerprozess, **Entwurf**), P9-12/P9-13 (Release-/Rollback-Übung, Staging-Rollout), P9-14 (Betriebsüberwachung).
**Grundsatz:** Betrieb, Support und Überwachung arbeiten mit Status, Zählern, Fehlercodes und Korrelations-IDs – nie mit Submission-Inhalten, E-Mail-Adressen, IP-Adressen, Tokens oder Secrets (DS-06, P5-09, P5-22).
**Verwandte Dokumente:** [`RELEASE.md`](RELEASE.md), [`spec/datenmodell.md`](spec/datenmodell.md), [`spec/datenschutz.md`](spec/datenschutz.md) Abschnitt 7, [`adr/0004-queue.md`](adr/0004-queue.md).

## 1. Backup und Restore (P5-13)

Form Captain erstellt keine Backups (DS-B-01). Dieses Verfahren beschreibt, **was** ein Backup enthalten muss und wie Wiederherstellung geprüft wird; das Werkzeug (Hoster, Backup-Plugin, `mysqldump`, `wp db export`) wählt der Betreiber.

### 1.1 Umfang

**Tabellen** (mit WordPress-Präfix, z. B. `wp_`):

| Tabelle | Inhalt | Pflicht im Backup |
|---|---|---|
| `fc_forms` | Formulare, Arbeitsstand, Einstellungen | ja |
| `fc_form_versions` | veröffentlichte Snapshots (Entries verweisen darauf) | ja |
| `fc_entries` | Einsendungen inkl. Status, Retention-Datum | ja |
| `fc_entry_values` | Feldwerte | ja |
| `fc_entry_notes` | Notizen, Audit | ja |
| `fc_jobs` | Queue/Outbox inkl. Zustände | ja (siehe 1.4 zu Doppelversand) |
| `fc_files` | Datei-Referenzen (Pro-Uploads) | ja |
| `fc_log` | redigiertes Fehlerprotokoll (30 Tage) | optional |
| `fc_rate_limits` | kurzlebige Rate-Limit-Buckets | nein (wird neu aufgebaut) |

**Optionen** (`wp_options`, Muster `form_captain_%`):

| Option | Pflicht | Hinweis |
|---|---|---|
| `form_captain_db_version` | ja | muss zum Tabellenstand passen |
| `form_captain_settings` | ja | |
| `form_captain_secret` | ja | ohne ihn werden Submission-Tokens und Kontext-Signaturen ungültig; IP-Hashes nicht mehr vergleichbar. Backup entsprechend schützen. |
| `form_captain_connectors` | ja | Opt-in-Zustände |
| `form_captain_migration_state` | ja, falls vorhanden | zeigt eine laufende/abgebrochene Migration |
| `form_captain_last_cleanup_at` | ja | Grundlage der Restore-Erkennung (DS-B-03) |
| `form_captain_rl_salt`, `form_captain_worker_lock`, `form_captain_activation_redirect` | nein | kurzlebig |
| **[Pro]** `form_captain_pro_*` | ja | Lizenzstatus wird nach Restore neu vom Lizenzserver geprüft |

**Dateien:** Plugin-Verzeichnisse `wp-content/plugins/form-captain` (bzw. das Release-ZIP + SHA-256 genügt) und **[Pro]** privates Upload-Verzeichnis `wp-content/uploads/form-captain-private/` bzw. `FORM_CAPTAIN_PRIVATE_DIR`. Datenbank und Dateien müssen aus **demselben Zeitpunkt** stammen, sonst entstehen `fc_files`-Zeilen ohne Datei oder umgekehrt.

**Rollen-Capabilities** liegen in der Option `{prefix}user_roles` und sind nur mit einem vollständigen WordPress-DB-Backup enthalten.

### 1.2 Backup erstellen (Referenzverfahren)

```bash
P=$(wp db prefix)
wp db export fc-backup.sql --tables=$(wp db tables "${P}fc_*" --all-tables-with-prefix --format=csv)
wp db query "SELECT option_name, option_value, autoload FROM ${P}options WHERE option_name LIKE 'form\_captain\_%'" > fc-options.tsv
tar czf fc-files.tgz -C wp-content/uploads form-captain-private   # nur Pro
sha256sum fc-backup.sql fc-options.tsv fc-files.tgz > fc-backup.sha256
```

Für den Optionsimport ist ein vollständiger `wp db export` der Installation robuster; das Teilbackup oben dient der Übung und der Migration auf eine Testinstallation.

### 1.3 Restore – Reihenfolge

1. Zielinstallation: gleiche oder neuere Form-Captain-Version wie beim Backup installieren, **nicht** aktivieren (sonst legt der Aktivator leere Tabellen und einen neuen `form_captain_secret` an).
2. Formulare/Website in Wartung; WP-Cron für den Zeitraum anhalten (`define('DISABLE_WP_CRON', true)`), damit keine Jobs vor Abschluss laufen.
3. Tabellen `fc_*` importieren (`wp db import fc-backup.sql`). Präfix bei abweichender Installation vorher anpassen.
4. Optionen `form_captain_*` importieren, insbesondere `form_captain_secret` und `form_captain_db_version`.
5. **[Pro]** Private Dateien an den gleichen relativen Pfad entpacken; Rechte wie Uploads-Verzeichnis, Schutzdateien (`.htaccess`, `index.php`) vorhanden.
6. Plugin aktivieren. Ist die Plugin-Version neuer als das Backup-Schema, läuft die Migration (siehe `RELEASE.md` 3.1).
7. Integritätsprüfung (1.5) durchführen.
8. Queue sichten (1.4), danach Cron wieder aktivieren.
9. Formulare veröffentlichen, Testeinsendung.

### 1.4 Queue und Doppelversand nach Restore

Ein Backup enthält Jobs im Zustand zum Sicherungszeitpunkt. Jobs, die nach dem Backup bereits ausgeführt wurden, stehen im Restore wieder als `pending`/`failed`.
- Vor Cron-Aktivierung im Systemstatus Anzahl fälliger Jobs je Typ prüfen.
- `mail.*`-Jobs älter als 24 h und `connector.action`-Jobs mit nicht idempotentem Ziel manuell auf `cancelled` setzen (Admin: Integrationen → Jobs; Recht `form_captain_manage_integrations`) oder bewusst freigeben.
- Connector-Aktionen sind über `idempotency_key` gegen Duplikate im Zielsystem abgesichert, soweit der Connector den Schlüssel auswertet.

### 1.5 Integritätsprüfung über Systemstatus

| Prüfung | Quelle | Soll |
|---|---|---|
| Schemaversion | `Migrator::integrity_report()` `installed_version` | = `target_version` |
| Migrationszustand | `state` | leer |
| Tabellen/Engines | `engines` | alle vorhanden, InnoDB |
| Verwaiste Werte | `orphan_values` | 0 |
| Entries ohne Werte | Integritätscheck (datenmodell.md) | 0 |
| Entries mit fehlendem `form_version_id` | Zählabfrage | 0 |
| **[Pro]** `fc_files` ohne physische Datei / Datei ohne Zeile | Dateiprüfung | 0 |
| Restore-Erkennung | DS-B-03 | ggf. Hinweis DS-T-31, Cleanup eingeplant |
| Zählung vorher/nachher | Formulare, Versionen, Entries je Status, Werte, Notizen, Jobs je Status | identisch zur Quelle |

### 1.6 Testverfahren auf separater Installation

1. Quellinstallation (Staging) mit den Testdaten aus `RELEASE.md` Abschnitt 4 befüllen; Zählung und Hash über `fc_entry_values (entry_id, field_id, value)` notieren.
2. Backup nach 1.2.
3. Frische Zielinstallation (anderer Host oder Container, anderes Präfix).
4. Restore nach 1.3.
5. Integritätsprüfung 1.5; Hash und Zählung vergleichen.
6. Funktionstest: Entry öffnen, CSV-Export, Testeinsendung mit Token (prüft `form_captain_secret`), Queue-Lauf, **[Pro]** geschützter Download.
7. Protokoll: Datum, Versionen, Dauer, Abweichungen. Turnus: vor jedem Minor-Release und mindestens halbjährlich.

### 1.7 Gelöschte Daten in Backups

- Gelöschte oder anonymisierte Einsendungen bleiben in vorhandenen Backups, bis diese beim Hoster ablaufen (DS-T-30). Form Captain kann Backups weder durchsuchen noch bereinigen.
- Nach einem Restore löscht der nächste Bereinigungslauf alle Entries mit abgelaufenem `expires_gmt` und abgelaufenem Papierkorb erneut (DS-B-02); der Systemstatus zeigt DS-T-31.
- Privacy-Löschanfragen, die **nach** dem Backup-Datum abgeschlossen wurden, muss der Betreiber erneut ausführen (Werkzeuge → Personenbezogene Daten löschen), weil kein Schattenregister gelöschter Personen existiert (DS-B-04).
- Empfehlung im Handbuch: Backup-Aufbewahrung des Hosters nicht länger als die kürzeste Retention-Einstellung plus Papierkorbfrist wählen.

## 2. Support- und Fehlerprozess (P9-02)

> **Status: entschieden 16.11.2026** (docs/ENTSCHEIDUNGEN.md #12, #13, #15): Free-Support im WordPress.org-Forum, Pro-Support per Ticket über E-Mail mit tarifabhängiger Antwortzeit; Sicherheitsmeldungen an eine eigene `security@…`-Adresse (konkrete Adresse noch einzutragen, Platzhalter `[SECURITY-KONTAKT]`). Nach einem Backup-Restore warnt der Systemstatus; erneutes Ausführen von Löschungen ist Betreiberpflicht.

### 2.1 Kanäle

| Kanal | Für | Stand |
|---|---|---|
| WordPress.org-Supportforum | Free-Fragen, öffentlich | **entschieden** (Nr. 12); Antwortziel: werktags innerhalb von 3 Werktagen (Vorschlag, intern) |
| Ticket per E-Mail `[PRO-SUPPORT-KANAL]` | Pro-Kunden je Tarif: Solo/Studio Standard, Agency priorisiert | **entschieden** (Nr. 12); Adresse und veröffentlichte Antwortzeiten folgen mit dem Pro-Verkaufsstart |
| `[SECURITY-KONTAKT]` (eigene `security@…`-Adresse) | vertrauliche Sicherheitsmeldungen | **entschieden** (Nr. 15); konkrete Adresse offen. Richtlinie: `SECURITY.md`, Vorlage `security.txt` in 2.6 |
| GitHub-Issues | Entwickler/Vertrag (`api-v1.md`), keine Kundendaten | Repository derzeit privat; keine Kundenanfragen über Issues |

Im Forum und in Issues niemals Systemstatus-Berichte mit Site-URL oder Entries anfordern; nur den Diagnosebericht nach 2.2.

### 2.2 Systemstatus-Diagnosebericht (ohne PII)

Erzeugt über *Form Captain → Einstellungen → Systemstatus*, Schaltfläche „Copy report“ / „Bericht kopieren“ (Recht `form_captain_manage_settings`), bzw. `wp form-captain status` (Textformat, identischer Inhalt; `SystemStatus::report_text()`).

**Enthalten:** Plugin-/Pro-Version, Vertrag-Version, WP-/PHP-/DB-Version, Datenbank-Engine je Tabelle, Schemaversion und Migrationszustand (Fehlercode, ohne `detail` mit Pfaden), Anzahl Formulare je Status, Anzahl Entries je Status (gerundet auf 10er), Queue-Zähler je Typ/Status, ältester fälliger Job (Alter in Minuten), Anzahl `dead` letzte 7 Tage je Fehlercode, Cron-Modus (WP-Cron/deaktiviert/System-Cron), letzter Worker- und Cleanup-Lauf (Zeitstempel), Mailtransport erkannt (wp_mail/SMTP-Plugin aktiv ja/nein), letzte 20 Fehlercodes aus `fc_log` mit Korrelations-ID und Zeit, aktive Suite-Plugins mit Version, Multisite ja/nein, Sprache.

**Nicht enthalten:** Site-URL/Domain (optional per Checkbox, Standard aus), Formulartitel, Feldlabels, Feldwerte, E-Mail-Adressen (auch nicht die Absenderadresse – nur „Domain passt zur Site: ja/nein“), Benutzernamen, IP/IP-Hashes, Tokens, `form_captain_secret`, Webhook-/Connector-Secrets, Lizenzschlüssel (nur Status), `last_error_message`-Freitexte.

Prüfung: AC-125/AC-140 und P5-22 gelten auch für den Bericht; automatisiert in `tests/integration/Admin/SystemStatusTest.php::test_report_contains_no_personal_data` (Bericht nach Werten, E-Mail-Adressen und IP durchsuchen → keine Treffer).

### 2.3 Korrelations-IDs

- Jede öffentliche Fehlerantwort enthält `request_id` (32 hex); derselbe Wert steht in `fc_log.correlation_id` mit Fehlercode, Formular-/Entry-/Job-ID (AC-141).
- Besucher sehen nur neutrale Meldung + Referenz (z. B. „Referenz: 9f3a…“); Support fragt nach dieser Referenz und Zeitpunkt.
- Support sucht im Systemstatus nach der ID (Admin) – keine Datenbankzugänge für Support nötig.
- Jobs übernehmen die Korrelations-ID der auslösenden Submission, damit Mail-/Connector-Fehler zuordenbar sind.

### 2.4 Triage

| Stufe | Kriterium | Beispiele | Reaktion (Vorschlag) |
|---|---|---|---|
| S1 – kritisch | Datenverlust, Sicherheitslücke, Einsendungen werden auf vielen Sites nicht gespeichert | `storage_failed` nach Upgrade, Rechteumgehung | sofort; Hotfix-Release, ggf. Rückzug der Version, Info an Betroffene |
| S2 – hoch | Kernfunktion gestört, Workaround vorhanden | Mails nicht zugestellt wegen Bug, Migration hängt | nächster Patch |
| S3 – normal | Teilfunktion fehlerhaft | Exportspalte falsch, Darstellungsfehler | geplantes Release |
| S4 – Frage/Wunsch | Bedienung, Konfiguration, Feature | SMTP-Einrichtung, neuer Feldtyp | Doku/FAQ, Roadmap-Liste |

Ablauf: Eingang → Diagnosebericht + Korrelations-ID anfordern → reproduzieren auf Staging mit anonymisierten Daten → Stufe zuweisen → Ticket mit Fehlercode, Versionen, Reproduktionsschritten (keine Kundendaten) → Fix mit Regressionstest → Release nach `RELEASE.md`.

### 2.5 Datenschutz im Support

- Keine Entries, CSV-Exporte, Datenbank-Dumps oder Admin-Zugänge per Forum/E-Mail anfordern. Ist ein Zugang unvermeidbar: zeitlich befristeter Benutzer mit minimalen Rechten, Auftragsverarbeitung geklärt (`[AVV-REGELUNG]`), Zugang nach Abschluss löschen lassen.
- Vom Kunden ungefragt gesendete personenbezogene Daten im Ticket schwärzen bzw. löschen; Löschung protokollieren.
- Aufbewahrung von Support-Tickets: `[FRIST]`.

### 2.6 Sicherheitsmeldungen

1. Eingang über `[SECURITY-KONTAKT]`; Bestätigung binnen `[FRIST]`.
2. Bewertung (Schwere, betroffene Versionen) – Eintrag nur in nicht öffentlichem Tracker.
3. Fix auf privatem Branch, Regressionstest, Release nach `RELEASE.md`; Changelog „Sicherheit“ ohne Exploit-Details.
4. Koordinierte Veröffentlichung, Credit nach Wunsch des Meldenden; WordPress.org-Plugin-Team informieren, falls Verzeichnisaktion nötig.

Fristen (Vorschlag in `SECURITY.md`, bis zur Bestätigung durch die Produktverantwortung): Eingangsbestätigung binnen 3 Werktagen, Ersteinschätzung binnen 10 Werktagen, Offenlegung spätestens 90 Tage nach Eingang oder nach dem Fix-Release.

**Vorlage `/.well-known/security.txt`** (RFC 9116) für die Produktwebsite, nach Eintragen der Adresse:

```
Contact: mailto:[SECURITY-KONTAKT]
Expires: [Datum in höchstens 12 Monaten, ISO 8601]
Preferred-Languages: de, en
Policy: [URL der veröffentlichten SECURITY.md]
```

**Noch offen (Produktverantwortung):** konkrete Adresse, optional PGP-Schlüssel, Bestätigung der Fristen, Teilnahme an einem Disclosure-Programm.

## 3. Release- und Rollback-Übung (P9-12)

Ziel: nachweisen, dass ein fehlerhaftes Update innerhalb der Zielzeit `[ZIEL-WIEDERHERSTELLUNGSZEIT]` auf die letzte funktionierende Version mit konsistenten Daten zurückgesetzt werden kann. Turnus: vor Free v1.0, vor Pro v1, danach vor jedem Minor-Release mit Migration.

1. **Staging bereitstellen:** Kopie der Referenzinstallation mit WP-/PHP-/DB-Versionen der Produktionsmatrix; Testdaten aus `RELEASE.md` 4; Cron als System-Cron (`wp form-captain jobs run`).
2. **Ausgangszustand dokumentieren:** Systemstatus-Diagnose, Zählungen, Hash der Werte (1.6 Schritt 1).
3. **Backup** nach 1.2 (DB + Dateien), Prüfsummen notieren.
4. **Testrelease installieren:** RC-ZIP (`bin/build-release.sh x.y.z-rc.N`), SHA-256 prüfen, Plugin-Verzeichnis ersetzen, Admin aufrufen → Migration.
5. **Fehler simulieren** (je Übung einer):
   - a) Migration schlägt fehl: dem Datenbankbenutzer vor dem Update das Recht `ALTER` entziehen (`REVOKE ALTER ON <db>.* FROM '<user>'@'<host>'`), oder Abbruch durch `kill` des PHP-Prozesses,
   - b) Fatal Error im neuen Code nach erfolgreicher Migration,
   - c) fehlerhafter Mailversand (SMTP aus) mit wachsender Queue.
6. **Entscheiden** anhand Rollback-Plan (`RELEASE.md` 3.5): Downgrade ohne Restore oder mit Restore.
7. **Wiederherstellen:** Formulare pausieren, Plugin-Verzeichnis durch letzte funktionierende Version ersetzen; bei Bedarf DB-Restore nach 1.3 (Tabellen + Optionen) und Dateien.
8. **Prüfen:** Integritätsprüfung 1.5, Zählung/Hash = Ausgangszustand (abzüglich bewusst verworfener Test-Einsendungen), Testeinsendung, Queue leer bzw. nur erwartete Jobs.
9. **Dokumentieren:** Dauer je Schritt, Abweichungen, Verbesserungen an Skripten/Doku; Ticket „Rollback-Übung x.y.z“ abschließen.

### 3.1 Protokoll der Übung vom 16.11.2026 (lokales Staging)

**Umgebung:** WordPress 6.8.3, PHP 8.3.6 (eingebauter Webserver), MariaDB 10.11, eigener DB-Benutzer mit `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, LOCK TABLES`. Mail-Senke per MU-Plugin. Zielzeit `[ZIEL-WIEDERHERSTELLUNGSZEIT]` ist noch nicht festgelegt.

**Artefakte:** Als letzte funktionierende Version diente der Stand `1a404d8` mit Schema 1 (`form-captain-v1.zip`, SHA-256 `8312784988…3d2a`). Als Release-Kandidat diente der aktuelle Stand mit Schema 2 (`form-captain-1.0.0-rc.1.zip`, SHA-256 `5b74b73b…8022`). Die Migration 1 → 2 ist echt: neue Spalte `consent_withdrawn_gmt` und ein Index.

**Ausgangszustand:** 2 Formulare, 40 Einträge, 200 Werte, Queue leer. Prüfsumme über alle Werte (MD5 über Eintrag, Feld und MD5 des Werts): `bb85fe1c…f9b3`.

| Schritt | Ergebnis | Dauer |
|---|---|---|
| Backup: `fc_*`-Tabellen, `form_captain_*`-Optionen, Plugin-Verzeichnis, SHA-256 notiert | 3 Dateien, Prüfsummen OK | 1 s |
| **a) Fehlgeschlagene Migration:** `ALTER` entzogen, RC installiert | Version bleibt 1. Zustand `migration_failed`, Detail `missing_column:entries.consent_withdrawn_gmt`. Frontend 200, Admin erreichbar | 2 s |
| Einsendung im Fehlerzustand | wird im Schema 1 gespeichert, kein Datenbankfehler außer den abgelehnten `ALTER` (Dokumentation in RELEASE.md 3.2 entsprechend berichtigt) | – |
| Ursache behoben (`GRANT ALTER`), Admin-Seite aufgerufen | Migration setzt automatisch fort, Schema 2, Status OK. Die 40 Ausgangseinträge sind bitgleich (`bb85fe1c…f9b3`) | < 1 s |
| **b) Fatal Error im neuen Code** (kaputte PHP-Datei) | Frontend und Admin HTTP 500 | – |
| Downgrade ohne DB-Restore auf v1 (Schema 2 bleibt) | Frontend 200, Einsendung funktioniert (additive Migration ist abwärtsverträglich). Während des nicht atomaren Dateitauschs kurz HTTP 500 | 1 s |
| Rollback mit DB-Restore (Tabellen, Optionen, Plugin-Dateien aus dem Backup) | Prüfsummen OK. Datenstand gleich dem Ausgangszustand (alle Zähler und die Prüfsumme). Schema 1, Status OK, Testeinsendung OK. Vorher gesichert: 2 Einsendungen seit dem Update (nur gezählt) | < 1 s |

**Befunde und Folgen:**

1. Downgrade wurde nicht erkannt: `maybe_upgrade()` rief `migrate()` nur bei installiert < Ziel auf. **Behoben** mit Test `InstallTest::test_boot_records_downgrade_and_clears_it_after_reinstall`.
2. Der Systemstatus empfahl bei jedem Schemaproblem „deaktivieren/aktivieren“. Jetzt nennt er die Ursache: DB-Rechte bei `migration_failed`, neuere Version oder Backup bei einem Downgrade (`SystemStatusTest::test_schema_problem_messages_name_the_cause`).
3. RELEASE.md 3.2 beschrieb eine Einsende-Sperre bei fehlgeschlagener Migration, die es nicht gibt (und bei additiven Migrationen nicht braucht). **Doku berichtigt.**
4. Der in Schritt 5a genannte Test-Hook existierte nicht. Ersetzt durch den Rechteentzug.
5. Der Dateitausch beim Rollback soll atomar per Umbenennen erfolgen (RELEASE.md 3.4).

**Noch offen:** Wiederholung auf einem echten Staging mit Produktions-Versionsmatrix und System-Cron, Zielzeit festlegen, Szenario c (SMTP aus mit wachsender Queue; das Verhalten ist lokal in docs/MAILZUSTELLUNG.md abgenommen).

## 4. Staging-Rollout und Datenbank-Rollback (P9-13)

**Vor dem Update (Pflicht bei jeder Migration):**
1. Rollback-Plan (`RELEASE.md` 3.5) ausgefüllt; bei nicht additiver Migration Wiederherstellungsweg festgelegt **und auf Staging geübt**, bevor das Produktions-Update startet.
2. Staging mit **anonymisierten** Beispieldaten: Produktionsdaten nur nach Anonymisierung übernehmen; alternativ synthetische Testdaten. Keine Produktionsmails von Staging: Mailversand auf Sink umleiten.
   **Werkzeug:** Auf der Staging-Kopie `WP_ENVIRONMENT_TYPE` auf `staging` setzen, dann `wp eval-file bin/anonymize-staging.php yes` ausführen. Das Skript liegt im Repository, nicht im Plugin-ZIP. Es ersetzt E-Mail-, Telefon-, Text-, Absatz- und versteckte Werte, wobei die Länge erhalten bleibt. Auswahl-, Zahl-, Datums- und Einwilligungswerte bleiben stehen, damit Bedingungen und Exporte testbar bleiben. `user_id` und `context_json` werden entfernt, Notiztexte, Log-Texte und Auftragsnutzdaten geleert, offene Aufträge storniert und E-Mail-Adressen in Formulareinstellungen ersetzt. Ausgegeben werden nur Zähler. Bei `production` und ohne das Argument `yes` verweigert das Skript den Lauf. Test: `tests/integration/Privacy/AnonymizeStagingTest.php`. WordPress-Benutzer und andere Plugins fasst es nicht an; dafür ist eigenes Werkzeug nötig.
3. Laufzeit der Migration auf Staging messen; bei > 30 s Ausführung über `wp form-captain migrate` im Wartungsfenster planen.

**Rollout:**
1. Staging-Update, 24 h Beobachtung (Abschnitt 5), Abnahme durch Verantwortlichen.
2. Produktions-Backup (1.2) unmittelbar vor dem Update; Prüfsummen notieren.
3. Formulare mit hohem Aufkommen optional pausieren (Wartungshinweis).
4. Update; Systemstatus prüfen (Schema, Migration, Queue).
5. Testeinsendung; Formulare reaktivieren.
6. Bei Fehler: Rollback nach `RELEASE.md` 3.4; Einsendungen seit Update vor einem DB-Restore als CSV sichern.

### 4.1 Übung vom 16.11.2026

Auf dem Staging aus der Rollback-Übung (3.1) mit Schema 1:

1. **Anonymisierung:** Der Lauf mit Umgebungstyp `production` wurde verweigert. Mit `staging`: 164 Werte ersetzt, 41 behalten (Einwilligungen), 1 offener Auftrag storniert, Dauer < 1 s. Danach 0 Treffer für Namen, E-Mail-Adressen und Nachrichtentexte der Testpersonen. Alle Zähler (Formulare, Einträge, Werte) unverändert.
2. **Migrationslaufzeit:** Bestand auf 100 041 Einträge vergrößert (Schema 1), dann Update auf den Release-Kandidaten mit Schema 2 durch atomaren Verzeichnistausch. Migration inklusive WordPress-Start: **335 ms** (MariaDB 10.11 fügt die Spalte ohne Tabellenkopie hinzu). Die Grenze von 30 s für ein Wartungsfenster wird weit unterschritten. Auf MySQL 5.7 oder älterem MariaDB vor dem Release erneut messen.
3. **Wiederherstellungsweg:** Migration 1 → 2 ist additiv. Ein Downgrade ohne DB-Restore ist möglich (in 3.1 geprüft). Ein DB-Restore aus Tabellen- und Options-Backup ist in 3.1 geübt; der Datenstand war danach identisch.

## 5. Betriebsüberwachung (P9-14)

Nur aggregierte Statusdaten; keine Inhalte, keine personenbezogenen Daten. Quelle ist derselbe Statusdienst wie für Systemstatus und Diagnosebericht.

### 5.1 Kennzahlen und Schwellen

| Kennzahl | Ermittlung | Warnung | Kritisch |
|---|---|---|---|
| Queue-Rückstand | Anzahl `pending` + `failed` mit `run_after_gmt <= now` | > 50 | > 500 |
| Ältester fälliger Job | `now - min(run_after_gmt)` fälliger Jobs | > 15 min (ADR 0004) | > 60 min |
| Dead-Letter | Anzahl `dead` letzte 24 h, je `type` + `last_error_code` | ≥ 1 | ≥ 10 |
| Mailfehler | Anteil `mail.*`-Jobs mit Fehlversuch letzte 24 h | > 5 % | > 25 % oder alle |
| Integrationsfehler | `connector.action` `failed`/`dead` je Connector-Key letzte 24 h | ≥ 1 `dead` | Connector > 50 % Fehler |
| Cron | Zeit seit letztem Worker-Lauf; `DISABLE_WP_CRON` ohne System-Cron | > 5 min | > 30 min |
| Bereinigung | Zeit seit `form_captain_last_cleanup_at` | > 26 h | > 72 h |
| Schema | `installed_version` ≠ `target_version` oder `migration_state` gesetzt | sofort | `migration_failed` / `schema_newer_than_plugin` |
| Fehlerrate Submission | Anzahl `storage_failed`/`internal_error` in `fc_log` letzte Stunde | ≥ 1 | ≥ 10 |
| Spam-/Rate-Limit-Quote | Anteil `spam`/`rate_limited` je Formular letzte 24 h (nur Zähler) | Info | – |

### 5.2 Anzeige und Abfrage

- **Systemstatus** (Admin): Ampel je Kennzahl mit Fehlercode und Handlungshinweis.
- **Admin-Hinweis:** nur bei *kritisch*, nur für `form_captain_manage_settings`, wegklickbar für 24 h.
- **WP-CLI** (zu implementieren):

```bash
wp form-captain status                 # Tabelle: Kennzahl, Wert, Zustand (ok/warn/crit)
wp form-captain status --format=json   # maschinenlesbar für externes Monitoring
wp form-captain status --check         # Exit-Code 0 = ok, 1 = Warnung, 2 = kritisch
```

  Ausgabe enthält ausschließlich die Kennzahlen aus 5.1, Versionen und Fehlercodes – Felder wie im Diagnosebericht (2.2).
- **Externes Monitoring:** System-Cron ruft `wp form-captain status --check` auf und alarmiert über das Werkzeug des Betreibers. Kein ausgehender Datenversand durch Form Captain selbst (keine Telemetrie, P9-04).

### 5.3 Reaktion

| Zustand | Erste Maßnahme |
|---|---|
| Queue-Rückstand/Cron | Cron-Modus prüfen; `wp form-captain jobs run`; System-Cron einrichten |
| Mailfehler | SMTP-Konfiguration, Absenderdomäne (SPF/DKIM) prüfen; Testversand; betroffene Jobs nach Behebung erneut ausführen |
| Integrationsfehler | Connector-Testverbindung; Zugangsdaten; Ziel-API-Status; Retry erst nach Ursache |
| Schema | `RELEASE.md` 3.2/3.3 |
| Submission-Fehler | Korrelations-ID in `fc_log`, DB-Verbindung/Rechte, ggf. S1-Triage |

## 6. Einstellungen per WP-CLI (SET-FC-14)

Gleiche Prüfung wie die Einstellungsseite (`SettingsSchema::sanitize_group()`); jede Änderung steht im Änderungsprotokoll (*Systemstatus*) mit Quelle „WP-CLI“. Geheime Werte werden nie ausgegeben.

```
wp form-captain settings list [--format=table|json|csv|yaml]   # Schlüssel, Wert, Vorgabe, Typ
wp form-captain settings get privacy.retention
wp form-captain settings set spam.rate_limit 10
wp form-captain settings set privacy.retention 90d --yes       # Aufbewahrung, externer Captcha und Datenlöschung bei Deinstallation nur mit --yes
wp form-captain settings reset spam --yes                      # ein Bereich: general, appearance, email, privacy, spam
wp form-captain settings reset --all --yes
wp form-captain settings export [--file=form-captain-settings.json]
wp form-captain settings import form-captain-settings.json --dry-run   # nur anzeigen
wp form-captain settings import form-captain-settings.json [--yes]     # --yes bestätigt eine geänderte Aufbewahrung
```

Ungültige Werte und unbekannte Schlüssel beim Import werden als Warnung gemeldet und nicht übernommen. Rollenrechte werden nicht per CLI gesetzt (dafür `wp role`/`wp cap`).
