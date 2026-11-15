# Form Captain – Produktkonzept

**Status:** Produkt- und Umsetzungsgrundlage  
**Stand:** 15. November 2026  
**Produktname:** Form Captain  
**Repository:** hastla007/wp-formmaster  
**WordPress-Kernplugin:** form-captain  
**Premium-Add-on:** form-captain-pro  
**Lizenzserver-Produktschlüssel:** form-captain-pro (im License Captain-Katalog anzulegen)  
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

## 1.1 Verbindliche Produktentscheidungen für Version 1

Diese Entscheidungen gelten als Planungsgrundlage für die erste Umsetzung. Änderungen werden als bewusste Produktentscheidung dokumentiert, nicht stillschweigend während der Implementierung.

| Entscheidung | Festlegung |
|---|---|
| **Erster Zielkunde** | Kleine und mittlere deutschsprachige Unternehmen sowie WooCommerce-Betreiber, die Anfragen verlässlich erfassen und im eigenen WordPress beantworten möchten. Agenturen sind die zweite Zielgruppe. |
| **Primärer Einsatz** | Kontakt-, Angebots- und Supportanfragen. Event-, Gutschein- und Affiliate-Szenarien folgen als Suite-Adapter, nicht als Voraussetzung für ein funktionierendes Formularprodukt. |
| **Positionierung** | „Formulare und Einsendungen direkt in WordPress, mit verlässlichen Suite-Verbindungen und ohne externe Abhängigkeit für den Kernbetrieb.“ |
| **Free-Versprechen** | Unbegrenzte Formulare und Einsendungen; im Free gibt es keine künstliche Submission-Grenze, keine Pflichtregistrierung und keinen Lizenzserverkontakt. |
| **Pro-Logik** | Ein einziges eigenständiges Add-on mit vollständigem Pro-Funktionsumfang; keine Einzel-Add-ons. Pro-Tarife unterscheiden sich nur durch Site-Limit und Support. |
| **MVP v1.0** | Formulareditor, 3 Vorlagen (Kontakt, Angebot, Support), Kernfelder, einfache bedingte Feldanzeige, Einträge, E-Mail, Basisspamschutz, Export, Block/Shortcode, Datenschutzwerkzeuge und Installationsassistent. |
| **Nicht im MVP** | Zahlungen, Datei-Uploads, PDF-Erzeugung, Multi-Step, CRM-spezifische Connectoren, Abandonment, Umfragen/Quiz, öffentliche Entry-Ansichten, Multisite-Netzwerkverwaltung und KI-Formularerstellung. |
| **Erste Pro-Version** | Multi-Step, Datei-Uploads, erweiterte Bedingungen/Routing, Webhooks, PDF aus Einsendungen und Integrations-/Fehlerprotokoll. Zahlungen folgen erst nach stabiler v1.x und separater Provider-/Refund-Prüfung. |
| **Suite-Reihenfolge** | Öffentlicher Vertrag ab Free v1.0; zuerst Pass Captain (Einbettung/Event-Kontext), danach Voucher Captain (Anfrage/Firmenbestellung), anschließend Review Captain (optionale lokale Review-Übergabe) und Affiliate Captain. |
| **Unterstützungsumfang v1** | WordPress 6.8+, PHP 8.3+, WooCommerce optional ab Version 8.0; HPOS und klassischer Bestellspeicher. CI prüft zusätzlich die aktuelle stabile WordPress-Version und unterstützte PHP-Versionen. Checkout-Block-Integration ist kein MVP-Ziel. Keine Multisite-Unterstützung in v1.0. |
| **Sprachen v1** | Englisch als Quelltextsprache und de_DE/de_AT Übersetzungen; weitere Übersetzungen nach stabiler Textdomain und Übersetzungsworkflow. |

**Produktabgrenzung:** Form Captain ist zunächst kein allgemeiner No-Code-App-Builder. Keine dynamischen Datenbanken, Kundenportale oder komplexen Genehmigungsketten in v1. Diese würden Support- und Sicherheitsumfang unverhältnismäßig erhöhen.

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
| **Ninja Forms** | Kostenloser Kern mit einzeln kaufbaren Add-ons bzw. Paketen; Erweiterungen für Logik, mehrseitige Formulare, Zahlungen, CRM und Automationen. | Einheitliche Lizenzierung über License Captain und ein zusammenhängendes Pro-Add-on statt vieler Einzellizenzen. |
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
- [WordPress.org Voraussetzungen](https://wordpress.org/about/requirements/) (PHP 8.3+ empfohlen; PHP 7.4 ist EOL).
- Preisanker: [WPForms Pricing](https://wpforms.com/pricing/), [Gravity Forms Pricing](https://www.gravityforms.com/pricing/), [Fluent Forms Pricing](https://fluentforms.com/pricing/), [Formidable Forms Pricing](https://formidableforms.com/pricing/) und [Ninja Forms Pricing](https://ninjaforms.com/pricing/).

### Preis- und Tarifbeobachtung (Stand 15.11.2026)

Die angezeigten US-Preise sind Listenpreise bzw. der auf der offiziellen Seite ausgewiesene Preis; Aktionspreise und Verlängerungsbedingungen unterscheiden sich. Die Beobachtung dient der Positionierung und ist kein direkter EUR-Preisvergleich:

| Wettbewerber | Öffentliche Jahrespreis-Anker | Lizenzstaffel / Signal |
|---|---|---|
| WPForms | Basic regulär 99 USD; Plus 199 USD; Pro 399 USD; Elite 599 USD. Die Seite bewirbt rabattierte Erstjahre und Verlängerung zum Listenpreis. | 1 / 3 / 5 / unbegrenzt Sites; Featureumfang nach Tier. |
| Gravity Forms | Basic 59 USD; Pro 159 USD; Elite 259 USD pro Jahr. | 1 / 3 / unbegrenzt Sites; Add-on-Pakete nach Tier. |
| Fluent Forms | Offizielle FAQ nennt Start ab 79 USD/Jahr; Preisseite zeigt 1 / 5 / unbegrenzt und zusätzlich Lifetime-Angebote. | Niedriger Preisanker, großzügiger Free-Tarif, umfangreiche Pro-Funktionen. |
| Formidable Forms | Listenpreise 79 / 199 / 399 / 599 USD/Jahr; Aktionen senken oft nur das erste Jahr. | Feature- und Site-Limits je Tarif; positioniert sich für komplexe/dynamische Formulare. |
| Ninja Forms | Core kostenlos; Plus/Pro/Elite Jahrespakete mit Add-ons; Preisseite nennt regulär 99 / 199 / 499 USD und rabattierte Erstjahre. | Kostenloser Kern plus einzelne Add-ons und Pakete. |

**Schlussfolgerung für Form Captain:** Die Free-Version muss echte Eintragsverwaltung, Export und einfache Logik enthalten; sonst ist der Wechselgrund schwach. Pro sollte zum Start einfacher und günstiger als die etablierten Jahrespakete sein, aber nicht als Billig- oder Lifetime-Produkt positioniert werden. Aktionen dürfen den regulären Verlängerungspreis nicht verschleiern.

### Vorgeschlagene Form-Captain-Pro-Preise zum Start

| Tarif | Jahrespreis (EUR, zzgl. USt.) | Live-Sites | Support |
|---|---:|---:|---|
| **Solo** | 49 € | 1 | Standard |
| **Studio** | 99 € | 5 | Standard |
| **Agency** | 199 € | 25 | Priorisiert |

Alle Tarife enthalten denselben Pro-Funktionsumfang, Updates und Support während der aktiven Laufzeit. Site-Limits zählen Live-Installationen; pro Live-Site ist eine Staging-Aktivierung enthalten, Development-Aktivierungen zählen nicht zum Limit, analog zum vorhandenen License Captain-Modell. Keine Lifetime-Lizenz und keine kostenpflichtigen Einzel-Add-ons zum Start. Preise nicht mit künstlichem „Normalpreis/Rabatt“-Doppelpreis vermarkten. Die Lizenz läuft 12 Monate und verlängert sich zum beim Kauf angezeigten Tarif, sofern im Checkout transparent zugestimmt wurde. Preisänderungen betreffen künftige Verlängerungen nur nach vorheriger Information und im Rahmen der Shopbedingungen.

**Begründung:** Der Einstieg liegt nahe an einem bekannten 1-Site-Preisanker, die 5-Site-Stufe deckt kleine Agenturen ab und 25 Sites bilden einen klaren Agency-Tier. Die geringere Startposition gegenüber etablierten Produkten berücksichtigt den kleineren Funktions- und Supportumfang zum Launch. Preise nach den ersten 6 Monaten anhand von Conversion, Supportkosten und Verlängerungsquote neu bewerten; bis dahin keine zusätzliche Tarifstufe einführen.


## 4. Funktionsumfang

### Verbindlicher Release-Schnitt

**Free v1.0 (WordPress.org)**

- Unbegrenzte Formulare, Felder und Einsendungen; genau die Feldarten aus Abschnitt 4.1.
- Drei direkt nutzbare Vorlagen: Kontakt, Angebotsanfrage, Support.
- Eine einfache Feldregel je Feldgruppe: anzeigen/ausblenden anhand eines Feldwerts. Keine verschachtelten Gruppen und keine bedingten Aktionen.
- Einträge mit Status neu/gelesen/archiviert/Spam; Suche, Datums-/Formularfilter, Detailseite und CSV-Export.
- Admin-Mail, optionales Nutzer-Receipt und eine Abschlussmeldung oder URL-Weiterleitung.
- Honeypot, Mindestzeit und zeitbasiertes Rate-Limit; kein externer Captcha-Dienst standardmäßig.
- Gutenberg-Block und Shortcode; kein Formular-Hosting außerhalb der Installation.
- Privacy Exporter/Eraser, Aufbewahrungsoption, Consent-Feld und ausdrückliche Bestätigung externer Dienste.
- Öffentlicher Contract v1 mit Events und Dokumentation; in v1.0 noch keine Suite-Automation, aber keine inkompatible private Schnittstelle.

**Pro v1 (separates Add-on)**

- Mehrseitige Formulare mit Fortschritt und Fortsetzen-Link.
- Datei-Upload mit Allowlist, Größenlimit, geschützten Downloads und automatischer Löschung.
- Verschachtelte AND/OR-Bedingungen und bedingte Bestätigungen/Benachrichtigungsziele.
- Webhook-Aktionen mit Feldzuordnung, Signatur, Idempotenz, Retries und Protokoll.
- PDF-Generierung aus Entry-Vorlage.
- Lizenz-/Updater-Seite und Updatefeed nach License Captain Vertrag.

**Nach v1.0, in dieser Reihenfolge**

1. Contact Form 7-Formularimport mit Konvertierungsbericht; Entry-Import nur, wenn eine kompatible Exportquelle vorliegt.
2. Pass Captain-Adapter: Eventformular aus Eventseite einbetten, Event-ID als verifizierbarer Kontext, keine automatische Ticket-/Bestellausstellung.
3. Voucher Captain-Adapter für Gutscheinanfrage/Firmenbestellung nach expliziter Prüfung und Bestätigung.
4. Affiliate- und Review-Adapter.
5. CRM-Connectoren; Zahlungen nur nach stabiler Webhook-/Idempotenzbasis und eigener Provider-/Refund-Spezifikation.
6. Weitere Migrationen, Analytics, White-Label und Multisite nur nach Nutzungsdaten/Nachfrage.

**Wichtig für die Konkurrenzfähigkeit:** Einfache bedingte Anzeige wird in Free geliefert, weil sie bei starken kostenlosen Wettbewerbern Teil des Basispakets ist. Die bezahlte Abgrenzung liegt bei komplexen Regeln und Aktionen statt bei einer künstlichen Sperre der einfachsten Logik.

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

### 4.2 Form Captain Pro – Add-on-Roadmap

Der folgende Funktionskatalog beschreibt die mittelfristige Pro-Roadmap. **Verbindlich für den ersten Verkauf ist ausschließlich „Pro v1“ im Release-Schnitt oben.** Zahlungsfunktion, direkte CRM-Connectoren und White-Label sind keine Launch-Zusagen.

| Bereich | Pro-Funktionen |
|---|---|
| **Form Builder** | Mehrspaltige Layouts, wiederverwendbare Feldgruppen, erweiterte Feldtypen (Datei, Signatur, Bewertung, Adresse), bedingte Felder und Abschnitte, Multi-Step-Formulare mit Fortschritt, mehr Regeln für Bestätigung und Benachrichtigungsrouting. |
| **Workflows** | Bedingte Aktionen und Benachrichtigungen, Einträge zuweisen, interne Aufgaben/Status, geplante Formulare und Submission Limits, Webhooks mit Feldmapping, Wiederholungswarteschlange und Ausführungsprotokoll. |
| **Dateien und Dokumente** | Sichere Datei-Uploads mit Typ-/Größenlimit, private Speicherung außerhalb des öffentlichen Verzeichnisses soweit möglich, Ablauf/Löschung, PDF aus Entry mit Vorlage und Anhängen; niemals unsicher ausführbare Dateitypen zulassen. |
| **Daten und Ausgabe** | Berechnungen, Umfragen/Quiz mit Basisreport, PDF/CSV-Export, Entry-Ansicht im Frontend nur für freigegebene Scopes, Entry-to-Post mit expliziter Feldzuordnung und moderiertem Status. |
| **Zahlungen** | Gebühren/Spenden und Bestell-/Registrierungsbeträge über offizielle Zahlungsprovider-APIs; Provider-Webhook-Verifikation, Idempotenzschlüssel, Refund-/Fehlerstatus. Keine Kartendaten im WordPress speichern. MVP erst nach eigenständiger Zahlungs- und Compliance-Prüfung. |
| **Integrationen** | CRM-/Newsletter-Connectoren, Zapier/Make/n8n via Webhook oder dokumentiertem Adapter; Integrationsstatus, Testverbindung, Feldzuordnung und Fehlerjournal. |
| **Betrieb** | Entry-Abandonment/Partial Entries, Versionshistorie und Wiederherstellung, erweiterter Export/Import und unterstützte Migrationen; Agentur-/White-Label-Option erst nach Nachfragevalidierung. |

**Pro-Schnitt:** Der Free-Kern bleibt für einfache Formulare dauerhaft brauchbar. Pro monetarisiert Automatisierung, komplexe Workflows und aufwendige Betriebsfunktionen. Kernformulare, die im Free erstellt wurden, bleiben auch ohne Pro unverändert nutzbar; Pro-Daten bleiben gespeichert und werden nach Lizenzende nicht gelöscht. Die Aktivierung folgt dem gemeinsamen Add-on-Kernel: Feature-Rechte werden aus dem signierten Lizenzstatus gelesen, täglich geprüft und bis zu 14 Tage offline zwischengespeichert; nur autorisierte Pro-Features registrieren schreibende Aktionen. Bei fehlendem Recht bleiben Free-Formulare und Einträge intakt, Pro-Editor-/Workflow-Aktionen pausieren mit erklärendem Admin-Hinweis und Pro-Updates stehen nicht zur Verfügung.

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

### Vertrag v1 – feste Namenskonvention und Mindestereignisse

Der Kern definiert in v1 folgende öffentliche, stabile Namensfamilie; Ereignisse feuern erst nach erfolgreichem Datenbank-Commit:

- Versionskonstante: FORM_CAPTAIN_CONTRACT_VERSION = 1.0.0.
- Aktionen: form_captain_form_created_v1, form_captain_form_updated_v1, form_captain_entry_created_v1, form_captain_entry_status_changed_v1 und form_captain_entry_deleted_v1.
- Admin-Menü: form_captain_admin_menu_built_v1($parent_slug), damit Pro und Suite-Adapter ihre Seiten als Unterseiten ergänzen können.
- Öffentliche Read-API: form_captain_get_form_v1($form_id), form_captain_get_entry_v1($entry_id, $scopes) und form_captain_submit_v1($form_id, $payload, $context).
- Erweiterungspunkt für Adapter: form_captain_register_action_v1($key, $definition, $callback) registriert eine Formularaktion mit Version, Einstellungsfeldern, Mapping/Validierung und Callback. Sie darf erst nach form_captain_loaded_v1 registriert werden. Beim Senden erhält der Callback nur die vom Administrator zugeordneten, validierten Werte plus form_id, entry_id und idempotency_key; keine vollständige Submission außerhalb des Scopes.
- Ergebnisobjekt: success, code, form_id/entry_id und request_id; erwartete Validierungs-/Berechtigungsfehler werden als Ergebnis zurückgegeben.
- Ein Adapter registriert sich erst nach form_captain_loaded_v1 und prüft die kompatible Vertrags-Major-Version. Inkompatible Major-Versionen werden deaktiviert und mit Code incompatible_contract in der Diagnose angezeigt.
- Entry-Ereignisse liefern standardmäßig IDs, Form-ID, Zeitstempel und eine Allowlist explizit freigegebener Werte; keine vollständigen WordPress- oder WooCommerce-Objekte, Zahlungsdaten oder rohe IP-Adresse.
- Jede schreibende Integration verlangt serverseitige Berechtigung und Idempotency-Key. Externe Fehler ändern keinen bereits gespeicherten Entry; sie erzeugen einen wiederholbaren Integrationsjob.

### Konkrete Integrationen

| Plugin | Möglicher Nutzen | Form Captain Vertrag / Schutz |
|---|---|---|
| **License Captain** | Pro-Aktivierung, Rechteprüfung, signierte Offline-Rechte und Pro-Updates. | Nur form-captain-pro verwendet den vorhandenen PHP License Client und Produktschlüssel form-captain-pro. Kern bleibt lizenzfrei; Lizenzseite wird unter Form Captain eingehängt. Feature-Rechte z. B. form.uploads, form.workflows, form.payments. |
| **Pass Captain** | Anmelde-/Bewerbungsformulare für Events, Anmeldedaten als Ausgang für Registrierung oder Ticketverkauf, Formlink auf Eventseiten. | Pass Captain ruft eine öffentliche Form-Captain-Funktion zum Einbetten/Ermitteln eines Formulars auf oder erhält eine freigegebene Submission-Referenz. Ticket-Erstellung nur als explizite Aktion in Pass Captain Pro/Kernvertrag; keine stillschweigende Ausstellung aufgrund einer Formularantwort. |
| **Voucher Captain** | Gutschein-Anfragen, Firmenbestellungen und Geschenkpersonalisierung. | Öffentliche Voucher-Ausstellungs-/Firmenbestell-API verwenden. Beträge, Empfänger und Einwilligung klar zuordnen; kein direkter Gutschein-Tabellenzugriff. Gutschein wird nur nach expliziter Bestätigung und optionaler Zahlung ausgestellt. |
| **Affiliate Captain** | Partnerbewerbungen, Kontaktdaten und manuell freigegebene Anmeldungen; Referral-/Affiliate-Code als Formularfeld. | Affiliate-Vertrag zur Partneranlage/Statusänderung nutzen, falls vorhanden; keine automatische Freigabe ohne Administratorentscheidung. Referral-Code als attributiertes Feld übergeben, keine Trackingdaten ohne Zustimmung. |
| **Review Captain** | Optional Form-Captain-Formulare als Eingabeoberfläche für lokale Reviews nutzen. | Review Captain Pro registriert eine Formularaktion „Review zur Moderation übergeben“ und übergibt explizit zugeordnete Felder an die Review-Captain-API. Review Captain besitzt Review, Herkunft, Moderation und Veröffentlichung; Form Captain besitzt Formular und Einsendung. Nicht verifizierte Formantworten werden nie als Kauf-/Teilnahme-verifiziert markiert. Formulareinsendungen lösen keine Review-Einladung aus. |
| **WooCommerce** | Produktanfragen, Reklamation, Angebotsanfrage, Checkout-/Bestellbezug und Zahlung. | Optionaler Adapter über WooCommerce CRUD/Hook APIs (HPOS und Blocks berücksichtigen). IDs statt serialisierter Bestellobjekte übergeben; Zahlungsauslösung nur mit serverseitig validiertem, idempotentem Payment-Provider. |

### Festgelegte Form-Captain-Brücke

- **Keine gegenseitige Pflichtabhängigkeit:** beide Free-Plugins funktionieren einzeln. Der Review-Adapter ist ein späterer Suite-Ausbau; er benötigt Form Captain Core und Review Captain Pro mit aktivem Entitlement review.suite_integrations. Form Captain Pro ist für den einfachen Übergang nicht nötig.
- **Datenbesitz:** Form Captain bleibt System of Record für Formular, vollständige Einsendung und deren Aufbewahrung. Review Captain ist System of Record für die daraus erzeugte lokale Review, Moderationsstatus und öffentliche Anzeige. Es gibt keine direkten Tabellenzugriffe.
- **Explizite Aktion statt stiller Automatik:** ein Administrator ordnet im Formular ein Zahlen-, Options- oder Auswahlfeld mit zulässigen Werten 1–5 als Sternebewertung sowie Reviewtext und optionalen Anzeigenamen zu; für v1 ist kein neues Feld nötig und aktiviert „Review Captain: lokale Review zur Moderation anlegen“. Eine gültige Einsendung erzeugt nur einen wartenden Review; es gibt keine automatische Veröffentlichung oder Anfragen-E-Mail.
- **Herkunft:** Review-Datensatz speichert source_plugin=form-captain und source_entry_id. Der Idempotency-Key lautet form-captain:{site_id}:{entry_id}; Wiederholungen erzeugen keine Review-Duplikate.
- **Verifizierungsstatus:** Formulareingaben sind standardmäßig „nicht verifiziert“. Ein Nutzerfeld oder versteckter Wert kann niemals Kauf/Teilnahme nachweisen. Nur ein vertrauenswürdiger Commerce-/Event-Vertrag darf verifiziert, kaufbestätigt oder teilnahmebestätigt setzen.
- **Löschung:** wird die Quell-Einsendung gelöscht, entfernt Review Captain den Verweis und löscht standardmäßig die noch wartende, daraus erzeugte Review. Freigegebene Reviews werden über die Review-Captain-Privacy- und Moderationsregel entfernt/anonymisiert; dies wird in beiden Admins nachvollziehbar angezeigt.
- **Einladungen:** Review Captain plant öffentliche Plattform-Anfragen nur aus vertrauenswürdigen, vom Quell-Plugin bestätigten Erlebnisereignissen mit experience_id, Kontaktresolver und Versandberechtigung. form_captain_entry_created_v1 allein ist dafür kein zulässiger Trigger.
- **Einführungszeitpunkt:** nicht Teil von Free v1.0. Erst nach stabilen Form-Captain- und Review-Captain-Core-Verträgen bauen und Ende-zu-Ende testen.

### Integrationsausbau in Stufen

1. **MVP:** Gutenberg/Shortcode, WooCommerce-Kontext als versteckter signierter Referenzwert, Ereignis-Hooks und Developer-Dokumentation.
2. **Erste Suite-Adapter:** Pass Captain und Voucher Captain für Einbettung/Anfragen; klare Aktionen im Form-Editor; keine automatische Geschäftsaktion ohne Review.
3. **Review Captain:** optionale Formularaktion für lokale Reviews in Review-Captain-Moderation; Review-Anfragen weiterhin ausschließlich über vertrauenswürdige Experience-Events. Danach Affiliate-Adapter.
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

**v1-Datenmodell (Entscheidung):** Tabellen mit WordPress-Präfix für Formulare, Entries und Entry-Werte; keine WordPress-Post-Zeile pro Antwort. Formulare haben numerische interne ID, öffentliche zufällige Kennung, Status, schema_version und ein validiertes JSON-Schema. Entries speichern Form-ID, Status, UTC-Zeitstempel, Lösch-/Retention-Datum und minimale Quellenmetadaten. Werte sind zeilenweise über entry_id + field_id abgelegt, damit CSV- und Privacy-Export gezielt möglich ist. Sensible Feldwerte werden nicht in Debug- oder Integrationslogs kopiert. Schemaänderungen erhalten versionierte Migrationen; Form-JSON-Import nimmt niemals Einsendungen oder Uploads ungefragt mit. Neue Installationen verwenden standardmäßig eine Aufbewahrungsfrist von 12 Monaten; bei der Einrichtung kann der Betreiber 90 Tage, 12 Monate oder manuelle Löschung wählen. Vor Ablauf werden keine Einsendungen automatisch ohne Admin-Hinweis gelöscht; gelöschte Einträge werden endgültig bereinigt, einschließlich referenzierter Dateien.

- Formulardefinition versioniert als JSON-Schema mit stabilen Feld-IDs; Einreichungen werden tabellarisch in eigener Entry-Tabelle gespeichert, Antwortwerte strukturiert und schema-versioniert. Keine Einsendungen als WordPress-Post pro Submission.
- Entry und Datei-Referenzen getrennt; private Dateiablage und Lösch-/Ablaufjobs für Uploads.
- Submission-Service zentralisiert: Sanitizing, Validierung, Rate-Limit, Speicherung, E-Mail/Workflow-Queue und Integrationsereignisse in definierter Reihenfolge.
- Externe Aktionen asynchron ausführen können, mit Idempotenz, Retry-Limits, Dead-letter-/Fehlerstatus und Admin-Protokoll. Kernsubmission darf durch ausgefallene CRM-/Webhook-Dienste nicht verloren gehen.
- REST-Routen mit WordPress-Nonce für Admin und geeigneter öffentlicher Token-/Rate-Limit-Strategie für Einreichung; öffentliche Submit-Routen erlauben nur definierte Operationen.
- HPOS-kompatibler Woo-Adapter; keine Abhängigkeit von WooCommerce für das Kernplugin.
- Multisite: Daten pro Site, Netzwerkaktivierung mit klarer Datenmigration und Capabilities; Entscheidung vor MVP festlegen.
- Uninstall löscht Formulare/Einträge nur nach expliziter Admin-Auswahl und dokumentierter Bestätigung.
- PHP-/WordPress-Mindestversion: WP 6.8+ und PHP 8.3+; CI-Matrix muss zusätzlich gegen aktuelle stabile Versionen laufen.

**WordPress.org-Platzierung:** Die Trennung folgt den aktuellen Plugin-Richtlinien: Trialware/Funktionssperren im WordPress.org-Free-Plugin sind nicht zulässig; die Richtlinien empfehlen ausdrücklich ein außerhalb des Verzeichnisses angebotenes Add-on für Premiumcode. Lizenzprüfung, Premium-Updater und Pro-Code bleiben daher ausschließlich in form-captain-pro. Upgrade-Hinweise bleiben kontextbezogen, sparsam und wegklickbar. Quelle: [Detailed Plugin Guidelines (Developer.WordPress.org)](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).

## 8. Lizenzierung und Verkauf

Die Lizenzierung folgt dem bestehenden Captain-Suite-Modell:

1. **Form Captain Free** wird als eigenständiges GPL-Kernplugin auf WordPress.org angeboten.
2. **Form Captain Pro** wird als privates Add-on in hastla007/captain-pro geführt; Slug, Add-on-Ordner, Textdomain und Produktschlüssel: form-captain-pro.
3. Der Lizenzserver ist License Captain (hastla007/wp-license). Dort wird das Produkt mit Laufzeit, Site-Limit, Support-Level und Entitlements konfiguriert.
4. Pro-Add-on vendort den Lizenzclient in eigenem Namespace. Der Add-on-Kernel prüft Kernplugin-Version, verbindet die Lizenzseite unter Form Captain und startet Features nur bei passenden Rechten.
5. Der Updater validiert signierte Metadaten sowie Paketgröße und SHA-256. Lizenzstatus und Offline-Cache folgen dem bestehenden Client-Vertrag.
6. Ablauf/Deaktivierung der Lizenz stoppt nur Pro-Aktionen/Updates gemäß Vertrag. Free-Formulare und bestehende Einträge bleiben erhalten.
7. Die empfohlenen Starttarife stehen im Abschnitt „Vorgeschlagene Form-Captain-Pro-Preise zum Start“; die drei Jahrespläne erhalten Site-Limits 1/5/25. Feature-Entitlements sind über alle Tarife identisch. Zahlungs-, Widerrufs- und Verlängerungstexte werden vor Verkauf für die Zielmärkte geprüft.

## 9. Umsetzungsplan und To-do

### Arbeitsregeln und aktueller Projektstand

Das Repository enthält derzeit nur die kurze README und dieses Konzept; Plugin-Code und eine separate TODO-Datei sind noch nicht vorhanden. Diese bestehende To-do-Sektion ist deshalb die maßgebliche Umsetzungsplanung. Die Aufgaben sind in Abhängigkeitsreihenfolge angeordnet. Ein Task gilt erst als erledigt, wenn sein Ergebnis im Repository liegt und die angegebene Abnahme erfüllt ist.

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
- **Abnahme Phase 2:** Ein Administrator erstellt aus einer Vorlage ein Formular, ordnet Felder, Regeln und Benachrichtigungen zu, speichert es als Entwurf und veröffentlicht eine valide Version.

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
- **Abnahme Phase 3:** Formular funktioniert in Block und Shortcode, bei aktivem Seiten-Cache sowie ohne JavaScript-Grundfunktion; ungültige oder manipulierte Requests werden serverseitig abgewiesen.

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
- **Abnahme Phase 5:** Daten können exportiert, gelöscht und automatisch bereinigt werden; alle Admin-/öffentlichen Datenzugriffe haben explizite Rechte- und Eingabeprüfungen.

### Phase 6 – Free v1.0 Qualität und Veröffentlichung

- [ ] **P6-01 – Automatisierte Testbasis aufbauen.** Unit-Tests für Schema, Validatoren, Bedingungen, Statuswechsel und CSV-Bereinigung; Integrationstests für DB/Migrationen/Submission.
- [ ] **P6-02 – WordPress-Testmatrix einrichten.** Mindest- und aktuelle WordPress-Version sowie unterstützte PHP-Versionen in CI prüfen.
- [ ] **P6-03 – WooCommerce-Kompatibilität verifizieren.** Plugin muss ohne WooCommerce funktionieren; mit WooCommerce dürfen Aktivierung und Formularsubmission keine Checkout-/HPOS-Daten verändern.
- [ ] **P6-04 – Kompatibilität und Konfliktfälle prüfen.** Block-Editor, Classic Editor, Caching, gängige Security-/SMTP-Plugins und mehrere Formulare auf einer Seite testen.
- [ ] **P6-05 – Last-/Missbrauchsgrenzen prüfen.** Große Formulare, viele Entries, CSV-Export, parallele Einsendungen, Spamwellen und fehlende Cron-Ausführung testen.
- [ ] **P6-06 – Accessibility-Abnahme durchführen.** Tastatur, Screenreader, Fokusreihenfolge, Fehleransagen, Kontrast und Mobilansicht an repräsentativen Formularen abnehmen.
- [ ] **P6-07 – Übersetzungsbasis erstellen.** Textdomain `form-captain`, POT-Erzeugung und vollständige deutsche/englische Texte für Admin, Frontend, E-Mails und Fehler etablieren.
- [ ] **P6-08 – Readme und Produktdokumentation fertigstellen.** Installation, Feldtypen, Rechte, externe Datenflüsse, Datenschutz, Mailzustellung, Retention, FAQ und Supportgrenzen dokumentieren.
- [ ] **P6-09 – Lizenz- und Pro-Prompts im Free prüfen.** Upgrade-Hinweise sparsam, kontextbezogen, wegklickbar und ohne künstliche Funktionssperre im Free gestalten.
- [ ] **P6-10 – Build reproduzierbar machen.** Release-ZIP ohne Entwicklungsdateien/Secrets bauen; Versionsnummer, Checksums und Release-Notizen erzeugen.
- [ ] **P6-11 – Upgrade-/Rollback-/Deinstallationspfad abnehmen.** Alte Schema-Versionen migrieren, abgebrochene Migration testen, Downgrade-Verhalten dokumentieren und Nutzdaten standardmäßig behalten.
- [ ] **P6-12 – WordPress.org-Einreichung vorbereiten.** Richtlinien, Assets, Screenshots, Datenschutzhinweise, SVN/Releaseprozess und Supportkanal prüfen.
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
- **Abnahme Phase 8:** Integrationen funktionieren unabhängig voneinander; keine Datenbankkopplung; kein doppelter Side-Effect; Fehler und Löschfolgen sind im Admin nachvollziehbar.

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
- **Abnahme Phase 9:** Releases sind reproduzierbar und betreibbar; spätere Erweiterungen haben eine begründete Nachfrage und klare Daten-/Supportverantwortung.

### Gesamt-Abnahmekriterien für „Form Captain fertig“

- [ ] Free v1.0 kann installiert, eingerichtet und aktualisiert werden; Formulare lassen sich bauen, veröffentlichen, absenden, speichern, verwalten und exportieren.
- [ ] Datenschutz-, Berechtigungs-, Spam-, E-Mail-, CSV-, Migrations- und Löschpfade sind abgenommen.
- [ ] Free-Release ist WordPress.org-fähig und besitzt vollständige Dokumentation sowie reproduzierbaren Build.
- [ ] Pro v1 ist ein separates, korrekt lizenziertes Add-on; Pro-Ablauf oder Deaktivierung löscht keine Free-Formulare oder Entries.
- [ ] Suite-Adapter verwenden ausschließlich freigegebene Verträge, sind idempotent und erfüllen die Datenbesitz-/Einladungsgrenzen dieses Konzepts.
- [ ] Offene spätere Erweiterungen aus Phase 9 sind entweder mit begründetem Releaseplan versehen oder bewusst aus dem Produktumfang ausgeschlossen.

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
## Quellen und Referenzen

- [Review Captain – Produktkonzept und Suite-Vertrag](https://github.com/hastla007/wp-reviewmaster/blob/main/konzept.md)


## Suite-Abgleich mit den gebauten Plugins (15.11.2026)

| Verbindung | Vorhandene Grundlage | Noch zu bauen / verbindliche Grenze |
|---|---|---|
| Pass Captain | Öffentliche Services und versionierte Voucher-/Check-in-Provider sind im Konzept und in der Hook-Dokumentation beschrieben. | Formularauswahl/Einbettung im Event-Admin und ein eigener Form-Captain-Adapter fehlen. Ein Formularentry darf keine Buchung oder Ticket-Ausstellung auslösen. |
| Voucher Captain | Versionierte PHP-API, externe Einlösung und Connector-Verträge sind vorhanden. | Anfrage-/Personalisierungsadapter fehlt. Erst nach ausdrücklicher Prüfung über Voucher-Service ausstellen; keine direkte Tabellenmanipulation. |
| Affiliate Captain | Öffentlicher Verkaufs-/Partnervertrag v1.3 mit Status `pending` ist vorhanden. | Eine Formular-zu-Partner-Anlage ist nicht im Vertrag. Vor einer Erweiterung den bestehenden Bewerbungsablauf bevorzugen; keine stillschweigende Freigabe oder Provisionsanlage. |
| Review Captain | Konzept definiert optionales Formular-Action-Mapping und lokale Review-Übergabe. | Noch nicht implementiert. Benötigt Review Captain Pro, explizite Feldzuordnung, idempotente Quelle, pending/unverified und Löschweitergabe. Keine Einladungs-E-Mail aus Form-Einsendungen. |
| License Captain / Captain Pro | Bestehendes Add-on-/License-Client-Modell ist etabliert. | Form Captain Pro ist geplant; Produktschlüssel, Entitlements, Admin-Einbindung und Lizenzkatalog müssen vor Premium-Release mit den bestehenden Add-ons abgeglichen werden. |

Für die Implementierung sind die genannten Verträge versionsgenau zu prüfen. Bestehende APIs/Hooks werden nur gemäß ihrer dokumentierten Semantik verwendet; ein im Konzept benannter zukünftiger Hook ist bis zur Implementierung kein vorhandener Vertrag.
