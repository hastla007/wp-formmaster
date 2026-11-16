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
- Multisite ist in v1 nicht unterstützt; Super-Admins werden nicht gesondert behandelt.
- Deinstallation mit Datenlöschung entfernt die Capabilities aus allen Rollen.

Die Zuordnung jeder Operation zu Authentisierung und Capability steht in [`rechtevertrag.md`](rechtevertrag.md).
