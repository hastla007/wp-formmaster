# Multisite (P9-08)

**Status:** Planung. Free v1.0 und Pro v1 unterstützen Multisite **nicht** (P0-03, `rechte.md`). Diese Spezifikation ist Voraussetzung für eine spätere Implementierung; sie ändert das v1-Verhalten nicht.
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

## 4. Massenmigration

- Jede Site führt ihren Migrator unabhängig aus (`form_captain_db_version` je Site).
- Nach Plugin-Update auf Netzwerkebene: Migration läuft lazy beim nächsten Request je Site **und** kann aktiv angestoßen werden:
  - Netzwerk-Admin-Aktion „Alle Sites migrieren“: Queue-Job auf der Hauptsite, verarbeitet 10 Sites je Lauf, Fortschritt in `form_captain_network.migration` (`{target, done, failed:[site_ids]}`).
  - WP-CLI: `wp site list --field=url | xargs -n1 -I{} wp --url={} form-captain migrate` bzw. `wp form-captain migrate --network`.
- Fehler auf einer Site blockieren andere Sites nicht; Netzwerkübersicht zeigt fehlgeschlagene Sites mit Fehlercode.
- Downgrade-/Abbruchregeln wie Einzelsite (`RELEASE.md` 3.2/3.3) je Site.
- Testanforderung: Netzwerk mit 200 Sites, davon 5 mit erzwungenem Migrationsfehler.

## 5. Rechte

- Capabilities werden **je Site** an die Rolle `administrator` vergeben (bei lazy Installation der Site).
- Super-Admins besitzen in WordPress implizit alle Capabilities auf jeder Site. Folge: Ein Super-Admin kann Entries jeder Site lesen. Vorschlag: dokumentieren und im Netzwerk-Admin sichtbar machen; optionaler Schalter `form_captain_network.super_admin_entries = false` filtert `form_captain_read_entries`/`export_entries`/`delete_entries` für Super-Admins, die auf der Site keine eigene Rolle haben (`map_meta_cap`). **Entscheidung offen.**
- Netzwerk-Einstellungen (nur Super-Admin, `manage_network_options`): Standard-Retention für neue Sites, Erlaubnis für Sites, die Datenlöschung bei Deinstallation selbst zu aktivieren.

## 6. Lizenzzählung (Pro)

| Option | Regel | Bewertung |
|---|---|---|
| L-1: je Site | jede Site mit aktivem Pro zählt als eine Live-Site im Tarif-Limit (1/5/25) | einfach, passt zu License Captain (Aktivierung je URL); große Netze sprengen Tarife |
| L-2: je Netzwerk | eine Aktivierung für das Netzwerk unabhängig von Siteanzahl | einfach für Kunden, Missbrauchsrisiko (ein Netz für viele Kunden) |
| L-3: je Domain | Sites mit gleicher Hauptdomain (Subdirectory-Netz) zählen einmal, gemappte Domains einzeln | fair, aber komplexer und schwer prüfbar |

- Vorschlag: **L-1** mit Netzwerk-Lizenzseite, auf der der Super-Admin Pro pro Site aktiviert; Staging/Development-Regel aus konzept.md gilt je Site. Bedarf eines Netzwerk-Tarifs erst nach Nachfrage.
- Muss mit License Captain (Aktivierungsmodell, Site-URL-Erkennung) und captain-pro abgestimmt werden. **Entscheidung offen.**

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

## 8. Offene Entscheidungen

1. Super-Admin-Zugriff auf Entries (Abschnitt 5).
2. Lizenzzählung L-1/L-2/L-3 (Abschnitt 6) mit License Captain.
3. Netzwerk-Defaults: welche Einstellungen der Super-Admin vorgeben/sperren darf.
4. Ob Multisite-Unterstützung zuerst nur für Free oder gleichzeitig für Pro freigegeben wird.
