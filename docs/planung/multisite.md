# Multisite (P9-08)

**Status:** Umgesetzt im Kern (Form Captain Free) am 24.11.2026 (SET-FC-44, SET-FC-35, Entscheidung 23). Form Captain Pro folgt mit SET-FC-45 (Lizenz je Site, private Uploads, MS-11); beide werden gemeinsam freigegeben. Handbuch: [`../handbuch/multisite.md`](../handbuch/multisite.md).
**Bezug:** [`../spec/datenmodell.md`](../spec/datenmodell.md), [`../spec/rechte.md`](../spec/rechte.md), [`../RELEASE.md`](../RELEASE.md) Abschnitt 3.

## 1. Grundsatz

**Daten pro Site.** Jede Site eines Netzwerks hat eigene Formulare, Entries, Jobs, Einstellungen, Secrets und Capabilities. Es gibt keine netzwerkweiten Form-Captain-Tabellen und keinen siteübergreifenden Zugriff auf Entries – auch nicht für Netzwerk-Administratoren über die Form-Captain-Oberfläche einer anderen Site.

## 2. Datentrennung

| Bestand | Ort | Hinweis |
|---|---|---|
| Tabellen `fc_*` | Site-Präfix (`wp_2_fc_entries` …) über `$wpdb->prefix` | bereits heute so implementiert (`Tables::name()`) |
| Optionen `form_captain_*` | Site-Optionen | `form_captain_secret` je Site getrennt |
| Netzwerkoption `form_captain_network` | `wp_sitemeta` | nur Netzwerkstatus: Aktivierungsart, Migrationsfortschritt, keine Nutzdaten |
| Private Uploads (Pro) | `uploads/sites/{id}/form-captain-private/` | WordPress-Standardpfad je Site |
| Cron/Queue | WP-Cron je Site | `wp form-captain jobs run --url=…` bzw. Netzwerk-Schleife |
| Rate-Limits, Logs | je Site | – |

- Jede Entry-/Form-ID wird nur im Kontext der aktuellen Site aufgelöst (P5-15); `switch_to_blog()` nur in Netzwerkwerkzeugen, mit `restore_current_blog()` in `finally`.
- Öffentliche API-Funktionen (`form_captain_*_v1`) arbeiten immer auf der aktuellen Site. Suite-Adapter-Idempotenzschlüssel enthalten bereits `{site_id}`.

## 3. Aktivierung

| Fall | Verhalten |
|---|---|
| Einzelaktivierung auf einer Site | wie Einzelinstallation, nur diese Site |
| **Netzwerkaktivierung** | Plugin ist überall geladen; Tabellen werden **lazy** angelegt: beim ersten Admin- oder Frontend-Request einer Site, der Form Captain benötigt. Kein synchrones Anlegen für alle Sites beim Aktivieren (Timeout bei großen Netzen). |
| Neue Site im Netzwerk (`wp_initialize_site`) | bei Netzwerkaktivierung Installation für diese Site einplanen |
| Site gelöscht (`wp_uninitialize_site` / `wpmu_drop_tables`) | `fc_*`-Tabellen der Site zur Löschliste hinzufügen; private Uploads löschen. WordPress löscht die Site vollständig – kein abweichendes Verhalten |
| Netzwerkdeaktivierung | Cron-Hooks je Site entfernen (batchweise), Daten bleiben |
| Deinstallation (Netzwerk) | je Site Regel aus `uninstall.php`: Löschung nur, wenn **diese Site** die bestätigte Löschoption gesetzt hat; Batch über alle Sites per Queue/WP-CLI |

Netzwerk-Admin-Seite *Netzwerkverwaltung → Form Captain*: Liste der Sites mit Schemaversion, Migrationszustand, Queue-Rückstand (aggregiert), Pro-Status; **keine** Formular- oder Entry-Inhalte.

**Umsetzung (SET-FC-44):**

| Teil | Ort |
|---|---|
| Netzwerkoption `form_captain_network` (`activation`, `activated_gmt`, `super_admin_entries`, `allow_site_data_deletion`, `site_defaults`, `migration`) | `src/Network/NetworkSettings.php` |
| Netzwerkaktivierung nur vermerken, Lazy-Installation beim ersten Request (`plugins_loaded` → `Activator::maybe_upgrade()`) | `src/Install/Activator.php` |
| Neue Site: Option `form_captain_install_pending` und einmaliges Cron-Ereignis `form_captain_install_site` (was zuerst kommt, installiert) | `Network::on_initialize_site()` |
| Gelöschte Site: `wpmu_drop_tables` + `fc_*`, `wp_uninitialize_site` (Priorität 5) löscht `form-captain-private/` | `Network::drop_tables()`, `Network::on_uninitialize_site()` |
| Netzwerkdeaktivierung: Cron-Hooks je Site in Stapeln zu 100; Sites mit eigener Aktivierung behalten ihre Hooks | `src/Install/Deactivator.php` |
| Deinstallation je Site nach eigener Löschbestätigung, Stapel über alle Sites, Fehler je Site isoliert | `src/Install/Uninstaller.php` |
| `switch_to_blog()` nur über `Network::in_site()`/`each_site()`, immer mit `restore_current_blog()` in `finally`; einstellungsabhängige Caches werden bei `switch_blog` geleert, der Schema-Cache ist je Site geschlüsselt | `src/Network/Network.php`, `src/Service/FormSchemaLoader.php` |
| Netzwerkseite mit Siteliste (50 je Seite), Migration, Netzwerk-Einstellungen | `src/Admin/NetworkPage.php` |

## 4. Massenmigration

- Jede Site führt ihren Migrator unabhängig aus (`form_captain_db_version` je Site).
- Nach Plugin-Update auf Netzwerkebene: Migration läuft lazy beim nächsten Request je Site **und** kann aktiv angestoßen werden:
  - Netzwerk-Admin-Aktion „Alle Sites migrieren“: Queue-Job auf der Hauptsite, verarbeitet 10 Sites je Lauf, Fortschritt in `form_captain_network.migration` (`{target, done, failed:[site_ids]}`).
  - WP-CLI: `wp site list --field=url | xargs -n1 -I{} wp --url={} form-captain migrate` bzw. `wp form-captain migrate --network`.
- Fehler auf einer Site blockieren andere Sites nicht; Netzwerkübersicht zeigt fehlgeschlagene Sites mit Fehlercode.
- Downgrade-/Abbruchregeln wie Einzelsite (`RELEASE.md` 3.2/3.3) je Site.
- Testanforderung: Netzwerk mit 200 Sites, davon 5 mit erzwungenem Migrationsfehler.
- Umgesetzt in `src/Network/NetworkMigration.php` (Jobtyp `network.migrate`, Fortschritt `{status, run, target, total, done, failed: {site_id: code}, cursor}`; ein neuer Lauf löst einen laufenden ab) und `wp form-captain migrate [--network]` (`src/Cli/Commands.php`). Fehlercodes je Site: `migration_failed`, `migration_running`, `schema_newer_than_plugin`, `site_failed`.

## 5. Rechte

- Capabilities werden **je Site** an die Rolle `administrator` vergeben (bei lazy Installation der Site).
- Super-Admins besitzen in WordPress implizit alle Capabilities auf jeder Site. **Entschieden (Entscheidung 23):** Schalter `form_captain_network.super_admin_entries`, Standard **aus**. Ist er aus, erhält ein Super-Admin ohne eigene Rolle auf der Site über `map_meta_cap` `do_not_allow` für `form_captain_read_entries`, `form_captain_edit_entries`, `form_captain_export_entries` und `form_captain_delete_entries` (`Network::filter_entry_caps()`; „bearbeiten“ ist mit aufgenommen, weil es das Lesen voraussetzt). Super-Admins mit eigener Rolle behalten den Zugriff dieser Rolle; alle anderen Capabilities bleiben unberührt.
- Netzwerk-Einstellungen (nur Super-Admin, `manage_network_options`): Vorgaben für neue Sites (SET-FC-35: `privacy.retention`, `privacy.trash_days`, `privacy.erasure_mode`, `privacy.log_days`; geprüft wie auf der Einstellungsseite, leeres Feld = Plugin-Standard; angewendet nur, wenn eine Site ihre Einstellungen zum ersten Mal anlegt, `Settings::first_install_values()`), Erlaubnis für Sites, die Datenlöschung bei Deinstallation selbst zu aktivieren (`allow_site_data_deletion`, Standard ja). Eine Sperre einzelner Werte gibt es nicht: Entscheidung 23 erlaubt nur Vorgaben, bestehende Sites werden nie still überschrieben.

## 6. Lizenzzählung (Pro)

| Option | Regel | Bewertung |
|---|---|---|
| L-1: je Site | jede Site mit aktivem Pro zählt als eine Live-Site im Tarif-Limit (1/5/25) | einfach, passt zu License Captain (Aktivierung je URL); große Netze sprengen Tarife |
| L-2: je Netzwerk | eine Aktivierung für das Netzwerk unabhängig von Siteanzahl | einfach für Kunden, Missbrauchsrisiko (ein Netz für viele Kunden) |
| L-3: je Domain | Sites mit gleicher Hauptdomain (Subdirectory-Netz) zählen einmal, gemappte Domains einzeln | fair, aber komplexer und schwer prüfbar |

- Vorschlag: **L-1** mit Netzwerk-Lizenzseite, auf der der Super-Admin Pro pro Site aktiviert; Staging/Development-Regel aus konzept.md gilt je Site. Bedarf eines Netzwerk-Tarifs erst nach Nachfrage.
- Muss mit License Captain (Aktivierungsmodell, Site-URL-Erkennung) und captain-pro abgestimmt werden. **Entschieden (Entscheidung 23): L-1**, Umsetzung in Pro (SET-FC-45).

## 7. Tests

| ID | Fall | Erwartung |
|---|---|---|
| MS-01 | Netzwerkaktivierung, Aufruf Site 2 | Tabellen nur für Site 2 angelegt |
| MS-02 | Neue Site anlegen | Installation eingeplant, nach erstem Request vollständig |
| MS-03 | Site löschen | `fc_*` und private Uploads der Site entfernt, andere Sites unberührt |
| MS-04 | Entry-ID von Site 2 auf Site 3 abrufen (REST/Admin) | `entry_not_found` |
| MS-05 | Admin Site 2 ohne Netzwerkrechte | kein Zugriff auf Site 3 |
| MS-06 | Super-Admin mit Schalter aus/an | Verhalten nach Abschnitt 5 |
| MS-07 | Massenmigration 200 Sites, 5 Fehler | 195 migriert, 5 in Fehlerliste, Wiederholung erfolgreich |
| MS-08 | Netzwerkdeinstallation, 1 Site mit bestätigter Löschung | nur diese Site gelöscht |
| MS-09 | Cron je Site, Queue einer Site blockiert | andere Sites unbeeinflusst |
| MS-10 | Privacy-Werkzeuge auf Site 2 | finden nur Entries von Site 2 |
| MS-11 | Pro-Aktivierung auf 6 Sites mit 5-Site-Tarif | 6. Aktivierung abgelehnt, Free intakt |
| MS-12 | Subdomain- und Subdirectory-Netz, Domain-Mapping | Submission-Token, Redirects, Uploads korrekt je Site |

Umgesetzt in `tests/integration/Multisite/MultisiteTest.php` (Gruppe `multisite`, Lauf `vendor/bin/phpunit -c phpunit-multisite.xml.dist`, CI-Job `integration-multisite`): MS-01 bis MS-10 und MS-12; MS-07 mit 200 Sites (Umgebungsvariable `FC_MS_SITES`), davon 5 mit erzwungenem Fehler, Wiederholung per WP-CLI. MS-12 deckt Unterverzeichnis-Site und gemappte Domain in einem Lauf ab; ein reines Subdomain-Netz braucht `SUBDOMAIN_INSTALL` beim Netzaufbau und wird mit der manuellen Release-Prüfung abgenommen. MS-11 gehört zu Pro (SET-FC-45). Zusätzlich: Netzwerkvorgaben nur für neue Sites, Verbot der Datenlöschung, Netzwerkdeaktivierung, Netzwerkseite ohne Inhalte.

## 8. Entscheidungen (Entscheidung 23, 24.11.2026)

1. Super-Admin-Zugriff auf Entries: standardmäßig aus, Netzwerkschalter (Abschnitt 5).
2. Lizenzzählung: L-1, je Site (Abschnitt 6, SET-FC-45).
3. Netzwerk-Defaults: nur Vorgaben für neue Sites, keine Sperre, bestehende Sites werden nie überschrieben (SET-FC-35).
4. Freigabe: Free und Pro gleichzeitig.
