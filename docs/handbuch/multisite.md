# Multisite (Netzwerk)

Form Captain läuft in WordPress-Netzwerken (Multisite), sowohl in Subdomain- als auch in Unterverzeichnis-Netzen und mit eigenen Domains je Website. Technische Grundlage: [`../planung/multisite.md`](../planung/multisite.md), Entscheidung 23.

## Jede Website hat ihre eigenen Daten

Formulare, Einsendungen, Warteschlange, Einstellungen, Schlüssel und Rollenrechte gehören immer zu genau einer Website. Eine Website sieht nie die Formulare oder Einsendungen einer anderen, auch nicht über die Datenschutz-Werkzeuge (*Werkzeuge → Personenbezogene Daten exportieren/löschen*) oder die Programmierschnittstelle. Ein Formular-Link oder Absende-Token einer Website funktioniert auf einer anderen nicht.

## Für das ganze Netzwerk aktivieren

Du kannst Form Captain auf einzelnen Websites aktivieren oder in der Netzwerkverwaltung für alle. Bei der Netzwerkaktivierung legt Form Captain nicht sofort für alle Websites Tabellen an – das würde in großen Netzen zu lange dauern. Stattdessen richtet sich jede Website beim ersten Aufruf selbst ein (Tabellen, Rollenrechte für Administratoren, Standardeinstellungen). Neue Websites werden zur Einrichtung vorgemerkt und sind nach ihrem ersten Aufruf vollständig.

Deaktivierst du Form Captain im Netzwerk, entfernt es die geplanten Aufgaben aller Websites. Daten bleiben erhalten. Wird eine Website gelöscht, löscht WordPress auch ihre Form-Captain-Tabellen und privaten Uploads; andere Websites bleiben unberührt.

## Netzwerkseite „Form Captain“

Als Super-Admin findest du in der *Netzwerkverwaltung → Form Captain*:

- **Websites:** Schemaversion, Migrationszustand (aktuell, ausstehend, fehlgeschlagen mit Fehlercode) und den Rückstand der Warteschlange je Website (offen / fehlgeschlagen / pausiert / endgültig fehlgeschlagen). Formular- oder Einsendungsinhalte siehst du hier nie.
- **Alle Websites migrieren:** Nach einem Update aktualisiert jede Website ihre Tabellen beim nächsten Aufruf. Mit dieser Schaltfläche stößt du die Migration für alle an. Sie läuft im Hintergrund auf der Hauptwebsite, 10 Websites je Durchlauf. Schlägt eine Website fehl, laufen die anderen weiter; die Seite zeigt die fehlgeschlagenen Websites mit Fehlercode. Behebe die Ursache (meist fehlende Datenbankrechte `CREATE`/`ALTER`) und starte die Migration erneut.
- **Per WP-CLI** ohne Zeitlimit: `wp form-captain migrate --network` (alle Websites) oder `wp --url=<website> form-captain migrate` (eine Website).

## Super-Admins und Einsendungen

Einsendungen enthalten oft personenbezogene Daten. Deshalb sehen Super-Admins Einsendungen einer Website standardmäßig **nicht**, wenn sie dort keine eigene Rolle haben: Sie können sie weder lesen noch bearbeiten, exportieren oder löschen. Alles andere (Formulare, Einstellungen) bleibt für Super-Admins erreichbar. Hast du auf einer Website eine Rolle, gelten die Rechte dieser Rolle.

Wenn dein Netzwerk es braucht, schaltest du den Zugriff auf der Netzwerkseite unter *Super-Admin-Zugriff auf Einsendungen* frei. Kläre vorher, ob das mit den Datenschutzhinweisen der einzelnen Websites vereinbar ist.

## Vorgaben für neue Websites

Unter *Vorgaben für neue Websites* legst du fest, womit eine Website startet, die Form Captain zum ersten Mal einrichtet: Aufbewahrung der Einsendungen, Tage im Papierkorb, Umgang mit Löschanfragen und Aufbewahrung der Protokolle. Ein leeres Feld bedeutet: Standard des Plugins. Bestehende Websites behalten ihre Einstellungen – Vorgaben werden nie nachträglich übertragen. Jede Website kann ihre Werte danach selbst ändern.

## Löschen des Plugins im Netzwerk

Löschst du Form Captain in der Netzwerkverwaltung, gilt für jede Website ihre eigene Einstellung *Daten bei Deinstallation löschen*: Nur Websites, die das Löschen ausdrücklich bestätigt haben, verlieren ihre Formulare und Einsendungen; alle anderen behalten sie. Auf der Netzwerkseite kannst du den Websites diese Möglichkeit auch ganz nehmen (*Daten beim Löschen des Plugins*); dann bleiben die Daten überall erhalten. Bei sehr großen Netzen lösche das Plugin per WP-CLI (`wp plugin deactivate form-captain --network`, danach `wp plugin uninstall form-captain`), damit kein Zeitlimit des Webservers greift.
