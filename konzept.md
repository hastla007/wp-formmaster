# Form Captain – Produktkonzept

**Status:** Produkt- und Umsetzungsgrundlage  
**Stand:** 15. November 2026  
**Produktname:** Form Captain  
**Repository:** hastla007/wp-formmaster  
**WordPress-Kernplugin:** form-captain  
**Premium-Add-on:** form-captain-pro  
**Lizenzserver-Produktschlüssel:** form-captain-pro (im WP License Captain-Katalog anzulegen)  
**Rechte-Präfix:** form.  
**Zielgruppe:** WordPress-Websites, WooCommerce-Shops, Agenturen und Nutzer der Captain Suite

> Dieses Konzept beschreibt Produktumfang und Umsetzung. Es ist keine Zusage bereits bestehender Anbieterfreigaben oder Integrationen. Externe Dienste werden nur über dokumentierte APIs angebunden; Datenschutz, Nutzungsbedingungen, Gebühren und API-Limits sind vor jeder Integration zu prüfen.

## 1. Produktidee und Positionierung

Form Captain ist ein WordPress-natives Formular- und Submission-Management-Plugin für Kontaktformulare, Anfragen, Newsletter-Anmeldungen, Registrierungen und strukturierte Geschäftsabläufe. Der Fokus liegt auf einer schnellen Bedienung, verlässlicher Speicherung und einer offenen, stabilen Schnittstelle zu WooCommerce und der Captain Suite.

**Produktversprechen:** Formulare visuell erstellen, Einsendungen sicher im eigenen WordPress verwalten und wiederkehrende Abläufe ohne individuelle Programmierung mit den Captain-Plugins verbinden.

Das Produkt besteht aus zwei getrennten Plugins:

- **Form Captain (Free):** eigenständig sinnvoll und vollständig kostenlos; veröffentlichbar auf WordPress.org. Formular-Editor, Kernfelder, Validierung, Einwilligungsfeld, E-Mail-Benachrichtigungen, Einträge, Export, Basis-Spamschutz, Gutenberg-Block und Shortcode gehören in den Kern.
- **Form Captain Pro:** separates Add-on außerhalb von WordPress.org. Es liefert professionelle Workflow-Funktionen wie mehrstufige Formulare, erweiterte Regeln, Dateien, PDFs, Zahlungs- und CRM-Anbindungen sowie Captain-Suite-Aktionen. Lizenz, Updater und Premium-Rechte liegen ausschließlich im Add-on.

Das kostenlose Plugin enthält keine Lizenzprüfung, keinen Lizenzclient, keinen eigenen Updater und keine nach Zahlung gesperrten Funktionen. Bereits im Free-Kern veröffentlichte Funktionen werden nicht nachträglich entzogen.

## 2. Zielgruppen und typische Einsätze

1. **Kleine Unternehmen:** Kontakt-, Angebots-, Support- und Rückrufformulare; Einsendungen zuverlässig im WordPress-Admin nachverfolgen.
2. **WooCommerce-Händler:** Produktanfragen, Vorbestellungen, Reklamationen, individuelle Bestellwünsche und strukturierte Leads.
3. **Veranstalter:** Anmeldungen, Helfer- und Ausstellerbewerbungen sowie Ticket-/Eventanfragen.
4. **Agenturen:** Vorlagen, wiederverwendbare Formulare, Rollen und Import aus bestehenden Plugins.
5. **Captain-Suite-Nutzer:** Formulare als gemeinsamer Einstiegspunkt für Gutschein-, Ticket-, Affiliate-, Review- und Lizenzprozesse.

## 3. Wettbewerbsanalyse

Die Wettbewerber setzen unterschiedliche Schwerpunkte. Die Marktbeobachtung unten fasst die öffentlich dokumentierten Produktmerkmale zusammen (Stand 15.11.2026); Tarifgrenzen und API-Verfügbarkeit können sich ändern.

| Produkt | Stärken / öffentlich sichtbare Merkmale | Produktchance für Form Captain |
|---|---|---|
| **WPForms** | Einsteigerorientierter Drag-and-drop-Builder, Vorlagen, bedingte Logik, Benachrichtigungsrouting, Spam-Abwehr, Marketing- und Automationsintegrationen; aktuelle Feature-Seite bewirbt auch KI-gestützte Erstellung/Bearbeitung. | Fokus auf nachvollziehbare, WordPress-native Einträge und Captain-Suite-Aktionen statt primär auf möglichst viele Integrationslogos. |
| **Gravity Forms** | Robuster lizenzierter Formular-Kern, bedingte Logik, mehrseitige Formulare, Uploads, Zahlungs- und Add-on-Ökosystem, umfangreiche Entwickler-Hooks. Kein dauerhaftes WordPress.org-Free-Plugin. | Free-Einstieg und Erweiterung über öffentlichen Vertrag; Suite-Verbindungen als gepflegte, getestete Adapter. |
| **Fluent Forms** | Großzügiger Free-Umfang laut Anbieter: Drag-and-drop, bedingte Logik, Eintragsverwaltung, Vorlagen, Spam-Schutz, Export, teils Zahlungen und Integrationen; Pro erweitert unter anderem um Multi-Step, Uploads, PDFs, CRM und erweiterte Workflows. | Ein Free-Kern muss bereits den alltäglichen Kontaktformular-Use-Case sauber lösen. Differenzierung über klare Datenhaltung, Suite-Integration und einheitlichen Add-on-Vertrag. |
| **Formidable Forms** | Positioniert sich für komplexe Formulare, Berechnungen, Umfragen, Quiz, dynamische Views und aus Einträgen erzeugte Inhalte. | Keine frühe Überdehnung zum App-Builder. Erst zuverlässige Formulare/Einträge/Integrationen; Berechnungen und dynamische Ausgabe danach. |
| **Ninja Forms** | Kostenloser Kern mit einzeln kaufbaren Add-ons bzw. Paketen; Erweiterungen für Logik, mehrseitige Formulare, Zahlungen, CRM und Automationen. | Einheitliche Lizenzierung über WP License Captain und ein zusammenhängendes Pro-Add-on statt vieler Einzellizenzen. |
| **Contact Form 7** | Etabliertes, kostenloses und schlankes Kontaktformular mit modularer Architektur und offenem Quellcode; benötigt für viele Abläufe weitere Erweiterungen oder manuelle Konfiguration. | Modernere visuelle Erstellung und integrierte Eintragsverwaltung anbieten, ohne die Leichtgewichtigkeit und Offenheit des WordPress-Ökosystems aufzugeben. |

### Marktanforderungen und Schlussfolgerungen

- Ein intuitiver Builder, E-Mail-Benachrichtigungen, Spam-Schutz, responsive Ausgabe und einfache Einbettung gelten als Basis.
- Entry-Management, Export, Import und Migration sind kaufentscheidend, weil Formulare produktive Geschäftsdaten aufnehmen.
- Bedingte Logik, mehrseitige Formulare, Datei-Uploads, Zahlungen und Integrationen sind häufige Upgrade-Treiber.
- Ein breites Integrationsverzeichnis ist kein Ersatz für stabile, idempotente Verträge und nachvollziehbare Fehlerberichte.
- Form Captain sollte mit einem kleineren, tief integrierten und gut dokumentierten Funktionssatz starten. Keine übertriebenen „X Integrationen“-Versprechen, bevor Adapter getestet und betreibbar sind.

### Quellen (Anbieter- und WordPress-Seiten)

- [WPForms Features](https://wpforms.com/features/) und [WPForms Pricing](https://wpforms.com/pricing/)
- [Gravity Forms Features](https://www.gravityforms.com/features/) und [Gravity Forms Dokumentation: Features](https://docs.gravityforms.com/features/)
- [Fluent Forms Free vs Pro](https://fluentforms.com/free-vs-pro/) und [Fluent Forms auf WordPress.org](https://wordpress.org/plugins/fluentform/)
- [Formidable Forms Features](https://formidableforms.com/features/) und [Formidable Forms auf WordPress.org](https://wordpress.org/plugins/formidable/)
- [Ninja Forms](https://ninjaforms.com/) und [Conditional Logic Add-on](https://ninjaforms.com/extensions/conditional-logic/)
- [Contact Form 7 auf WordPress.org](https://wordpress.org/plugins/contact-form-7/)

## 4. Funktionsumfang

### 4.1 Form Captain Free – Kernumfang

**Erstellen und Veröffentlichen**

- Formularübersicht mit Entwurf, veröffentlicht, pausiert, Einsendungen und Datum der letzten Änderung.
- Visueller Formular-Editor mit Feldliste, Feldreihenfolge, Einstellungen, Vorschau und Speichern.
- Feldtypen zum Start: einzeiliger Text, Absatz, E-Mail, Telefon, Zahl, Auswahl, Checkbox, Optionsfeld, Datum, Überschrift/HTML-Hinweis, versteckter Wert und Einwilligung.
- Pro Feld: Bezeichnung, interner Schlüssel, Pflichtstatus, Standardwert, Hilfetext, Platzhalter, Zeichen-/Wertegrenzen und CSS-Klasse.
- Kernvalidierung serverseitig; Browser-Validierung ist zusätzliche Bedienhilfe.
- Gutenberg-Block mit Formularauswahl und Vorschau; Shortcode als kompatibler Fallback. Responsives Markup, Theme-erbliche Farben und keine zwingende externe Skript-/Font-Ladung.
- Versandbestätigung: Erfolgsmeldung oder Redirect auf eine ausgewählte Seite.

**Benachrichtigungen und Einträge**

- Admin-Benachrichtigung mit Empfänger, Antwortadresse, Betreff und Feldzuordnung.
- Optional eine Bestätigung an die einsendende Person; Absenderdomäne und Fehlerhinweis im Admin prüfen.
- Einträge persistent in eigenen Tabellen speichern, mit Suche, Filtern, Detailansicht, Status (neu, gelesen, archiviert, Spam), Notiz und Papierkorb.
- CSV-Export mit Spaltenauswahl, Datumsfilter und korrekter Behandlung von Formelzeichen; Export nur mit Capability und Nonce.
- Einträge standardmäßig auf der Website halten; kein Versand an Drittanbieter ohne konfigurierte Integration und transparente Einwilligung.

**Missbrauchsschutz, Rechte und Datenschutz**

- Honeypot, Mindest-Ausfüllzeit, Rate-Limit je IP-Hash/Formular und einfacher Wort-/E-Mail-Blocklist-Filter. Keine rohe IP länger speichern als für den Zweck nötig.
- Captcha-Provider sind optional und explizit zu aktivieren; deren Datentransfer und Datenschutzhinweis werden dokumentiert.
- WordPress-Privacy-Exporter/Eraser, Retentionsregel, Datenexport und Löschbarkeit je Formular; optionale Aufbewahrungsfrist.
- Rollen und Capabilities: Formulare verwalten, Einträge lesen, Einträge exportieren, Einstellungen ändern. Standard: Administrator kann alles; Editor erhält keine sensiblen Einträge ohne Zuweisung.
- Nonces, Capability-Prüfung, Escaping, Sanitizing, sichere Upload-Verzeichnisse (sobald Upload verfügbar), Schutz vor CSRF/XSS/SQL-Injection und Rate-Limit auf Submission-Routen.
- Übersetzungsfertig, Deutsch als Startsprache, Textdomain form-captain.

**Basisbetrieb**

- Setup-Checkliste, Beispiel-Kontaktformular, Systemstatus und verständliche Fehlerhinweise.
- Formulare als JSON importieren/exportieren; keine Entries im Formular-Export.
- Entwickler-Hooks und versionierte öffentliche API von Anfang an vorsehen.

### 4.2 Form Captain Pro – Premium-Add-on

| Bereich | Pro-Funktionen |
|---|---|
| **Form Builder** | Mehrspaltige Layouts, wiederverwendbare Feldgruppen, erweiterte Feldtypen (Datei, Signatur, Bewertung, Adresse), bedingte Felder und Abschnitte, Multi-Step-Formulare mit Fortschritt, mehr Regeln für Bestätigung und Benachrichtigungsrouting. |
| **Workflows** | Bedingte Aktionen und Benachrichtigungen, Einträge zuweisen, interne Aufgaben/Status, geplante Formulare und Submission Limits, Webhooks mit Feldmapping, Wiederholungswarteschlange und Ausführungsprotokoll. |
| **Dateien und Dokumente** | Sichere Datei-Uploads mit Typ-/Größenlimit, private Speicherung außerhalb des öffentlichen Verzeichnisses soweit möglich, Ablauf/Löschung, PDF aus Entry mit Vorlage und Anhängen; niemals unsicher ausführbare Dateitypen zulassen. |
| **Daten und Ausgabe** | Berechnungen, Umfragen/Quiz mit Basisreport, PDF/CSV-Export, Entry-Ansicht im Frontend nur für freigegebene Scopes, Entry-to-Post mit expliziter Feldzuordnung und moderiertem Status. |
| **Zahlungen** | Gebühren/Spenden und Bestell-/Registrierungsbeträge über offizielle Zahlungsprovider-APIs; Provider-Webhook-Verifikation, Idempotenzschlüssel, Refund-/Fehlerstatus. Keine Kartendaten im WordPress speichern. MVP erst nach eigenständiger Zahlungs- und Compliance-Prüfung. |
| **Integrationen** | CRM-/Newsletter-Connectoren, Zapier/Make/n8n via Webhook oder dokumentiertem Adapter; Integrationsstatus, Testverbindung, Feldzuordnung und Fehlerjournal. |
| **Betrieb** | Entry-Abandonment/Partial Entries, Versionshistorie und Wiederherstellung, erweiterter Export/Import und unterstützte Migrationen; Agentur-/White-Label-Option erst nach Nachfragevalidierung. |

**Pro-Schnitt:** Der Free-Kern bleibt für einfache Formulare dauerhaft brauchbar. Pro monetarisiert Automatisierung, komplexe Workflows und aufwendige Betriebsfunktionen. Kernformulare, die im Free erstellt wurden, bleiben auch ohne Pro unverändert nutzbar; Pro-Daten bleiben gespeichert und werden nach Lizenzende nicht gelöscht. Pro-Aktionen pausieren bei fehlendem Recht kontrolliert und zeigen einen nachvollziehbaren Status.

## 5. Captain-Suite-Integration

### Leitprinzip

Form Captain bleibt ein eigenständiges Formularprodukt. Jedes Suite-Plugin integriert sich über versionierte öffentliche Funktionen, Aktionen oder Adapter. Kein Plugin liest oder verändert direkt die Tabellen eines anderen Plugins. Connectoren prüfen Plugin-Version und Vertragsversion und zeigen inkompatible Zustände sichtbar an.

### Gemeinsame technische Verträge

- Öffentliche Funktion/API mit Major-Version, Capability-Checks und dokumentierten Fehlercodes.
- Aktionen nach erfolgreichem Commit (z. B. Formular abgeschickt, Entry erstellt, Entry geändert, Entry gelöscht) mit minimalem Payload.
- Filter/Aktionsregistrierung für Felder, Formularaktionen, Admin-Menü, Eintragsdetails, Bestätigungslinks und Export.
- Idempotency-Key für externe/geschäftskritische Aktionen; Wiederholungen erzeugen keine doppelten Gutscheine, Tickets, Provisionen oder API-Ereignisse.
- Keine vollständigen Bestell-, Zahlungs- oder unnötigen Personendaten in Suite-Events; nur IDs und explizit freigegebene Feldwerte.
- Integrationsdiagnose: erkanntes Plugin, Version, Vertragskompatibilität, letzte Ausführung, Fehler und erneuter Versuch.

### Konkrete Integrationen

| Plugin | Möglicher Nutzen | Form Captain Vertrag / Schutz |
|---|---|---|
| **WP License Captain** | Pro-Aktivierung, Rechteprüfung, signierte Offline-Rechte und Pro-Updates. | Nur form-captain-pro verwendet den vorhandenen PHP License Client und Produktschlüssel form-captain-pro. Kern bleibt lizenzfrei; Lizenzseite wird unter Form Captain eingehängt. Feature-Rechte z. B. form.uploads, form.workflows, form.payments. |
| **Pass Captain** | Anmelde-/Bewerbungsformulare für Events, Anmeldedaten als Ausgang für Registrierung oder Ticketverkauf, Formlink auf Eventseiten. | Pass Captain ruft eine öffentliche Form-Captain-Funktion zum Einbetten/Ermitteln eines Formulars auf oder erhält eine freigegebene Submission-Referenz. Ticket-Erstellung nur als explizite Aktion in Pass Captain Pro/Kernvertrag; keine stillschweigende Ausstellung aufgrund einer Formularantwort. |
| **Voucher Captain** | Gutschein-Anfragen, Firmenbestellungen und Geschenkpersonalisierung. | Öffentliche Voucher-Ausstellungs-/Firmenbestell-API verwenden. Beträge, Empfänger und Einwilligung klar zuordnen; kein direkter Gutschein-Tabellenzugriff. Gutschein wird nur nach expliziter Bestätigung und optionaler Zahlung ausgestellt. |
| **Affiliate Captain** | Partnerbewerbungen, Kontaktdaten und manuell freigegebene Anmeldungen; Referral-/Affiliate-Code als Formularfeld. | Affiliate-Vertrag zur Partneranlage/Statusänderung nutzen, falls vorhanden; keine automatische Freigabe ohne Administratorentscheidung. Referral-Code als attributiertes Feld übergeben, keine Trackingdaten ohne Zustimmung. |
| **Review Captain** | Bewertungs-/Feedbackanfragen oder internes Feedbackformular nach Ticket-/Gutschein-/Bestellereignis. | Review-Anfragen nur für echte Interaktionen und mit Frequenzschutz/Opt-out. Öffentliche Review-Captain-API für Quelle/Request nutzen; keine gekaufte oder incentivierte positive Bewertung. |
| **WooCommerce** | Produktanfragen, Reklamation, Angebotsanfrage, Checkout-/Bestellbezug und Zahlung. | Optionaler Adapter über WooCommerce CRUD/Hook APIs (HPOS und Blocks berücksichtigen). IDs statt serialisierter Bestellobjekte übergeben; Zahlungsauslösung nur mit serverseitig validiertem, idempotentem Payment-Provider. |

### Integrationsausbau in Stufen

1. **MVP:** Gutenberg/Shortcode, WooCommerce-Kontext als versteckter signierter Referenzwert, Ereignis-Hooks und Developer-Dokumentation.
2. **Erste Suite-Adapter:** Pass Captain und Voucher Captain für Einbettung/Anfragen; klare Aktionen im Form-Editor; keine automatische Geschäftsaktion ohne Review.
3. **Affiliate/Review:** Bewerbung, Feedbackanfrage, freigegebene Übergabe und Auswertung.
4. **Externe Dienste:** Webhooks als generische Grundlage; spezifische CRM-/Payment-Connectoren jeweils nach Nachfrage und API-Prüfung.

## 6. Admin-Seiten und Frontend-Seiten

| Seite | Aufgabe und Inhalt |
|---|---|
| **Dashboard** | Setup-Status; Anzahl aktiver Formulare und neue Einsendungen; letzte Formulare; Systemhinweise; Schnellaktionen „Formular erstellen“, „Einträge ansehen“, „Seite erstellen“. Keine globalen störenden Upgrade-Banner. |
| **Formulare** | Tabelle mit Name, Status, Einbettungscode/Block-Hinweis, Einträge, Änderungsdatum; Suche/Filter; Aktionen Bearbeiten, Duplizieren, Vorschau, Export, Pausieren, Löschen. |
| **Formular erstellen / Editor** | Vorlagenauswahl (Kontakt, Anfrage, Newsletter, Eventanmeldung); Feldpalette; Canvas; Feld-Einstellungen; Formular- und Benachrichtigungseinstellungen; Vorschau Desktop/Mobil; Veröffentlichung. |
| **Einträge** | Tabelle mit Formular, Datum, Status, Name/E-Mail soweit vorhanden; Filter, Suche, Bulk-Aktionen, Export. Rollen und Capabilities müssen vor Abfrage greifen. |
| **Eintragsdetail** | Antwortwerte, Quelle/Formular, Zeitstempel, Status, interne Notiz, Spamgrund, Lösch-/Exportaktion. Keine vollständigen IP-Adressen oder unnötigen Browser-Fingerprints. |
| **Integrationen** | Kacheln für verfügbare Adapter, erkannte Suite-Plugins, Verbindung/Status, Feldmapping, Testlauf, letzte erfolgreiche und fehlgeschlagene Aktion. Pro-Verbindungen klar mit Lizenzstatus versehen. |
| **Einstellungen** | Allgemein, Absender/SMTP-Hinweis, Datenschutz/Aufbewahrung, Spamschutz, Eintragsrechte, Import/Export, Systemstatus und Entwickler-API. |
| **Formular-Frontend** | Form mit Labeln, Pflichtfeld-Hinweisen, Inline-Fehlern, Erfolgsmeldung, Tastaturbedienung, Screenreader-Ansagen und responsivem Layout. |
| **Optionale Systemseiten** | Erfolg-/Danke-Seite per WordPress-Seitenauswahl; keine ungefragt angelegten öffentlichen Seiten. Pro: Fortsetzen-Link, Upload-Bestätigung, Zahlungsstatus. |

### Inhalt der Ersteinrichtung

1. Formularzweck auswählen; ein importierbares Beispiel wird erstellt.
2. Empfängeradresse und Absender prüfen.
3. Formular auf einer bestehenden oder neu gewählten Seite einsetzen (Gutenberg-Block/Shortcode).
4. Testeinsendung auslösen und Admin-Eintrag/E-Mail-Status zeigen.
5. Datenschutz- und Retention-Einstellungen wählen.
6. Suite-Plugins optional erkennen und Adapter später aktivieren.

## 7. Datenmodell und technische Leitplanken

- Formulardefinition versioniert als JSON-Schema mit stabilen Feld-IDs; Einreichungen werden tabellarisch in eigener Entry-Tabelle gespeichert, Antwortwerte strukturiert und schema-versioniert. Keine Einsendungen als WordPress-Post pro Submission.
- Entry und Datei-Referenzen getrennt; private Dateiablage und Lösch-/Ablaufjobs für Uploads.
- Submission-Service zentralisiert: Sanitizing, Validierung, Rate-Limit, Speicherung, E-Mail/Workflow-Queue und Integrationsereignisse in definierter Reihenfolge.
- Externe Aktionen asynchron ausführen können, mit Idempotenz, Retry-Limits, Dead-letter-/Fehlerstatus und Admin-Protokoll. Kernsubmission darf durch ausgefallene CRM-/Webhook-Dienste nicht verloren gehen.
- REST-Routen mit WordPress-Nonce für Admin und geeigneter öffentlicher Token-/Rate-Limit-Strategie für Einreichung; öffentliche Submit-Routen erlauben nur definierte Operationen.
- HPOS-kompatibler Woo-Adapter; keine Abhängigkeit von WooCommerce für das Kernplugin.
- Multisite: Daten pro Site, Netzwerkaktivierung mit klarer Datenmigration und Capabilities; Entscheidung vor MVP festlegen.
- Uninstall löscht Formulare/Einträge nur nach expliziter Admin-Auswahl und dokumentierter Bestätigung.
- PHP-/WordPress-Mindestversion erst anhand der CI-Matrix und verbreiteter PHP-Versionen festlegen.

## 8. Lizenzierung und Verkauf

Die Lizenzierung folgt dem bestehenden Captain-Suite-Modell:

1. **Form Captain Free** wird als eigenständiges GPL-Kernplugin auf WordPress.org angeboten.
2. **Form Captain Pro** wird als privates Add-on in hastla007/captain-pro geführt; Slug, Add-on-Ordner, Textdomain und Produktschlüssel: form-captain-pro.
3. Der Lizenzserver ist WP License Captain (hastla007/wp-license). Dort wird das Produkt mit Laufzeit, Site-Limit, Support-Level und Entitlements konfiguriert.
4. Pro-Add-on vendort den Lizenzclient in eigenem Namespace. Der Add-on-Kernel prüft Kernplugin-Version, verbindet die Lizenzseite unter Form Captain und startet Features nur bei passenden Rechten.
5. Der Updater validiert signierte Metadaten sowie Paketgröße und SHA-256. Lizenzstatus und Offline-Cache folgen dem bestehenden Client-Vertrag.
6. Ablauf/Deaktivierung der Lizenz stoppt nur Pro-Aktionen/Updates gemäß Vertrag. Free-Formulare und bestehende Einträge bleiben erhalten.
7. Preise, Paketnamen, Laufzeiten, Verlängerungs- und Supportbedingungen werden separat entschieden. Keine Preise oder unbelegte Leistungszusagen in diesem Konzept festschreiben.

## 9. Umsetzungsplan und To-do

### Phase 0 – Produktentscheidungen und Verträge

- [ ] Kern-/Pro-Grenze nach diesem Dokument bestätigen.
- [ ] Plugin-Namen, Ordner, Textdomains, Konstanten und Rechtepräfix festlegen.
- [ ] Formulardaten-Schema, stabile Feld-IDs und Schema-Migrationen entwerfen.
- [ ] Capability-/Datenschutzmodell und Aufbewahrungs-Defaults festlegen.
- [ ] Versionierten Form-Captain-Integrationsvertrag v1 spezifizieren.
- [ ] Pro-Produkt form-captain-pro samt Entitlements im WP License Captain planen.
- [ ] WordPress-, PHP-, WooCommerce- und Multisite-Support-Matrix festlegen.
- **Abnahme:** Architekturentscheidungen und öffentliche Verträge sind dokumentiert, bevor Implementierungen von Suite-Plugins abhängen.

### Phase 1 – Free-MVP

- [ ] Plugin-Gerüst, Übersetzung, Aktivierung/Deaktivierung und DB-Schema/Migrationen.
- [ ] Formularliste, Vorlagen und visueller Editor mit Kernfeldern.
- [ ] Serverseitige Submission-Validierung, Nonces/Capabilities und sichere öffentliche Route.
- [ ] Eigene Entry-Speicherung, Suche, Status, Detailansicht, Papierkorb.
- [ ] Admin- und Nutzer-E-Mail mit konfigurierbaren Bestätigungen.
- [ ] Honeypot, Mindestzeit und Rate-Limit.
- [ ] Shortcode, Gutenberg-Block, responsives und barrierearmes Frontend.
- [ ] CSV-Export, Formular-JSON-Import/Export, WordPress Privacy Exporter/Eraser.
- [ ] Setup-Assistent und Beispiel-Kontaktformular.
- **Abnahme:** Kontaktformular lässt sich ohne Pro erstellen, einbetten, absenden, sicher speichern, verwalten und exportieren.

### Phase 2 – Release-Vorbereitung Free

- [ ] Security review und Missbrauchs-/Lastgrenzen prüfen.
- [ ] WordPress.org-readme, Screenshots, FAQ, externe Services und Datenschutzoffenlegung.
- [ ] Übersetzungsworkflow und mindestens Deutsch/Englisch.
- [ ] Upgrade-/Rollback-Pfade für Schemaänderungen.
- [ ] Importer für Contact Form 7 und Export-/Migrationen für priorisierte Mitbewerber spezifizieren.
- [ ] Kompatibilitätsmatrix mit WooCommerce, Block-Editor und gängigen Caching-/Security-Plugins.
- **Abnahme:** reproduzierbarer ZIP-Build, dokumentierter Supportumfang und getesteter Update-/Deinstallationspfad.

### Phase 3 – Form Captain Pro und Lizenzierung

- [ ] captain-pro Add-on-Grundgerüst nach bestehendem Add-on-Kernel.
- [ ] Lizenzclient vendoren; Produktkey, Serveradresse und öffentlicher Schlüssel konfigurieren.
- [ ] Lizenz- und Feature-Seite in Form Captain einhängen.
- [ ] Rechte für Multi-Step, Uploads, Workflows, PDF, Webhook und Integrationen registrieren.
- [ ] Pro-Features nur im Add-on implementieren; Kern-Hooks dafür neutral und ohne Lizenzprüfung anbieten.
- [ ] Lizenzverlust-, Offline-Cache-, Update- und Deaktivierungsablauf dokumentieren.
- **Abnahme:** aktives Add-on mit gültiger Lizenz schaltet Pro-Funktionen frei; deaktiviertes Add-on lässt Formulare/Einträge intakt.

### Phase 4 – Suite-Adapter

- [ ] Pass-Captain-Einbettung und Event-Kontext-Adapter.
- [ ] Voucher-Captain-Anfrage/Firmenbestell-Adapter mit explizitem Bestätigungsschritt.
- [ ] Affiliate-Captain-Bewerbung und manuelle Freigabe.
- [ ] Review-Captain-Feedbackanfrage mit Zustimmung, Opt-out und Frequenzlimit.
- [ ] WooCommerce-Adapter für Produkt-/Bestellkontext (HPOS, Classic und Blocks soweit sinnvoll).
- [ ] Vertragstests gegen jede unterstützte Suite-Version sowie Diagnoseansicht.
- **Abnahme:** Kein Adapter benötigt direkten Tabellenzugriff; Wiederholung eines Ereignisses dupliziert keine Aktion.

### Phase 5 – Ausbau und Priorisierung

- [ ] Migrationen aus WPForms, Gravity Forms, Fluent Forms, Ninja Forms und Formidable priorisiert nach Nutzerbedarf.
- [ ] Mehrsprachigkeit (WPML/Polylang) und Multisite-Verhalten.
- [ ] Zahlungsintegration mit offizieller API und geprüften Refund-/Webhook-Abläufen.
- [ ] CRM-Connectoren nach Nachfrage priorisieren; zuerst dokumentiertes Webhook- und Fehlerprotokoll.
- [ ] Agentur-/White-Label-Funktionen nur nach Validierung von Lizenz- und Supportkosten.
- **Abnahme:** jeder zusätzliche Connector hat einen klaren Eigentümer, API-/Datenschutzprüfung, Fehlerbehandlung und Wartungsplan.

## 10. Risiken und Entscheidungen vor Produktionsstart

- **E-Mail-Zustellung:** WordPress wp_mail() allein garantiert keine Zustellung. SMTP-Anleitung, Absenderprüfung und nachvollziehbarer Versandstatus bereitstellen.
- **Personenbezogene Daten:** Formulare können sensible Daten sammeln. Minimierung, Rollen, Retention, Export/Löschung und externe Datenübertragung klar steuern.
- **Upload-Risiko:** Dateitypen, Malware-Prüfung/Serverlimits, Zugriffsschutz, Speicherort und Bereinigung vor Veröffentlichung festlegen.
- **Zahlungen:** PCI-/Provider-Anforderungen, Webhook-Sicherheit, Rückerstattungen und Doppelbuchungen vor dem Zahlungsfeature prüfen.
- **Plugin-Überladung:** MVP auf Formularbau, Einträge, Spam-Schutz und verlässliche Benachrichtigung begrenzen.
- **Kompatibilität:** WooCommerce Checkout Blocks und HPOS, Caching, Multisite und Pagebuilder früh in der Supportmatrix berücksichtigen.
- **WordPress.org-Regeln:** Free-Kern frei nutzbar halten; Premiumcode und dessen eigener Updater im separaten Add-on.
- **Integrationswartung:** Keine Suite-Integration nur über nicht versionierte interne Klassen. Vertragstests und Deprecation-Policy sind Voraussetzung.

## 11. Erfolgskriterien

- Neuer Nutzer kann das Beispiel-Kontaktformular ohne Dokumentation veröffentlichen und erfolgreich testen.
- Eine Formularantwort geht nicht verloren, wenn Mail oder externe Integration ausfällt.
- Einträge sind nur für berechtigte Rollen sichtbar und können datenschutzkonform exportiert/löschen werden.
- Das Frontend lädt keine nicht benötigten Drittanbieterressourcen und ist per Tastatur verwendbar.
- Jede Suite-Aktion ist nachvollziehbar, wiederholbar und idempotent.
- Free ist ohne Lizenz verwendbar; Pro-Funktion und Update sind nach Lizenzserver-Vertrag überprüfbar.
- Migrationen erhalten Feldzuordnungen und Entry-Daten, soweit die Quellformate dies ermöglichen.