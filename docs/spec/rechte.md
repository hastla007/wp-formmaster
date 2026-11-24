# Capability- und Rollenmatrix (P0-07)

| Capability | Erlaubt | Administrator | Editor | Autor/andere |
|---|---|---|---|---|
| `form_captain_manage_forms` | Formulare anlegen, bearbeiten, veröffentlichen, pausieren, duplizieren, JSON-Import/-Export, Vorschau | ✔ | – | – |
| `form_captain_read_entries` | Entry-Liste und Detail lesen, Notizen lesen | ✔ | – | – |
| `form_captain_edit_entries` | Status ändern, Notizen schreiben, Mail erneut senden | ✔ | – | – |
| `form_captain_export_entries` | CSV-Export | ✔ | – | – |
| `form_captain_delete_entries` | Papierkorb, endgültig löschen, Wiederherstellen | ✔ | – | – |
| `form_captain_manage_settings` | Einstellungen inkl. Datenschutz, Spamschutz, Rollen, Systemstatus | ✔ | – | – |
| `form_captain_manage_integrations` | Connectoren aktivieren, Mapping, Testlauf, Job-Retry | ✔ | – | – |

- Bei Aktivierung erhält **nur** die Rolle `administrator` alle Capabilities. Die Rollenzuweisung lässt sich unter *Einstellungen → Zugriff & Rollen* ändern; dort wird je Rolle angezeigt, welche Nutzeranzahl danach Zugriff auf Entries erhält.
- Die Rolle `administrator` kann die Capability `form_captain_manage_settings` nicht verlieren (Aussperrschutz).
- Menü: Hauptmenü sichtbar für jede der Capabilities; Unterseiten jeweils nur mit der passenden Capability.
- CSV-Eintragsimport: `manage_forms` **und** `edit_entries` für Upload, Zuordnung, Prüfung und Import; Rücknahme eines Imports `manage_forms` **und** `delete_entries` (siehe `rechtevertrag.md` OP-FRM-13/14).
- Multisite (SET-FC-44, Entscheidung 23): Capabilities werden je Site an `administrator` vergeben (bei der Lazy-Installation der Site). Super-Admins **ohne eigene Rolle** auf einer Site erhalten `form_captain_read_entries`, `form_captain_edit_entries`, `form_captain_export_entries` und `form_captain_delete_entries` nicht (`map_meta_cap` → `do_not_allow`), solange `form_captain_network.super_admin_entries` aus ist (Standard). Super-Admins mit eigener Rolle behalten deren Rechte; übrige Capabilities bleiben unberührt. Die Netzwerkseite verlangt `manage_network_options` und zeigt keine Formular- oder Entry-Inhalte.
- Deinstallation mit Datenlöschung entfernt die Capabilities aus allen Rollen.

Die Zuordnung jeder Operation zu Authentisierung und Capability steht in [`rechtevertrag.md`](rechtevertrag.md).
