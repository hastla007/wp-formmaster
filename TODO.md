# Form Captain – Umsetzungsliste

**Quelle:** übernommener Abschnitt 9 des Produktkonzepts sowie die ergänzenden Workflow-Captain-Aufgaben für Phase 8. Produktentscheidungen und Architektur bleiben im Konzept maßgeblich.

## 9. Umsetzungsplan und To-do

### Arbeitsregeln und aktueller Projektstand

Das Repository enthält derzeit Konzept und separate TODO.md; Plugin-Code ist noch nicht vorhanden. Diese Datei ist die operative Umsetzungsplanung, übernommen aus Abschnitt 9 des Konzepts. Die Aufgaben sind in Abhängigkeitsreihenfolge angeordnet. Ein Task gilt erst als erledigt, wenn sein Ergebnis im Repository liegt und die angegebene Abnahme erfüllt ist.

**Release-Reihenfolge:** Free v1.0 wird nach Phase 6 veröffentlicht. Pro v1 folgt nach Phase 7 als separates Add-on. Die Suite-Adapter aus Phase 8 werden einzeln und nach den jeweiligen Vertragsfreigaben ausgeliefert. Optionale Post-v1-Funktionen aus Phase 9 blockieren diese Releases nicht.

### Phase 0 – Entscheidungen, Spezifikation und Arbeitsgrundlage

- [x] **P0-01 – Produktgrenze festlegen.** Free ist eigenständig nutzbar; Pro ist ein separates Add-on. Zahlungen, Uploads und Multi-Step sind nicht Teil des Free-MVP.
- [x] **P0-02 – Produktidentität festlegen.** Name Form Captain, Kernslug `form-captain`, Add-on `form-captain-pro`, Lizenz-Produktschlüssel `form-captain-pro`, Rechtepräfix `form.`.
- [x] **P0-03 – Mindestplattform festlegen.** WordPress 6.8+, PHP 8.3+, WooCommerce optional ab 8.0; keine Multisite-Unterstützung in Free v1.0.
- [ ] **P0-04 – v1-Datenmodell als Spezifikation abschließen.** Tabellen, Spalten, Indizes, Fremdschlüssel-Strategie, Statuswerte, UTC-Zeitstempel und Löschverhalten für Formulare, Entries, Entry-Werte, Jobs und Upload-Referenzen dokumentieren.
- [ ] **P0-05 – JSON-Formularschema festschreiben.** Schema-Version, stabile Feld-IDs, Feldtypen, Optionen, Validierungsregeln, bedingte Sichtbarkeit und Migrationsregeln mit Beispielen spezifizieren.
- [ ] **P0-06 – Zustandsmodelle definieren.** Zustände und erlaubte Übergänge für Formular (Entwurf/veröffentlicht/pausiert/archiviert), Entry (neu/gelesen/archiviert/Spam/Papierkorb) sowie Integrationsjob festlegen.
- [ ] **P0-07 – Capability- und Rollenmatrix freigeben.** Rechte zum Verwalten, Lesen, Bearbeiten, Exportieren, Löschen und Konfigurieren konkreten WordPress-Capabilities zuordnen.
- [ ] **P0-08 – Datenschutzentscheidungen operationalisieren.** Retention-Optionen (90 Tage/12 Monate/manuell), Einwilligung, Export, Erasure, Backups, Upload-Ablauf und Deinstallation mit konkreten Admin-Texten und Datenflüssen beschreiben.
- [ ] **P0-09 – Öffentliche API v1 spezifizieren.** Funktionsnamen, versionierte Actions, Ereigniszeitpunkt nach Commit, Payload-Allowlist, Fehlercodes, Capability-Erwartungen, Idempotenz, Deprecation-Regeln und Mindestversionen festlegen.
- [ ] **P0-10 – Datenfluss- und Bedrohungsmodell erstellen.** Öffentliche Submission, Admin, E-Mail, CSV, Export, Upload, Webhook und Suite-Adapter mit Trust Boundaries, gespeicherten Daten und Schutzmaßnahmen abbilden.
- [ ] **P0-11 – MVP-Abnahmekriterien in CI-taugliche Prüfpunkte übersetzen.** Für jede Free-MVP-Funktion mindestens einen nachvollziehbaren manuellen oder automatisierten Abnahmepunkt notieren.
- [ ] **P0-12 – Entwicklungs- und CI-Umgebung festlegen.** Lokales WordPress-Testsystem, PHP-/Datenbankversionen, Gutenberg-Buildwerkzeuge und unterstützte Browser bestimmen; reproduzierbare Start-, Test- und Buildbefehle festhalten.
- [ ] **P0-13 – Queue-/Worker-Architektur entscheiden.** Festlegen, ob WordPress-Cron, Action Scheduler oder ein eigener Worker die Jobs verarbeitet; Verhalten bei deaktiviertem Cron, parallelen Läufen, Sperren und verzögerten Wiederholungen dokumentieren.
- [ ] **P0-14 – Bedrohungsmodell um konkrete Angriffsflächen ergänzen.** SSRF und Redirects bei Webhooks, manipulierte Uploads, MIME-Spoofing, sehr große/komprimierte Dateien, gefälschter Suite-Kontext und öffentlich erreichbare Downloads mit konkreten Schutzregeln erfassen.
- [ ] **P0-15 – Site-Kontext für Suite-Adapter definieren.** Für Event-/Bestellreferenzen festlegen, wie serverseitig signierte, kurzlebige und zweckgebundene Werte erzeugt und validiert werden; vom Nutzer editierbare Hidden Fields gelten nie als Vertrauensnachweis.
- [ ] **P0-16 – Technische Architekturentscheidungen dokumentieren.** PHP-Namespace/Autoloading, WordPress-Admin-UI-Ansatz, REST-Routen, Asset-Build, Datenzugriff und Abhängigkeitsregeln in kurzen ADRs festlegen.
- [ ] **P0-17 – Rechtevertrag je API-Operation definieren.** Öffentliche Submission, Admin-CRUD, Export, Entry-Read und Adapter-Callbacks mit Authentisierung, Capability, erlaubten Scopes und Fehlercodes abgrenzen.
- [ ] **P0-18 – Datenintegrität und Löschsemantik festlegen.** Eindeutige Schlüssel, Transaktionsgrenzen, Soft-/Hard-Delete, abhängige Werte/Jobs und Verhalten bei teilweisem DB-Ausfall dokumentieren.
- [ ] **P0-19 – Technische Budgets festlegen.** Maximale Request-/Formular-/Entry-Größen, Job-Batchgröße, Laufzeitgrenzen, Rate-Limits und unterstützte Datenbankversionen als überprüfbare Defaults definieren.
- **Abnahme Phase 0:** Ein Entwickler kann Datenmodell und öffentliche Verträge implementieren, ohne offene Grundsatzentscheidungen zu erraten. Keine Suite-Integration hängt von einer privaten Schnittstelle ab.

### Phase 1 – Repository, Plugin-Gerüst und Datenbasis

- [ ] **P1-01 – Repository-Grundstruktur anlegen.** Plugin-Hauptdatei, `src/`, `assets/`, `languages/`, `templates/`, `tests/`, `bin/` und Dokumentationsordner nach einer festgelegten Namespace-/Autoloading-Konvention einrichten.
- [ ] **P1-02 – Projektwerkzeuge konfigurieren.** Composer-Entwicklungsabhängigkeiten, PHP-Lint, WordPress Coding Standards, statische Analyse und Testskripte mit festgelegten Mindestversionen einrichten.
- [ ] **P1-03 – Plugin-Bootstrap bauen.** Header, Textdomain, Versionskonstanten, Autoloader, Mindestversion-Prüfung und kontrollierter Start bei fehlendem/zu altem WordPress implementieren.
- [ ] **P1-04 – Aktivierung und Deaktivierung implementieren.** Aktivierung führt nur versionierte Migrationen aus; Deaktivierung stoppt geplante Jobs und entfernt keine Nutzdaten.
- [ ] **P1-05 – Schema-Migrationsdienst erstellen.** Gespeicherte Schema-Version prüfen, Migrationen wiederholbar ausführen, Fehler melden und bei abgebrochener Migration einen sicheren Zustand hinterlassen.
- [ ] **P1-06 – Tabellen für Formulare und Entries erstellen.** Die freigegebene Spezifikation mit passenden Indizes, eindeutigen IDs und WordPress-Präfix umsetzen.
- [ ] **P1-07 – Tabelle für Entry-Werte und optionale Job-Metadaten erstellen.** Antwortwerte getrennt speichern; keine vollständigen Submission-Payloads in Logs oder Optionen duplizieren.
- [ ] **P1-08 – Repository-/Service-Schicht einführen.** DB-Zugriff hinter testbaren Repositories kapseln; Formular-, Entry-, Submission- und Queue-Logik nicht in Admin- oder REST-Handlern ablegen.
- [ ] **P1-09 – Status- und Fehlerkonstanten definieren.** Stabile interne Codes und öffentlich dokumentierte Fehlercodes mit Übersetzungen bereitstellen.
- [ ] **P1-10 – Capabilities bei Aktivierung registrieren.** Standardrollen minimal berechtigen; Administrator kann alle Aufgaben ausführen; keine automatische Berechtigung zum Lesen sensibler Entries für Editor.
- [ ] **P1-11 – Setup-/Systemstatusdaten begrenzen.** Nur notwendige Einstellungen speichern; keine personenbezogenen Daten oder Secrets im Klartext in Diagnosen ausgeben.
- [ ] **P1-12 – Lokale WordPress-Umgebung und CI einrichten.** Plugin automatisiert auf leerer Installation installieren/aktivieren; Datenbankdienst, WP-CLI, Testdaten und reproduzierbare Testkommandos bereitstellen.
- [ ] **P1-13 – Frontend-Asset-Build konfigurieren.** Gutenberg-/Admin-JavaScript und CSS versioniert bauen, Produktionsassets reproduzierbar erzeugen und Quell-/Builddateien klar trennen.
- [ ] **P1-14 – Autoloading und Produktionsabhängigkeiten festschreiben.** Composer-Lockfile und Produktions-/Dev-Abhängigkeiten trennen; Release enthält keine ungenutzten Dev-Pakete oder unkontrollierten Vendor-Kopien.
- [ ] **P1-15 – Dependency- und Secret-Prüfungen in CI ergänzen.** Veraltete/unsichere Composer-Pakete, versehentlich eingecheckte Secrets und Lizenzhinweise der ausgelieferten Bibliotheken automatisiert prüfen.
- [ ] **P1-16 – Datenbankintegrität prüfen.** Tabellenengine, Indexe, eindeutige Idempotenzschlüssel, Fremdbeziehungen und Verhalten ohne verfügbare Transaktionen auf unterstützten MySQL-/MariaDB-Versionen verifizieren.
- [ ] **P1-17 – Strukturierte, datensparsame Logs implementieren.** Korrelations-ID pro Submission/Job, Fehlercode und Zeit erfassen; Formularantworten, rohe IPs, Tokens und Secrets standardmäßig redigieren.
- **Abnahme Phase 1:** Frische Installation und Upgrade erzeugen dasselbe korrekte Schema; Deaktivierung löscht keine Daten; automatisierte Tests können Repositories und Migrationen isoliert prüfen.

### Phase 2 – Formularverwaltung und Editor

- [ ] **P2-01 – Admin-Menü registrieren.** Menüeinträge für Dashboard, Formulare, Einträge, Integrationen und Einstellungen mit passenden Capabilities anlegen.
- [ ] **P2-02 – Formularübersicht bauen.** Name, Status, Entry-Anzahl und Änderungsdatum anzeigen; Suche, Filter, Pagination und leere Zustände ergänzen.
- [ ] **P2-03 – Formularaktionen absichern.** Bearbeiten, Duplizieren, Vorschau, Pausieren, Exportieren und Löschen serverseitig mit Capability und Nonce schützen.
- [ ] **P2-04 – Vorlagenauswahl implementieren.** Kontakt-, Angebots- und Supportvorlage als versionierte, importierbare Formdefinitionen bereitstellen.
- [ ] **P2-05 – Editor-Grundlayout erstellen.** Feldpalette, Canvas, Einstellungsbereich und Desktop-/Mobilvorschau übersichtlich und tastaturbedienbar aufbauen.
- [ ] **P2-06 – Feldmodell mit stabilen IDs umsetzen.** Felder hinzufügen, entfernen, duplizieren, sortieren und konfigurieren, ohne IDs beim Speichern unbeabsichtigt zu ändern.
- [ ] **P2-07 – Free-Feldtypen implementieren.** Text, Absatz, E-Mail, Telefon, Zahl, Auswahl, Checkbox, Optionsfeld, Datum, Überschrift/HTML-Hinweis, versteckter Wert und Einwilligung.
- [ ] **P2-08 – Feldvalidierung im Editor umsetzen.** Pflichtstatus, Labels, Hilfetext, Platzhalter, Standardwert, Zeichen-/Wertebereich und Feldschlüssel prüfen.
- [ ] **P2-09 – HTML-Hinweise sicher begrenzen.** Erlaubte Markup-Tags definieren und HTML beim Speichern sowie bei Ausgabe konsequent bereinigen.
- [ ] **P2-10 – Einfache bedingte Feldanzeige umsetzen.** Eine Regel pro Feldgruppe unterstützen; Regeln clientseitig darstellen und serverseitig unabhängig validieren.
- [ ] **P2-11 – Formular- und Benachrichtigungseinstellungen ergänzen.** Admin-Empfänger, Antwortadresse, Betreff, Nutzerbestätigung, Erfolgsmeldung und Redirect konfigurieren lassen.
- [ ] **P2-12 – Entwurf, Veröffentlichung und Pause implementieren.** Ungültige Formulare dürfen nicht veröffentlicht werden; pausierte Formulare zeigen eine konfigurierbare, neutrale Meldung.
- [ ] **P2-13 – Editor-Speicherung gegen Konflikte absichern.** Schema-Version beim Speichern prüfen und parallele Änderungen mit verständlicher Konfliktmeldung behandeln.
- [ ] **P2-14 – Vorschau ohne echte Submission anbieten.** Vorschau darf keine Entry-Datensätze, Benachrichtigungen oder externen Aktionen erzeugen.
- [ ] **P2-15 – Entwurfs- und Vorschauzugriff absichern.** Vorschau-Links für unveröffentlichte Formulare kurzlebig und zufällig machen; Vorschau darf ohne Berechtigung keine Antworten speichern oder Formularschema offenlegen.
- [ ] **P2-16 – Schemaänderungen für bestehende Entries festlegen.** Geänderte/entfernte Felder, neue Pflichtfelder und Optionen so versionieren, dass historische Entries mit dem ursprünglichen Schema lesbar bleiben.
- [ ] **P2-17 – Einstellungsstruktur und globale Voreinstellungen spezifizieren.** Tabs Allgemein, Darstellung, E-Mail, Datenschutz & Speicherung, Spamschutz, Zugriff & Rollen und Systemstatus anhand Abschnitt 6.1 umsetzen; Integrationen und formularbezogene Optionen klar ausgrenzen.
- [ ] **P2-18 – WordPress-Menü und Settings-Navigation bauen.** Dashboard, Formulare, Einträge, Integrationen und Einstellungen als schlanke Plugin-Navigation anlegen; innerhalb der Einstellungen klare Tabs, Seitentitel, Breadcrumbs/Zurückwege und leere Zustände anbieten.
- [ ] **P2-19 – Einstellungen über die WordPress Settings API speichern.** Pro Option Schema, Typ, Standardwert, Sanitizing, Validierung, Capability, Nonce und verständliche Fehlermeldung definieren; keine beliebigen Optionen oder ungeprüfte Werte übernehmen.
- [ ] **P2-20 – Globale Standards und Formularwerte trennen.** E-Mail-Absender und Darstellung dürfen als neue Formular-Defaults dienen; Empfänger, Reply-To, Betreff, Feld-Mapping, Bestätigung und Redirect bleiben formularbezogen. Änderung globaler Defaults darf veröffentlichte Formulare nicht unbemerkt ändern.
- [ ] **P2-21 – Darstellungseinstellungen mit sicherem Reset umsetzen.** Theme-Styling ist Standard; globale Basisdarstellung und Formular-Ausnahme samt Vorschau/Zurücksetzen bereitstellen, ohne fremde Themes zu überschreiben oder externe Assets nachzuladen.
- [ ] **P2-22 – E-Mail-Einstellungen als Zustellhilfe gestalten.** Standard-Absender aus Website-Identität übernehmen, Testnachricht und verständliche Zustell-/Konfigurationshinweise anbieten; keine SMTP-Verbindung oder geheimen Mailserver-Zugangsdaten im Form-Captain-Kern verwalten.
- [ ] **P2-23 – Navigationsorte für Import, Export und Lizenz festlegen.** Formular-JSON Import/Export in der Formularübersicht, Entry-CSV-Export in Einträge und Pro-Lizenz/Updates nur bei installiertem Add-on anbieten; keine redundanten Optionen in mehreren Menüs.
- [ ] **P2-24 – Einstellungsänderungen nachvollziehbar machen.** Speichern, Validierungsfehler, Zurücksetzen und Änderungen mit Datenfolgen klar bestätigen; bei globalen Defaults anzeigen, welche Formulare unverändert eigene Werte verwenden.
- **Abnahme Phase 2:** Ein Administrator findet jede Einstellung im erwartbaren Bereich, kann speichern und zurücksetzen; Formular-spezifische Optionen werden nicht mit globalen Defaults verwechselt.

### Phase 3 – Frontend, Einbettung und sichere Submission

- [ ] **P3-01 – Formular-Renderer erstellen.** JSON-Schema in semantisches, responsives HTML umwandeln; keine unnötigen externen Fonts oder Skripte laden.
- [ ] **P3-02 – Gutenberg-Block bauen.** Formularauswahl, Vorschau im Editor und verständlichen Fallback bei gelöschtem/pausiertem Formular bereitstellen.
- [ ] **P3-03 – Shortcode bauen.** Formular-ID validieren, Ausgabe über denselben Renderer erzeugen und ungültige IDs sicher behandeln.
- [ ] **P3-04 – Barrierefreie Feldkomponenten implementieren.** Labels, Pflichtfeldhinweise, Fehlerzuordnung, Tastaturbedienung, Fokusmanagement und Screenreader-Ansagen ergänzen.
- [ ] **P3-05 – Servervalidierung pro Feldtyp implementieren.** Typ, Pflichtfeld, Zeichen-/Wertebereich, erlaubte Optionen, E-Mail/Datum und bedingte Sichtbarkeit prüfen.
- [ ] **P3-06 – Submission-Endpunkt definieren und umsetzen.** Nur definierte öffentliche Operationen erlauben; Requestgröße, Formularstatus und Herkunftskontext prüfen.
- [ ] **P3-07 – Submission-Daten normalisieren.** Unbekannte Feld-IDs, doppelte Werte, unerwartete Arrays und nicht sichtbare bedingte Felder ablehnen oder nach klarer Regel verwerfen.
- [ ] **P3-08 – Honeypot und Mindestzeit implementieren.** Spamverdacht ohne unnötige personenbezogene Protokollierung bewerten.
- [ ] **P3-09 – Rate-Limit implementieren.** Pro Formular und zeitlich begrenztem, gehashtem Kontext drosseln; Speicherbereinigung und Fehlermeldung definieren.
- [ ] **P3-10 – Bestätigungsablauf implementieren.** Nach erfolgreicher Speicherung Meldung oder vorher freigegebenes Redirect ausgeben; Fehler behalten Eingaben soweit sicher und sinnvoll.
- [ ] **P3-11 – CSRF-/Nonce-Strategie festlegen.** Öffentliche Formulare dürfen nicht auf kurzlebige Admin-Nonces angewiesen sein; Missbrauchsschutz und Cache-Kompatibilität gemeinsam absichern.
- [ ] **P3-12 – Fehler- und Erfolgsmeldungen übersetzen.** Keine internen Exception-, SQL- oder Providerdetails an öffentliche Besucher ausgeben.
- [ ] **P3-13 – REST-/Submission-Routen vollständig absichern.** HTTP-Methode, Permission-Callback, CORS, Request-Limits und Antwortschema explizit definieren; Admin-REST-Nonce nicht als öffentlichen Besucherschutz missbrauchen.
- [ ] **P3-14 – Cache- und Wiederholungsverhalten technisch absichern.** Formularausgabe darf keine veralteten Nonces enthalten; Einmal-/Idempotenzwerte dürfen durch Seiten-Caches nicht zwischen Besuchern geteilt werden.
- [ ] **P3-15 – Redirects gegen Open Redirect absichern.** Nur interne, erlaubte Ziele oder explizit geprüfte Ziele zulassen; Redirect-Parameter aus der Submission niemals direkt übernehmen.
- [ ] **P3-16 – Frontend-JavaScript als progressive Verbesserung bauen.** Validierung und Bedingungen funktionieren serverseitig weiter; mehrere Formulare, Minifizierung, verzögerte Skripte und Theme-Konflikte testen.
- [ ] **P3-17 – Eingabe-/Ressourcenlimits durchsetzen.** Maximale Anzahl Felder/Optionen, verschachtelte Payloads, Feldwertlängen und Requestgröße begrenzen und Überlast verständlich zurückweisen.
- [ ] **P3-18 – Yoast-Kompatibilität ohne Laufzeitabhängigkeit absichern.** Yoast SEO Free/Premium defensiv erkennen; bei deaktiviertem oder fehlendem Plugin alle Form-Captain-Funktionen ohne Warnschleife oder Fatal Error betreiben.
- [ ] **P3-19 – Yoast-Shortcode-Analyse optional integrieren.** Den offiziellen Filter `yoast.analysis.shortcodes` nur verwenden, wenn verfügbar; ausschließlich sichtbare Labels/Hilfetexte bereitstellen und niemals Submission-Werte oder private Daten in die Analyse geben.
- [ ] **P3-20 – Yoast-Metadatenhoheit bewahren.** Keine eigenen Filter für Yoast-Titel, Meta-Beschreibung, Canonical, Robots, Open Graph, X-Tags oder Sitemap setzen; Formular auf einer Seite darf deren Yoast-Einstellungen nicht ändern.
- [ ] **P3-21 – Doppelte Indexierungsobjekte und Schema verhindern.** Keine öffentliche Formular-URL, öffentliche Entry-Seite, Formular-CPT-Sitemap oder eigenes konkurrierendes JSON-LD ausgeben; Formular bleibt eingebetteter Seiteninhalt.
- [ ] **P3-22 – Yoast-Block/Shortcode-Ausgabe crawlbar und zugänglich halten.** HTML serverseitig rendern; sichtbare Labels, Hilfetexte und Pflichtfeldhinweise ausgeben; Form-Assets nur auf Seiten mit Form-Captain-Block/Shortcode laden.
- [ ] **P3-23 – SEO-Hinweis in Block und Dokumentation ergänzen.** Erklären, dass Seiteninhalt, Überschriften, Seitentitel, Meta-Beschreibung und Indexierungsregeln in WordPress/Yoast gepflegt werden und ein Formular keinen hilfreichen Seiteninhalt ersetzt.
- **Abnahme Phase 3:** Formular funktioniert in Block und Shortcode, bei aktivem Seiten-Cache sowie ohne JavaScript-Grundfunktion; ungültige oder manipulierte Requests werden serverseitig abgewiesen; Yoast-Metadaten und Indexierung bleiben unverändert.

### Phase 4 – Entries, E-Mail und Verwaltung

- [ ] **P4-01 – Submission-Service implementieren.** Ablauf zentral orchestrieren: Formular laden, Felder validieren, Spam prüfen, Entry transaktional speichern und erst nach Commit Folgejobs anlegen.
- [ ] **P4-02 – Entry-Werte mit Schema-Version verknüpfen.** Eintrag bleibt auch nach Formularänderung verständlich; gelöschte Felder werden nicht fälschlich neuen Werten zugeordnet.
- [ ] **P4-03 – Eindeutige Request-/Idempotenzreferenz setzen.** Wiederholte HTTP-Requests dürfen nicht unkontrolliert doppelte Geschäftsaktionen oder E-Mails erzeugen.
- [ ] **P4-04 – Entry-Übersicht bauen.** Formular, Zeit, Status und vorhandene Kontaktfelder zeigen; Suche, Datums-/Formularfilter und Pagination ergänzen.
- [ ] **P4-05 – Entry-Detailseite bauen.** Antworten, Quelle, Zeitstempel, Status, interne Notiz und Spamgrund anzeigen; unnötige IP-/Browserdaten weglassen.
- [ ] **P4-06 – Statusaktionen umsetzen.** Neu/gelesen/archiviert/Spam/Papierkorb sowie Bulk-Aktionen mit Capability, Nonce und Audit-Informationen sichern.
- [ ] **P4-07 – Admin-Mail als Queue-Aufgabe senden.** Empfänger, Reply-To, Betreff und explizit gewählte Felder unterstützen; sensible Felder standardmäßig nicht in E-Mail aufnehmen.
- [ ] **P4-08 – Nutzerbestätigung optional senden.** Nur an validierte E-Mail-Adresse; neutrale Bestätigung, konfigurierbare Inhalte und klare Fehlerdiagnose bereitstellen.
- [ ] **P4-09 – Versandstatus sichtbar machen.** Queue-Status, letzter Fehler und Wiederholungsaktion für berechtigte Admins anzeigen; keine Secrets oder vollständige Feldwerte protokollieren.
- [ ] **P4-10 – Interne Notizen implementieren.** Nur berechtigte Nutzer sehen sie; Notizänderungen verändern keine ursprüngliche Submission.
- [ ] **P4-11 – CSV-Export bauen.** Datums-/Formularfilter und Spaltenauswahl; CSV-Formelinjektion, Zeichensatz, Zeilenumbrüche und große Exporte berücksichtigen.
- [ ] **P4-12 – JSON-Export/Import für Formulare implementieren.** Nur Formulardefinitionen übertragen, keine Entries, Uploads oder Secrets ungefragt; Import vor Speicherung validieren.
- [ ] **P4-13 – Papierkorb und endgültige Löschung umsetzen.** Wiederherstellung zeitlich begrenzen; endgültige Löschung entfernt Werte, Referenzen und zugehörige Dateien.
- [ ] **P4-14 – Dauerhafte Jobverarbeitung umsetzen.** Jobs erst nach Entry-Commit dauerhaft einreihen; Worker mit Sperren, begrenzten Batches, Backoff, Maximalversuchen und Dead-letter-Status bauen.
- [ ] **P4-15 – Queue-Ausfallpfade verifizieren.** Doppelte und parallele Worker, deaktivierten WP-Cron, Timeout, Datenbankfehler, wiederkehrende Providerfehler und manuelle Wiederholung testen; Submission darf in keinem Fall verloren gehen.
- [ ] **P4-16 – Entry-Commit und Outbox idempotent koppeln.** Eindeutiger Datenbankschlüssel verhindert doppelte Folgejobs; nach Commit darf ein Prozessabbruch weder Entry noch zugehörige Mail-/Integrationsarbeit verlieren.
- [ ] **P4-17 – E-Mail-Inhalte und Header absichern.** Headerwerte gegen CRLF-Injection validieren; HTML-Ausgabe escapen; Text-/HTML-Version, Feld-Allowlist und Umgang mit sensiblen Antworten festlegen.
- [ ] **P4-18 – Löschungen mit offenen Jobs synchronisieren.** Entry-Löschung/Erasure verhindert spätere E-Mail- oder Webhook-Auslieferung mit bereits gelöschten Werten; Konfliktfälle werden nachvollziehbar protokolliert.
- **Abnahme Phase 4:** Erfolgreiche Submission ist dauerhaft gespeichert, unabhängig von E-Mail/Connector-Ausfällen; berechtigte Nutzer können Einträge verwalten, suchen und sicher exportieren.

### Phase 5 – Datenschutz, Sicherheit und Betrieb

- [ ] **P5-01 – Privacy Exporter integrieren.** Entries zu einer verifizierten Person auffindbar und als lesbarer Export ausgeben.
- [ ] **P5-02 – Privacy Eraser integrieren.** Löschung oder Anonymisierung nach klarer Zuordnung ausführen und abhängige Werte/Dateien behandeln.
- [ ] **P5-03 – Retention-Einstellung implementieren.** 90 Tage/12 Monate/manuell anbieten; Ablaufdaten berechnen, bevorstehende Löschung im Admin sichtbar machen und Löschjob protokollieren.
- [ ] **P5-04 – Geplanten Bereinigungsjob robust bauen.** Batchweise löschen, Wiederholbarkeit sicherstellen, bei Fehlern fortsetzen und keine großen Laufzeiten pro Cron-Aufruf verursachen.
- [ ] **P5-05 – Datenbereinigung und Exportgrenzen prüfen.** Anonymisierte/gelöschte Entries dürfen nicht über CSV, API, Queue oder Backups als aktive Datensätze wieder erscheinen.
- [ ] **P5-06 – Uninstall-Verhalten umsetzen.** Standardmäßig Daten behalten; vollständige Löschung nur über explizite Einstellung und dokumentierte Bestätigung.
- [ ] **P5-07 – Admin- und öffentliche Requests auf Rechte prüfen.** Capability, Nonce/Token, Parameter, Requestgröße, Rate-Limit und Datenzugriff in jeder Route einzeln testen.
- [ ] **P5-08 – SQL-/HTML-/Mail-Sicherheit prüfen.** Prepared statements, Output escaping, Sanitizing, Header-Injection-Schutz und sichere Fehlerbehandlung verifizieren.
- [ ] **P5-09 – Logs datensparsam gestalten.** Keine vollständigen Submissionwerte, Roh-IP, E-Mail-Adressen, Tokens oder Webhook-Secrets in Standardlogs schreiben.
- [ ] **P5-10 – Systemstatusseite implementieren.** WP/PHP-Version, Schema-Version, Cron-/Queue-Status, Mailkonfiguration und erkannte Suite-Plugins ohne Geheimnisse anzeigen.
- [ ] **P5-11 – Rollen- und Mandantengrenzen manuell prüfen.** Autor, Editor und nicht berechtigte Rollen dürfen keine fremden Entries lesen/exportieren oder Admin-Aktionen ausführen.
- [ ] **P5-12 – Sicherheitsreview durchführen.** Submission, Admin, Export, CSV, Import, Cron, Mail und Löschung unabhängig prüfen; Befunde beheben und nachvollziehbar schließen.
- [ ] **P5-13 – Backup-/Restore-Verfahren definieren und testen.** Formulare, Entries, Werte, Jobzustände und Pro-Dateireferenzen auf einer separaten Installation sichern und wiederherstellen; Wiederherstellung und Datenintegrität dokumentieren.
- [ ] **P5-14 – Datenlebenszyklus einschließlich Backups abnehmen.** Retention, Privacy-Erasure, Papierkorb, Queue-Kopien und Backup-Ablauf so abstimmen, dass gelöschte Daten nicht als aktive Entries wieder auftauchen.
- [ ] **P5-15 – Objektzugriff gegen IDOR prüfen.** Jede Entry-/Form-ID wird nach Capability und Site-Zugehörigkeit geprüft; erratene IDs dürfen keine Daten preisgeben oder fremde Datensätze ändern.
- [ ] **P5-16 – Tokens und Secrets sicher speichern.** Webhook-Secrets, Vorschau-/Fortsetzen-Token und Signaturschlüssel nie im Klartext loggen; Rotation, Widerruf und Löschung dokumentieren.
- [ ] **P5-17 – Datenschutz über alle Kopien testen.** Export, Mailqueue, Webhookqueue, Papierkorb, Logs und Upload-Referenzen auf Lösch-/Retention-Verhalten prüfen.
- [ ] **P5-18 – Datenschutz-Einstellungen mit sicheren Defaults umsetzen.** 12 Monate Standard und 90 Tage/12 Monate/manuell wählbar; Consent niemals vorangekreuzt; Datenschutzhinweis und anstehende/konkrete Löschfolgen sichtbar machen.
- [ ] **P5-19 – Spamschutz-Optionen datensparsam konfigurieren.** Honeypot, Mindestzeit und Rate-Limit standardmäßig aktivieren; externe Captcha-Anbieter bleiben opt-in mit Anbieter, Datenfluss und erforderlichem Datenschutzhinweis vor Aktivierung.
- [ ] **P5-20 – Einstellungsrechte und Rollenänderungen absichern.** Nur passende Capabilities dürfen Datenschutz-, Integrations-, Rollen- oder Mailoptionen ändern; Änderungen an Entry-Lese-/Exportrechten mit aktualisiertem Zugriff verständlich anzeigen.
- [ ] **P5-21 – Einstellungs-Migrations- und Rücksetzverhalten sichern.** Neue Versionen ergänzen Defaults ohne bestehende Werte zu überschreiben; Reset fragt nach Konsequenzen und setzt nur den gewählten Bereich zurück.
- [ ] **P5-22 – Diagnoseausgabe aus Einstellungen datenschutzprüfen.** Queue-/Mail-/Systemdiagnose enthält Status und sichere Fehlercodes, aber keine Submission-Inhalte, Tokens, Secrets oder personenbezogenen Details.
- **Abnahme Phase 5:** Daten können exportiert, gelöscht und automatisch bereinigt werden; alle Admin-/öffentlichen Datenzugriffe haben explizite Rechte- und Eingabeprüfungen.

### Phase 6 – Free v1.0 Qualität und Veröffentlichung

- [ ] **P6-01 – Automatisierte Testbasis aufbauen.** Unit-Tests für Schema, Validatoren, Bedingungen, Statuswechsel und CSV-Bereinigung; Integrationstests für DB/Migrationen/Submission.
- [ ] **P6-02 – WordPress-Testmatrix einrichten.** Mindest- und aktuelle WordPress-Version sowie unterstützte PHP-Versionen in CI prüfen.
- [ ] **P6-03 – WooCommerce-Basiskompatibilität verifizieren.** Form Captain Free funktioniert ohne WooCommerce; bei aktiver Shopinstallation greift es nicht in Warenkorb, klassischen/Block-Checkout, Orderdaten oder HPOS ein. Das Pro-Fragebogenmodul wird separat in P8 und der WooCommerce-Matrix abgenommen.
- [ ] **P6-04 – Kompatibilität und Konfliktfälle prüfen.** Block-Editor, Classic Editor, Caching, gängige Security-/SMTP-Plugins und mehrere Formulare auf einer Seite testen.
- [ ] **P6-05 – Last-/Missbrauchsgrenzen prüfen.** Große Formulare, viele Entries, CSV-Export, parallele Einsendungen, Spamwellen und fehlende Cron-Ausführung testen.
- [ ] **P6-06 – Accessibility-Abnahme durchführen.** Tastatur, Screenreader, Fokusreihenfolge, Fehleransagen, Kontrast und Mobilansicht an repräsentativen Formularen abnehmen.
- [ ] **P6-07 – Übersetzungsbasis erstellen.** Textdomain `form-captain`, POT-Erzeugung und vollständige deutsche/englische Texte für Admin, Frontend, E-Mails und Fehler etablieren.
- [ ] **P6-08 – Readme und Produktdokumentation fertigstellen.** Installation, Feldtypen, Rechte, externe Datenflüsse, Datenschutz, Mailzustellung, Retention, FAQ und Supportgrenzen dokumentieren.
- [ ] **P6-09 – Lizenz- und Pro-Prompts im Free prüfen.** Upgrade-Hinweise sparsam, kontextbezogen, wegklickbar und ohne künstliche Funktionssperre im Free gestalten.
- [ ] **P6-10 – Build reproduzierbar machen.** Release-ZIP ohne Entwicklungsdateien/Secrets bauen; Versionsnummer, Checksums und Release-Notizen erzeugen.
- [ ] **P6-11 – Upgrade-/Rollback-/Deinstallationspfad abnehmen.** Alte Schema-Versionen migrieren, abgebrochene Migration testen, Downgrade-Verhalten dokumentieren und Nutzdaten standardmäßig behalten.
- [ ] **P6-12 – WordPress.org-Einreichung vorbereiten.** Richtlinien, Assets, Screenshots, Datenschutzhinweise, SVN/Releaseprozess und Supportkanal prüfen.
- [ ] **P6-13 – Browser-E2E für den Hauptablauf einrichten.** Formular erstellen, veröffentlichen, auf einer Seite einbetten, absenden, Entry verwalten und exportieren automatisiert in unterstützten Browsern abdecken.
- [ ] **P6-14 – Upgrade-Matrix abnehmen.** Mindestens Ausgangsversion, Schema-Upgrades, unterbrochene Migration, Wiederaufnahme und Rollback-/Wiederherstellungsverhalten mit repräsentativen Daten prüfen.
- [ ] **P6-15 – Admin-Oberfläche barrierefrei abnehmen.** Dashboard, Editor, Entry-Liste/-Detail und Einstellungen zusätzlich zum Frontend mit Tastatur, Screenreader, Fokusführung und Mobilansicht prüfen.
- [ ] **P6-16 – Security- und Robustheitstests automatisieren.** Manipulierte Payloads, Grenzwerte, SQL-/HTML-Injection, CSV-Formeln, Replay, parallele Requests und fehlerhafte Migrationen abdecken.
- [ ] **P6-17 – Datenbank- und Browsermatrix präzisieren.** CI mindestens gegen festgelegte MySQL-/MariaDB-Versionen sowie die unterstützten Browser-/Block-Editor-Kombinationen laufen lassen.
- [ ] **P6-18 – Release-ZIP technisch prüfen.** ZIP-Install, Dateiliste, Dateirechte, PHP-Syntax auf Mindestversion, Textdomain, Lizenzhinweise und Ausschluss von Tests/Secrets vor Veröffentlichung automatisiert abnehmen.
- [ ] **P6-19 – Produktionsfehler und Supportdiagnose testen.** Fehlercodes und Korrelations-IDs müssen Support ermöglichen, ohne Submission-Inhalte oder personenbezogene Werte exportieren zu müssen.
- [ ] **P6-20 – Yoast-Kompatibilitätsmatrix abnehmen.** Yoast fehlt/deaktiviert, unterstützte Version, aktuelle Version sowie Free/Premium mit Gutenberg-Block und Shortcode prüfen; SEO-Ausgabe, Meta-Tags, Canonical, Robots, Sitemap und JSON-LD auf unbeabsichtigte Änderungen kontrollieren.
- [ ] **P6-21 – Yoast-Inhaltsanalyse und Rendering prüfen.** Bei unterstütztem Shortcode-Filter nur öffentliche Formularlabels/Hilfetexte berücksichtigen; keine Entry-Werte einbeziehen. Sichtbares serverseitiges HTML, doppelte Ausgabe und Asset-Ladung auf Seiten ohne Formular kontrollieren.
- [ ] **P6-22 – WooCommerce-Pro-Kompatibilitätsmatrix abnehmen.** HPOS aktiviert/deaktiviert, klassische und Block-basierte Bestellerstellung, My Account/Bestellbestätigung/E-Mails, Gast-/Kontokauf, Produkteditor, Paid/Pending/On-hold/Failed/Refunded sowie deaktiviertes Pro prüfen.
- **Abnahme Phase 6 / Free v1.0:** Reproduzierbarer Build, bestandene CI-/Sicherheits-/Accessibility-Prüfung, vollständige Dokumentation und getesteter Update-/Datenpfad; Kernprodukt bleibt ohne Pro dauerhaft benutzbar.

### Phase 7 – Form Captain Pro und License Captain

- [ ] **P7-01 – Premiumumfang und Entitlements final mit License Captain abstimmen.** Feature-Namen, Produktkey `form-captain-pro`, Tariflimits 1/5/25 Sites, Supportlevel und Updatekanal synchronisieren.
- [ ] **P7-02 – Add-on im captain-pro Repository anlegen.** Ordner, Slug, Textdomain und Produktschlüssel identisch setzen; Build-/Übersetzungsregeln des vorhandenen Add-on-Kernels einhalten.
- [ ] **P7-03 – Mindestversion und Feature-Hooks im Kern bereitstellen.** Kern bietet neutrale Erweiterungspunkte; keine Lizenzprüfung und kein Premiumcode im Free-Plugin.
- [ ] **P7-04 – License Client und Add-on-Kernel integrieren.** Lizenzseite unter Form Captain einhängen; Aktivierung, Site-Limit, signierte Entitlements, Updateprüfung und Diagnosen abbilden.
- [ ] **P7-05 – Feature-Registry für Pro-Funktionen registrieren.** Multi-Step, Uploads, komplexe Bedingungen/Routing, Webhooks und PDF jeweils getrennt und mit stabilem `form.*`-Recht schalten.
- [ ] **P7-06 – Lizenzzustände testen.** Ungültige/abgelaufene Lizenz, Offline-Zeit, Entitlementwechsel, Domainwechsel und Add-on-Deaktivierung dürfen Free-Formulare und Entries nicht beeinträchtigen.
- [ ] **P7-07 – Multi-Step-Formulare bauen.** Fortschritt, serverseitige Zwischenschritte, Validierung, Wiederaufnahme und Ablauf von Fortsetzen-Links absichern.
- [ ] **P7-08 – Uploads sicher umsetzen.** Allowlist, Größenlimit, MIME-Prüfung, zufällige Namen, nicht öffentliche Ablage, Download-Berechtigung und Bereinigung durchsetzen.
- [ ] **P7-09 – Upload-Datenschutz integrieren.** Aufbewahrung, Entry-Löschung, Privacy-Erasure, Backup-Hinweise und Links in Benachrichtigungen sicher behandeln.
- [ ] **P7-10 – Erweiterte Bedingungen und Routing bauen.** AND/OR-Regeln mit serverseitiger Prüfung; bedingte Bestätigungen und Benachrichtigungsziele ohne unsichere dynamische Codeausführung.
- [ ] **P7-11 – Webhook-Aktionen bauen.** Feld-Allowlist, HMAC-Signatur, Timeout, Idempotency-Key, Retry-Backoff, maximale Versuche, Dead-letter und manuelles Wiederholen implementieren.
- [ ] **P7-12 – PDF aus Entry generieren.** Nur freigegebene Felder und Templates rendern; HTML/CSS/Dateien begrenzen und PDF im Adminzugriff schützen.
- [ ] **P7-13 – Pro-E2E-Lizenztest durchführen.** Add-on-Build, Lizenzaktivierung, Featurefreigabe, Update, Ablauf/Offline und Datenintegrität gegen License Captain prüfen.
- [ ] **P7-14 – Webhook-Ziele gegen SSRF absichern.** Nur erlaubte HTTP(S)-Ziele zulassen, Loopback-/private/link-local IPs und DNS-Wechsel blockieren, Redirects kontrollieren und Timeouts/Antwortgrößen begrenzen.
- [ ] **P7-15 – Upload-Prüfung und Quarantäne ergänzen.** Dateiinhalt unabhängig von Dateiendung prüfen, gefährliche/komprimierte Formate begrenzen, optionale Malware-Scanner-Hooks definieren und ungeprüfte Dateien nicht ausliefern.
- [ ] **P7-16 – Geschützte Downloads und Löschung E2E prüfen.** Nicht angemeldete oder unberechtigte Nutzer dürfen Dateien nicht erraten/abrufen; Löschung, abgelaufene Lizenz und Entry-Erasure müssen Dateien und temporäre Kopien erfassen.
- [ ] **P7-17 – Webhook-Signaturen gegen Replay schützen.** Zeitstempel/Nonce in Signatur einbeziehen, Secret-Rotation unterstützen und wiederholte Requests innerhalb des definierten Fensters erkennen.
- [ ] **P7-18 – PDF-Generierung begrenzen.** Template-/HTML-Escaping, externe Ressourcen, Renderzeit, Speicherverbrauch und maximale Entry-Größe absichern; PDF darf keine lokalen Dateien oder URLs unkontrolliert laden.
- **Abnahme Phase 7 / Pro v1:** Pro-Funktionen liegen ausschließlich im Add-on, sind getrennt entitelt, signiert lizenziert und lassen bei Lizenzverlust Free-Daten unverändert.

### Phase 8 – Captain-Suite-Integrationen

Jeder Adapter ist optional, versioniert, einzeln deaktivierbar und greift ausschließlich über dokumentierte öffentliche APIs/Actions zu. Zuerst Kernvertrag und Diagnose implementieren, danach jeweils genau einen Adapter.

- [ ] **P8-01 – Form-Captain-Contract v1 implementieren.** Versionskonstante, Read-API, registrierbare Formularaktionen, Post-Commit-Ereignisse, Fehlerobjekte und Payload-Allowlist gemäß Abschnitt 5 bereitstellen.
- [ ] **P8-02 – Contract-Kompatibilität prüfen.** Adapter nach `form_captain_loaded_v1` registrieren; Major-Version validieren; inkompatible Adapter sichtbar mit Fehlercode deaktivieren.
- [ ] **P8-03 – Integrationsjob und Idempotenz implementieren.** Externe Fehler dürfen gespeicherte Entries nicht zurückrollen; Jobstatus, Retry-Limit, Dead-letter, manuelles Wiederholen und Diagnose ergänzen.
- [ ] **P8-04 – Integrationsseite bauen.** Erkanntes Plugin, Plugin-/Vertragsversion, Lizenzstatus, Feldmapping, letzter Lauf, Fehler und Testaktion anzeigen; nur erlaubte Daten vor dem Senden zeigen.
- [ ] **P8-05 – Pass-Captain-Einbettung bauen.** Eventformular auswählen/verlinken und freigegebenen Eventkontext über öffentliche Pass-/Form-Schnittstelle setzen; Formularentry darf keine Reservierung oder Ticket-Ausstellung auslösen.
- [ ] **P8-06 – Voucher-Anfrageadapter bauen.** Firmenanfrage/Personalisierung mit expliziter Adminprüfung und Bestätigung an Voucher Captain übergeben; keine automatische Ausgabe oder Zahlung.
- [ ] **P8-07 – Affiliate-Entscheidung umsetzen.** Zuerst prüfen, ob der vorhandene Partnerbewerbungsablauf genügt. Nur bei bestätigtem Bedarf API/Adapter implementieren; neuer Partner bleibt `pending`, nie automatische Freigabe oder Provision.
- [ ] **P8-08 – Form→Review lokale Übergabe bauen.** Nur mit Review Captain Pro; explizites Zahlen-/Text-/Anzeigenamen-Mapping, Herkunftsreferenz und Idempotency-Key verwenden; Review bleibt pending und unverified.
- [ ] **P8-09 – Review-Lösch- und Einladungsgrenze umsetzen.** Gelöschter Form-Entry entfernt den wartenden Review gemäß Vertrag; veröffentlichte Reviews folgen Review-Captain-Privacy-Regeln. Form-Entries lösen nie Einladungs-E-Mails aus.
- [ ] **P8-10 – Suite-Diagnose pro Verbindung vervollständigen.** Fehlendes Plugin, Lizenz fehlt, inkompatibler Vertrag, Timeout und wiederholbarer Fehler mit nächstem Schritt anzeigen.
- [ ] **P8-11 – Adapter-Vertragstests erstellen.** Pass, Voucher, Affiliate, Review und License Captain mit Testdoubles bzw. offiziellen Vertragsbeispielen prüfen; keine privaten Klassen/Tables mocken.
- [ ] **P8-12 – Suite-E2E durchführen.** Formulareinsendung, Feldmapping, Doppelzustellung, Plugin-Deaktivierung, API-Ausfall, Entry-Löschung und inkompatible Version durchspielen.
- [ ] **P8-13 – Signierten Suite-Kontext implementieren.** Event-/Bestell-IDs ausschließlich aus serverseitig validierten, kurzlebigen und zweckgebundenen Kontexten übernehmen; manipulierte Hidden Fields und wiederverwendete Tokens zurückweisen.
- [ ] **P8-14 – Vertrauensgrenzen der Adapter testen.** Abgelaufene/geänderte Signatur, fremde Site, fremdes Formular, fehlendes Plugin, Replay und doppelte Zustellung müssen ohne Seiteneffekt abgewiesen oder idempotent verarbeitet werden.
- [ ] **P8-15 – Contract-Kompatibilität automatisiert prüfen.** Mindestversionen, Major-/Minor-Regeln, optionale Plugins und unbekannte Payload-Felder gegen versionierte Vertragsfixtures testen.
- [ ] **P8-16 – Adapter-Nebenwirkungen bei Wiederholung abnehmen.** Timeout nach erfolgreichem Zielaufruf, erneute Queue-Zustellung und Admin-Retry dürfen weder doppelte Reviews/Partner/Gutscheine noch verlorene Ergebnisse erzeugen.
- **Abnahme Phase 8:** Integrationen funktionieren unabhängig voneinander; keine Datenbankkopplung; kein doppelter Side-Effect; Fehler und Löschfolgen sind im Admin nachvollziehbar.

### Workflow Captain Provider-Ergänzung für Phase 8

- [ ] P8-17 – Form-Workflowvertrag v1: Post-Commit-Ereignisse für Formular veröffentlicht/deaktiviert, Entry angenommen/validiert/markiert/gelöscht und Benachrichtigungsfehler; stabile IDs, Schema-Version, Allowlist und Deprecation-Policy.
- [ ] P8-18 – Sensible Felder standardmäßig ausschließen. Nur freigegebene nicht-sensible Felder und Consent-Referenzen liefern; Datei-Uploads, Passwörter, Zahlungsdaten und sensible Felder sperren oder maskieren.
- [ ] P8-19 – Sichere Actions für Adminbenachrichtigung, Entry-Tag/Status, Anonymisierung/Löschung und genehmigte Übergabe an Suite-Services anbieten; keine automatische Zahlung, Ticket-/Gutscheinausgabe, Partnerfreigabe oder Review-Veröffentlichung.
- [ ] P8-20 – Native Bestätigung und Admin-Mail bleiben Standard. Pro Nachricht explizite Workflow-Übergabe mit Fallback und Dedupe zur Vermeidung doppelter Mails.
- [ ] P8-21 – Diagnose für Workflow fehlt, Pro-Recht fehlt/abgelaufen, Contract inkompatibel, Queue-Timeout, Entry gelöscht und Consent widerrufen ergänzen; Form-Speicherung und Spamprüfung bleiben unabhängig.
- [ ] P8-22 – Export/Eraser, Retention, Consent-Widerruf, Review-Löschweitergabe, Replay/Dupe, unbekannte Felder und Multisite mit offiziellen Vertragsfixtures abnehmen.
- Abnahme: Workflow erhält nur freigegebene Formulardaten; Workflow-Ausfall beeinträchtigt Speichern, Spam-Schutz, Eintragsverwaltung und native Benachrichtigung nicht.


#### Kundenspezifische Projekt-Connectoren für Phase 8

- [ ] **P8-23 – Connector-Architektur und Abgrenzung freigeben.** Separate WordPress-Plugins für projektspezifische Logik vorsehen; Connector-Plugin besitzt Zielsystemzustand, Form Captain bleibt Eigentümer von Formularen und Entries. Generische Webhooks bleiben ein eigener Mechanismus.
- [ ] **P8-24 – Connector-Vertrag v1 spezifizieren.** Versioniertes Interface mit Key, Version, Fähigkeiten/Aktionen, Settings-Schema, Handler-Ergebnis, Retry-Semantik und Deprecation-Regel dokumentieren.
- [ ] **P8-25 – Registry und Registration-API implementieren.** Connectoren nach dem Core-Load über einen öffentlichen Filter registrieren; nur dokumentierte Interfaces/Actions verwenden. API innerhalb v1 additiv halten; inkompatible Major-Versionen sichtbar deaktivieren.
- [ ] **P8-26 – Site-lokales Opt-in ergänzen.** Installierte Connectoren standardmäßig deaktivieren; Administrator aktiviert jeden Connector ausdrücklich und kann ihn unabhängig abschalten.
- [ ] **P8-27 – Aktionen im Formular-Editor sichtbar machen.** Connector deklariert Label, Voraussetzungen, Settings und erlaubte Events; Administrator schaltet Aktionen formularweise ein und kann einen sicheren Testlauf starten.
- [ ] **P8-28 – Feld-Scopes und Mapping absichern.** Nur serverseitig validierte Allowlist-Felder und nötige IDs übergeben; sensible Werte standardmäßig sperren; keine vollständige Submission oder direkte Tabellenabfrage.
- [ ] **P8-29 – Connectoren an die Outbox binden.** Aktionen nach Entry-Commit asynchron mit Schlüssel aus Site, Entry, Connector und Aktion ausführen; doppelte Zustellung muss denselben Ausgang liefern.
- [ ] **P8-30 – Einheitliche Retry-/Dead-letter-Regeln implementieren.** Backoff, Maximalversuche (Standard acht), temporäre/definitive Fehler und manuelles Wiederholen standardisieren; native Mail und Entry-Speicherung bleiben unabhängig.
- [ ] **P8-31 – Connector-Zustände in Integrationen anzeigen.** Erkannt, deaktiviert, aktiv, inkompatibel, wartet, fehlgeschlagen, wiederholbar und Dead-letter mit letzter Ausführung und nächstem Schritt darstellen.
- [ ] **P8-32 – Connector-Lifecycle definieren.** Plugin-Deaktivierung, Entfernen, API-Inkompatibilität, Entry-Löschung und Consent-Widerruf behandeln; keine ungefragte Remote-Löschung, keine verlorenen offenen Jobs.
- [ ] **P8-33 – Berechtigungen und Secrets schützen.** Connector-Einstellungen und Testläufe mit Capability/Nonce sichern; Secrets redigieren, Rotation ermöglichen und Testmodus gegen reale Nebenwirkungen absichern.
- [ ] **P8-34 – Referenz-Connector als eigenständiges Plugin erstellen.** Beispiel für Registrierung, Form-Aktion, Feldmapping, externen Beispieldienst und idempotente Wiederholung liefern; klar als Beispiel kennzeichnen.
- [ ] **P8-35 – Connector-Entwicklerdokumentation schreiben.** Installationsvoraussetzungen, Vertrag, Beispielcode, Payloads/Scopes, Fehlercodes, Retry, Löschung, Kompatibilität und Releasecheckliste dokumentieren.
- [ ] **P8-36 – Contract-Testkit bereitstellen.** Gemeinsame Testfixtures prüfen Registrierung, Fähigkeiten, Schemafehler, Versionen, Idempotenz, Retry, Dead-letter, Deaktivierung und Datenschutzgrenzen.
- [ ] **P8-37 – Zwei-Plugin-Integrationstests in CI ausführen.** Form Captain plus Referenz-Connector mit simuliertem Zielausfall, Timeout nach Erfolg, doppelter Zustellung, Entry-Löschung und inkompatibler Version testen.
- [ ] **P8-38 – Free-/Pro-Grenze für Connectoren abnehmen.** Registry, stabile API und einfache Connector-Aktionen sind im Free-Kern nutzbar; Premiumrechte gelten ausschließlich für gesonderte Pro-Funktionen.
- [ ] **P8-39 – Pilot-Connector aus einem realen Kundenprozess ableiten.** Erst nach Freigabe eines konkreten Einsatzfalls Mapping, Zielsystemvertrag und Supportverantwortung testen; projektspezifischen Code nicht in Form Captain Core übernehmen.
- **Abnahme Connectoren:** Ein separat installierbares Connector-Plugin kann sich sicher registrieren, wird nur nach Opt-in ausgeführt, erhält genau freigegebene Daten und bleibt bei Wiederholung/Ausfall diagnostizierbar.


#### Pilot-Connectoren: BoatingSchool B2B und LearnBoating B2C

Diese Aufgaben erweitern Phase 8 um zwei echte Kundenfälle. Sie ändern nicht die Reihenfolge von Free v1.0 und Pro v1; Umsetzung startet erst nach dem allgemeinen Connector-Vertrag und einer konkreten Kundenfreigabe. Änderungen in den Kunden-Repositories werden dort separat umgesetzt und versioniert.

**BoatingSchool – Form-Captain-Lead-Connector**

- [ ] **P8-40 – B2B-Prozess mit Kunde und Plattformverantwortlichen abnehmen.** Formularzweck (Demo, Beratung oder Schulzugangsinformation), Zielwebsite, verantwortliche Inbox, erlaubte Felder, Einwilligung, Aufbewahrung und Follow-up festlegen. Ohne eine WordPress-Website mit Form Captain ist dieser Connector kein passender Einbauort.
- [ ] **P8-41 – Bestehende Commerce-Grenze dokumentieren.** Vor Implementierung bestätigen, dass `/api/commerce/v1` Shopkonten, Käufe und Kurscodes verwaltet, aber keinen allgemeinen Interessenten-/Lead-Eingang. Keine bestehenden Endpunkte für Form-Leads umnutzen.
- [ ] **P8-42 – Neuen Laravel-Lead-Vertrag spezifizieren.** Für BoatingSchool einen eigenständigen Intake-Endpunkt (Vorschlag: `POST /api/integrations/v1/form-captain/leads`) mit versioniertem JSON-Schema, erlaubten Feldern, Antwort- und Fehlercodes, Idempotenz, Rate-Limits und Schlüsselrotation definieren.
- [ ] **P8-43 – Tenant-unabhängiges Lead-Datenmodell und Inbox planen.** Lead ist ein unqualifizierter Interessent, keinem Tenant zugeordnet und weder Benutzer-, Shopkonto- noch Schuldatensatz. Superadmin-Rollen, Status (neu, in Bearbeitung, qualifiziert, abgelehnt), interne Notizen, Aufbewahrung, Export/Löschung und Auditierung festlegen.
- [ ] **P8-44 – Lead-Endpunkt sicher in BoatingSchool implementieren.** Separaten HMAC-Key und Signaturvertrag verwenden; Timestamp/Nonce, Replay-Schutz, Rate-Limits, Eingabevalidierung und Schemafehler abdecken. Commerce-Secrets und Commerce-Berechtigungen nicht wiederverwenden.
- [ ] **P8-45 – Lead-Inbox im BoatingSchool-Superadmin umsetzen.** Eingehende Leads nachvollziehbar anzeigen, bearbeiten, ablehnen und löschen; keine Konto-/Schulanlage durch den Intake-Aufruf. Etwaige spätere Freigabe führt ausschließlich in den vorhandenen geprüften Onboarding-Prozess.
- [ ] **P8-46 – Eigenständiges WordPress-Connector-Plugin erstellen.** Form Captain und kompatible Vertragsversion erkennen; Endpoint und Secret ausschließlich serverseitig konfigurieren; Connector standardmäßig deaktivieren. Ohne erreichbaren, kompatiblen Endpunkt keine scheinbar erfolgreiche Übergabe melden.
- [ ] **P8-47 – Lead-Feldmapping und Herkunftsbezug implementieren.** Nur explizite, nicht sensible Felder, Consent-Referenz, Formular-/Entry-ID und erlaubte Quell-URL übertragen. Idempotency-Key aus Website, Formular und Entry verwenden; vollständigen Entry nicht pauschal spiegeln.
- [ ] **P8-48 – Zustellstatus und Wiederholung über die Form-Captain-Outbox abnehmen.** Remote-Lead-ID, letzter Versuch, wiederholbarer/endgültiger Fehler und manuelle Wiederholung darstellen. Erfolgreiche Wiederholung darf keinen zweiten Lead erzeugen; Connector-Ausfall darf Entry, Spamprüfung und native Benachrichtigung nicht verhindern.
- [ ] **P8-49 – BoatingSchool-Vertrag und Connector gemeinsam testen.** HMAC-Kompatibilität, Replay, falsches Schema, doppelte Zustellung, Timeout nach erfolgreichem Schreiben, Schlüsselrotation, Rate-Limit, Superadmin-Sichtbarkeit und Ablehnung automatischer Kontenerstellung abdecken.
- [ ] **P8-50 – Kunden-Staging und Datenschutzabnahme dokumentieren.** WordPress → Laravel Ende-zu-Ende testen; Testleads löschen, Aufbewahrung und Einwilligung bestätigen, Wiederherstellung/Ausfallweg festhalten. Produktivschaltung erst nach Freigabe der tatsächlichen WordPress-URL, API-URL und Verantwortlichen.

**LearnBoating – lokale Kursfrage-Integration**

- [ ] **P8-51 – Kursfragen-Anwendungsfall bestätigen.** Auf Kurs-/Produktseiten eine unverbindliche Kursfrage als Ziel definieren. Amtliche Prüfung, Terminvergabe, Praxis-Tickets, Lernzugang, Gutschein-, Affiliate- und Kaufprozesse bleiben ausgeschlossen.
- [ ] **P8-52 – Öffentlichen LearnBoating-Kurskontext-Vertrag spezifizieren.** Im Lernplugin einen dokumentierten, versionierten Erweiterungspunkt (Vorschlag: `bootspruefung_form_context_v1`) für öffentliche Kursdaten `course_code`, `label`, `permalink`, `status` festlegen. Keine private Klasse, Datenbankabfrage oder unverifizierte Hidden-Field-Quelle als API behandeln.
- [ ] **P8-53 – LearnBoating-Erweiterungspunkt implementieren und dokumentieren.** Nur veröffentlichte, anfragbare Kurse ausgeben; Vertragsversion und Fehlerfall dokumentieren. Das Lernplugin muss ohne Form Captain unverändert funktionieren.
- [ ] **P8-54 – Eigenständiges LearnBoating-Connector-Plugin erstellen.** Auf derselben WordPress-Installation wie `bootspruefung-learning` installieren; den Kurskontext lokal beziehen und als Form-Captain-Aktion anbieten. Keine HTTP-Rundreise zur eigenen Website.
- [ ] **P8-55 – Kontext gegen Manipulation absichern.** Kurskontext serverseitig aus aktueller Kursseite übernehmen oder eine Auswahl gegen den öffentlichen Vertrag validieren. Unbekannte, unveröffentlichte oder deaktivierte Kurscodes ablehnen; Browserwerte allein nie als verlässlich behandeln.
- [ ] **P8-56 – Entry- und Benachrichtigungsfluss umsetzen.** Form Captain bleibt Eigentümer des vollständigen Entries und versendet die konfigurierte Standardbenachrichtigung. Kursreferenz wird am Entry sichtbar. Es entsteht kein Ticketdatensatz oder Supportfall im Lernplugin, solange es keinen öffentlichen Support-Vertrag gibt.
- [ ] **P8-57 – Datenminimierung und bestehende Connectoren abnehmen.** Keine Abfrage/Übertragung von Lernfortschritt, Prüfungsversuchen, Bestellungen, Gutscheinwerten, Affiliate-Attribution oder Nutzerprofilen. Vorbefüllung von WordPress-Kontaktdaten nur nach aktiver Nutzerentscheidung. Bestehende Voucher-/Affiliate-Connectoren bleiben zuständig.
- [ ] **P8-58 – LearnBoating-Kompatibilität und Zwei-Plugin-Verhalten testen.** Die kombinierte Basis aus aktuellem Form-Captain- und Lernplugin-Mindeststand verifizieren (Konzeptstand: WordPress 7.0+, PHP 8.3+; WooCommerce nur nach Anforderung des Lernplugins). Aktivierung ohne Form Captain, fehlender/veralteter Vertrag, unbekannter Kurs, Plugin-Deaktivierung, Entry-Löschung und normales Formular-Fallback testen.
- [ ] **P8-59 – Gemeinsame Pilot-Dokumentation und Supportgrenze veröffentlichen.** Installation, unterstützte Versionen, Datenfluss, Fähigkeiten, Grenzen, Consent, Fehlerbehebung und klare Trennung zwischen Form-Captain-Entry und LearnBoating-Kurszugang dokumentieren.
- **Abnahme Kunden-Connectoren:** BoatingSchool nimmt ausschließlich minimierte Interessenten-Leads in einer neuen, getrennten Inbox auf; LearnBoating liefert nur geprüften Kurskontext. Beide Connectoren sind optional, separat installierbar, idempotent, deaktivierbar und verwenden ausschließlich öffentliche, dokumentierte Verträge.

#### WooCommerce Pro: Käuferfragebögen je Produkt nach dem Kauf

- [ ] **P8-60 – Free-/Pro-Grenze und Releaseumfang festlegen.** WooCommerce-Konfliktfreiheit und optionale Shop-Erkennung bleiben Free; Produktzuordnung, Käuferzugang und Bestellverknüpfung sind Pro v1 mit Entitlement form.woocommerce_questionnaires. Keine Zahlungen oder Checkout-Felder durch Form Captain.
- [ ] **P8-61 – Bestell- und Statusvertrag spezifizieren.** Nur tatsächlich angelegte und von WooCommerce als bezahlt bestätigte Aufträge verarbeiten; unbezahlte, Draft-, fehlgeschlagene, stornierte und erstattete Bestellungen ausschließen. Wiederholte Statusereignisse idempotent behandeln.
- [ ] **P8-62 – Produktzuordnung im WooCommerce-Produkteditor bauen.** Pro Produkt bestehendes Formular wählen, Feature aktivieren und optional/„vor Bearbeitung erforderlich“ festlegen; deaktiviert als Standard. Variable Produkte und Variationsvererbung samt Konfliktregeln abdecken.
- [ ] **P8-63 – Unterstützte Formularfelder und Kontext-Allowlist definieren.** Formwerte, Feldtypen, Pflichtfelder, Einwilligung und zulässige Produkt-/Bestellreferenzen festlegen. Order ID, Line-Item-ID, Produkt und Kundenzugriff werden serverseitig abgeleitet, nie aus ungeprüften Hidden Fields akzeptiert.
- [ ] **P8-64 – Sichere Käuferberechtigung implementieren.** Eigentümerschaft für eingeloggte Kunden prüfen; für Gastbestellungen begrenzten, widerrufbaren, ablaufenden Zugriff pro Bestellposition spezifizieren. Keine erratbaren URLs, Klartext-Order-Keys in neuen Linkparametern oder Antworten in URL/Mail.
- [ ] **P8-65 – Zugangsflächen nach Kauf bauen.** Fragebogen auf Order-Received/Bestellbestätigung, WooCommerce-Account-Bestellansicht und in der passenden Kunden-E-Mail anbieten. Block-Checkout und klassischen Checkout über unterstützte Erweiterungspunkte berücksichtigen; E-Mail und Account-Ansicht sind der robuste Fallback.
- [ ] **P8-66 – Formularzugang erst nach bestätigter Zahlung freigeben.** Ereignisse für Processing/Completed beziehungsweise WooCommerce-Paid-Prüfung nutzen; Checkout-Drafts und Pending/On-hold ohne bestätigte Zahlung nicht einbeziehen. WooCommerce-Status nicht selbst überschreiben.
- [ ] **P8-67 – Bestellpositionsbezogenen Entry-Vertrag umsetzen.** Eine vollständige Form-Captain-Einsendung je Bestellposition als maßgeblichen Antwortdatensatz speichern; bei Menge > 1 in v1 keine Mehrfachbögen je Stück erzeugen. Form-/Entry-ID mit Auftrag und Line Item idempotent verknüpfen.
- [ ] **P8-68 – WooCommerce-CRUD für Order und Line Item verwenden.** Referenz und Fragebogenstatus per WooCommerce Order-/Item-CRUD speichern, nicht durch direkte WordPress-Tabellen- oder Order-Meta-Zugriffe. HPOS-Kompatibilität erklären und deklarieren.
- [ ] **P8-69 – Verkäuferstatus und Korrekturfluss bereitstellen.** Status je Zeile (nicht angefordert, ausstehend, abgeschlossen, erneut angefordert) im Auftrag anzeigen und auf den Form-Captain-Entry verlinken. Korrektur-Anforderung öffnet kontrolliert eine neue Entry-Version; kein stilles Überschreiben historischer Antworten.
- [ ] **P8-70 – Pflicht-vor-Bearbeitung nur als Hinweis umsetzen.** Offene erforderliche Antworten für Shopmitarbeiter prominent anzeigen und optional erinnern; weder Orderstatus, Zahlung, digitale Downloads noch Versand automatisch blockieren. Harte Fulfillment-/Download-Sperre bleibt separat.
- [ ] **P8-71 – E-Mail und Erinnerung idempotent integrieren.** WooCommerce-Mailer und Form-Captain-Queue verwenden; doppelte Statushooks senden nicht mehrfach. Links sind begrenzt/ablaufend und enthalten keine Antwortwerte oder unnötigen personenbezogenen Daten.
- [ ] **P8-72 – Storno, Refund und Privacy-Verhalten definieren.** Keine neuen Zugänge/Erinnerungen nach Storno/Refund; bereits gespeicherte Entries folgen Form-Captain-Retention/Exporter/Eraser. WooCommerce enthält keine zweite vollständige Antwortkopie.
- [ ] **P8-73 – Produkteditor- und Order-Admin-Kompatibilität prüfen.** Classic Product Editor und unterstützten neuen Product Editor bewerten; wenn ein Editor keine stabile Erweiterungs-API bietet, dokumentierten WooCommerce-Fallback bereitstellen. Berechtigungen für Shop Manager/Admin prüfen.
- [ ] **P8-74 – Checkout-/Bestellfluss E2E abnehmen.** Klassischer und Block-Checkout, HPOS an/aus, Gast-/Kontokauf, variabel/einfaches Produkt, Zahlung fehlgeschlagen/ausstehend/bezahlt, Mehrfachhook, mehrere Produkte und Refund/Storno durchspielen.
- [ ] **P8-75 – Pro-Lizenz und Deaktivierung abnehmen.** Bei abgelaufenem Pro keine neue Käuferfragebogen-Konfiguration; bestehende WooCommerce-Aufträge und gespeicherte Entries bleiben lesbar und intakt. Keine Beeinträchtigung des Checkouts.
- [ ] **P8-76 – WooCommerce-Modul dokumentieren und deklarieren.** Mindest-/aktuelle WooCommerce-Version, HPOS, Checkout-Blocks, Classic Checkout, Produkteditor, Gastzugriff, Statusablauf, Datenaufbewahrung, Grenzen und Supportablauf veröffentlichen; Kompatibilität erst nach Test deklarieren.
- **Abnahme WooCommerce Pro:** Verkäufer kann pro Produkt optional einen sicheren Fragebogen zuordnen; nach bezahlter Bestellung füllt der richtige Käufer ihn aus; Antworten liegen als Form-Captain-Entry vor und WooCommerce zeigt Status/Referenz. Checkout, Zahlung, Orderstatus und HPOS bleiben unbeeinträchtigt.

### Phase 9 – Launch, Betrieb und spätere Erweiterungen

- [ ] **P9-01 – Produkt- und Preisangaben finalisieren.** Form-Captain-Pro-Tarife, 1/5/25-Site-Limits, Supportumfang, Verlängerung und Shopprodukte mit License Captain und captain-pro abgleichen.
- [ ] **P9-02 – Support- und Fehlerprozess festlegen.** Supportkanal, Systemstatus-Export, Datenschutz beim Supportdump, Fehler-Triage und Sicherheitskontakt dokumentieren.
- [ ] **P9-03 – Release-Checkliste durchführen.** Versionsnummer, Changelog, ZIP-Prüfsumme, Upgrade-Test, Lizenz-/Entitlementtest, Datenbackup und Rollbackplan vor jedem Release bestätigen.
- [ ] **P9-04 – Nutzungsfeedback nach Free-Launch auswerten.** Abbruchraten im Setup, häufige Feldtypen, Mailprobleme und Supportanfragen datensparsam erfassen; keine Submission-Inhalte als Telemetrie senden.
- [ ] **P9-05 – Contact Form 7 Import nach v1 spezifizieren.** Feld-/Regelzuordnung, nicht unterstützte Funktionen, Vorschau und Konvertierungsbericht festlegen.
- [ ] **P9-06 – Importer implementieren und testen.** Bestehende Formulare sicher migrieren; Entries nur über dokumentierte Exportdateien übernehmen; Originaldaten vor Import nicht löschen.
- [ ] **P9-07 – Mehrsprachigkeit erweitern.** WPML/Polylang-Unterstützung erst mit definierter Formular-/Übersetzungszuordnung und Testmatrix ergänzen.
- [ ] **P9-08 – Multisite separat planen.** Pro-Site-Datentrennung, Netzwerkaktivierung, Massenmigration und Lizenzzählung vor Implementierung spezifizieren.
- [ ] **P9-09 – Zahlungen, CRM, Analytics und White-Label nicht vorziehen.** Für jeden Bereich erst API-/Provider-, Refund-, Rechts-/Datenschutz-, Support- und Betriebskosten spezifizieren; danach eigene Releaseentscheidung treffen.
- [ ] **P9-10 – Datenschutz-/Rechtsfreigabe vor Verkauf einholen.** Datenflüsse, Einwilligungstexte, externe Dienste, Retention, Löschung, Auftragsverarbeitung und Upload-/Mailverhalten für die Zielmärkte fachlich prüfen und dokumentierte Freigabe ablegen.
- [ ] **P9-11 – E-Mail-Zustellung auf typischen Hostern abnehmen.** Absender-/Reply-To-Regeln, SMTP-Konfiguration, SPF/DKIM-Hinweise, Fehlerstatus und Wiederholungsweg in einer repräsentativen Hostingmatrix verifizieren.
- [ ] **P9-12 – Release- und Rollbackübung durchführen.** Testrelease auf Staging installieren, Update/Fehler simulieren, Datenbank und Dateien wiederherstellen und Rückkehr zur letzten funktionierenden Version dokumentieren.
- [ ] **P9-13 – Staging-Rollout und Datenbank-Rollback üben.** Release auf Staging mit anonymisierten Beispieldaten testen; für irreversible Migrationen Wiederherstellungsweg festlegen, bevor das Update startet.
- [ ] **P9-14 – Betriebsüberwachung ohne Submission-Inhalte einrichten.** Queue-Rückstand, Mailfehler, Integrationsfehler, Cron-Ausfälle und Schemafehler anhand aggregierter Statusdaten sichtbar machen.
- **Abnahme Phase 9:** Releases sind reproduzierbar und betreibbar; spätere Erweiterungen haben eine begründete Nachfrage und klare Daten-/Supportverantwortung.

### Gesamt-Abnahmekriterien für „Form Captain fertig“

- [ ] Free v1.0 kann installiert, eingerichtet und aktualisiert werden; Formulare lassen sich bauen, veröffentlichen, absenden, speichern, verwalten und exportieren.
- [ ] Datenschutz-, Berechtigungs-, Spam-, E-Mail-, CSV-, Migrations- und Löschpfade sind abgenommen.
- [ ] Free-Release ist WordPress.org-fähig und besitzt vollständige Dokumentation sowie reproduzierbaren Build.
- [ ] Pro v1 ist ein separates, korrekt lizenziertes Add-on; Pro-Ablauf oder Deaktivierung löscht keine Free-Formulare oder Entries.
- [ ] Suite-Adapter verwenden ausschließlich freigegebene Verträge, sind idempotent und erfüllen die Datenbesitz-/Einladungsgrenzen dieses Konzepts.
- [ ] Offene spätere Erweiterungen aus Phase 9 sind entweder mit begründetem Releaseplan versehen oder bewusst aus dem Produktumfang ausgeschlossen.
