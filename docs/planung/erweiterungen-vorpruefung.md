# Vorprüfung späterer Erweiterungen (P9-09)

**Status:** Prüfraster. Zahlungen, CRM-Connectoren, Analytics und White-Label werden **nicht** vorgezogen (konzept.md 4, „Nach v1.0“). Für jeden Bereich wird vor Umsetzungsbeginn eine eigene Vorprüfung nach diesem Raster ausgefüllt und als `docs/planung/vorpruefung-{bereich}.md` abgelegt. Erst danach folgt eine eigene Releaseentscheidung.
**Grundsatz:** Externe Dienste nur über dokumentierte APIs; Datenschutz, Nutzungsbedingungen, Gebühren und Limits sind vor jeder Integration zu prüfen (konzept.md Vorbemerkung). Anbieter werden in der Vorprüfung neutral als „Anbieter 1/2/…“ geführt, bis eine Auswahl entschieden ist; offizielle API-Dokumentation darf verlinkt werden.

## 1. Allgemeines Prüfraster (für jeden Bereich)

| Nr. | Prüfpunkt | Fragen | Nachweis |
|---|---|---|---|
| R-1 | **Nachfrage** | Wie viele Anfragen/Supportfälle/Kunden fordern es? Welche Zielgruppe (konzept.md 1.1)? | Zähler aus Support/P9-04, Kundeninterviews |
| R-2 | **API / Provider** | Offizielle, versionierte API? Authentifizierung (OAuth/API-Key), Rate-Limits, Sandbox, Webhook-Signatur, Deprecation-Policy, SDK nötig oder HTTP direkt? Regionen/Datenstandort? | Link API-Doku, Versionsstand, Testkonto |
| R-3 | **Architektur** | Passt es in Outbox/Connector-Vertrag (ADR 0004, 0006)? Idempotenzschlüssel, Retry, Dead-Letter, Löschweitergabe? Datenbesitz eindeutig (Form Captain = Formular/Entry)? | Skizze Datenfluss, Trust Boundary im Bedrohungsmodell |
| R-4 | **Refund / Rückabwicklung** | Was passiert bei Storno, Rückerstattung, Widerruf, Löschung im Zielsystem? Teilrückabwicklung? Doppelte Ausführung? | Zustandsdiagramm |
| R-5 | **Recht** | Verträge/Nutzungsbedingungen des Anbieters, Lizenz der SDKs (GPL-kompatibel?), Markenrichtlinien, regulatorische Pflichten (z. B. Zahlungsdienste, Verbraucherschutz) | Prüfvermerk Recht (P9-10) |
| R-6 | **Datenschutz** | Welche personenbezogenen Daten verlassen die Site? Rechtsgrundlage, Einwilligungstext, Auftragsverarbeitung, Drittlandtransfer, Retention beim Anbieter, Löschweg, Eintrag im readme „External services“ | Datenflusstabelle nach `spec/datenschutz.md` |
| R-7 | **Sicherheit** | Secrets-Speicherung (P5-16), SSRF/Redirect (T-WH), Webhook-Verifikation, Rechte (`manage_integrations`), Logging ohne Werte | Ergänzung `bedrohungsmodell.md` |
| R-8 | **Produktgrenze** | Free oder Pro? WordPress.org-Richtlinien (keine Trialware im Free; externe Dienste opt-in) | Verweis `WORDPRESS-ORG.md` 2 |
| R-9 | **Support** | Erwartete Tickets/Monat, nötiges Fachwissen, Abhängigkeit von Anbieter-Support, Fehlerdiagnose ohne PII möglich (`BETRIEB.md` 2.2)? | Schätzung, Supportleitfaden-Entwurf |
| R-10 | **Betriebskosten** | Entwicklung (PT), Wartung je API-Version/Jahr, Testkonten/Gebühren, Monitoring, Dokumentation, Übersetzung | Kostentabelle (Abschnitt 4) |
| R-11 | **Test** | Sandbox vorhanden? Automatisierbare Vertragstests? E2E ohne echte Gelder/Daten? | Testplan |
| R-12 | **Ausstieg** | Was passiert bei Anbieter-Abschaltung, Lizenzende Pro, Deaktivierung? Daten bleiben in Form Captain? | Abschaltplan |

## 2. Bereichsspezifische Zusatzfragen

### 2.1 Zahlungen

- Nur über offizielle Provider-APIs mit gehosteten Zahlungsseiten bzw. Tokenisierung; **keine Kartendaten** auf dem WordPress-Server (PCI-Umfang so klein wie möglich, Selbstauskunft bestimmen).
- Zahlungsstatus ausschließlich aus signierten Provider-Webhooks bzw. serverseitiger Abfrage, nie aus Browser-Redirect-Parametern.
- Idempotenz: ein Entry erzeugt höchstens eine Zahlungsabsicht; Webhook-Wiederholungen und doppelte Klicks sind abgesichert.
- Zustände: `pending`, `authorized`, `paid`, `failed`, `refunded`, `partially_refunded`, `disputed`; Wechsel nur durch Provider-Ereignis oder berechtigten Admin.
- Refund-Prozess: aus Form Captain auslösbar oder nur im Provider-Dashboard? Teilerstattung? Wirkung auf Entry-Status und Mails.
- Steuern, Rechnungen, Belege: wer erzeugt sie (Provider/Shop/extern)? Form Captain ist kein Buchhaltungssystem.
- Abgrenzung zu WooCommerce: Für Warenverkauf bleibt WooCommerce zuständig (Käuferfragebogen-Modul greift nicht in Zahlung ein); Form-Captain-Zahlungen nur für Gebühren/Spenden/Anmeldebeträge.
- Rechtlich: Preisangaben, Widerrufsbelehrung, Button-Beschriftung, Spendenquittungen je Zielmarkt.
- Voraussetzung laut konzept.md: stabile Webhook-/Idempotenzbasis (Pro v1) und eigene Provider-/Refund-Spezifikation.

### 2.2 CRM-/Newsletter-Connectoren

- Feldzuordnung, Pflichtfelder des Zielsystems, Dublettenlogik (Upsert-Schlüssel), Double-Opt-in-Pflicht bei Newsletter.
- Löschweitergabe: wird bei Entry-Löschung/Erasure im CRM gelöscht? Nur wenn konfiguriert (konzept.md 5: keine ungefragte Löschung im Fremdsystem).
- Token-Lebensdauer und Erneuerung (OAuth-Refresh im Cron), Verhalten bei abgelaufenem Zugang.
- API-Limits je Konto; Batch vs. Einzelaufruf; Kosten pro Kontakt beim Anbieter.
- Alternativ: generischer Webhook (Pro v1) ausreichend? Eigener Connector nur, wenn Webhook nachweislich nicht genügt.

### 2.3 Analytics

- Nur aggregierte Formularkennzahlen (Aufrufe, Starts, Abschlüsse, Abbruchfeld) – **keine** Submission-Inhalte, keine Profile, keine Fingerprints.
- Lokal in WordPress oder an externen Dienst? Extern nur opt-in mit Einwilligung des Besuchers, falls Endgerätezugriff/Tracking vorliegt.
- Cookies/Local Storage: Einwilligungspflicht prüfen; Default aus.
- Datenvolumen und Aufbewahrung der Zähler; Auswirkung auf Seitencaches und Performance-Budget.
- Abgrenzung zu P9-04 (Nutzungsfeedback des Betreibers an uns): Analytics ist eine Betreiberfunktion, keine Telemetrie an den Hersteller.

### 2.4 White-Label

- Umfang: Menüname, Logo, Entfernen von Herstellerhinweisen im Admin, eigener Supportlink.
- Grenzen: Lizenz-/Copyright-Hinweise (GPL), Plugin-Header und readme bleiben; Sicherheits- und Update-Hinweise dürfen nicht unterdrückt werden.
- Supportfolgen: Endkunden kennen das Produkt nicht – Diagnosebericht muss trotzdem Version und Herkunft enthalten.
- Tarif: nur Agency? Auswirkung auf Preisgefüge (konzept.md: keine zusätzliche Tarifstufe vor 6-Monats-Bewertung).
- WordPress.org: White-Label-Funktion nur im Pro-Add-on.

## 3. Go/No-Go-Kriterien

**Go**, wenn **alle** Muss-Kriterien erfüllt sind:

| Nr. | Muss-Kriterium |
|---|---|
| G-1 | Nachfrage belegt: ≥ `[SCHWELLE]` unabhängige Anfragen in 6 Monaten oder zahlender Pilotkunde |
| G-2 | Offizielle, dokumentierte API mit Sandbox und Deprecation-Policy |
| G-3 | Datenfluss, Einwilligung und Auftragsverarbeitung rechtlich freigegeben (P9-10) |
| G-4 | Umsetzung über bestehende Outbox/Connector-Verträge ohne Bruch des öffentlichen Vertrags v1 |
| G-5 | Refund-/Rückabwicklung und Löschweitergabe vollständig spezifiziert |
| G-6 | Supportaufwand ≤ `[SCHWELLE]` h/Monat geschätzt und Diagnose ohne PII möglich |
| G-7 | Wartungskosten pro Jahr durch erwarteten Mehrumsatz/Nachfrage gedeckt |
| G-8 | Free-Kern bleibt ohne die Erweiterung vollständig nutzbar; keine neue Pflichtabhängigkeit |

**No-Go**, wenn **eines** zutrifft:

| Nr. | Ausschlusskriterium |
|---|---|
| N-1 | Nur inoffizielle/undokumentierte API oder Scraping |
| N-2 | Speicherung von Kartendaten oder vergleichbar sensiblen Zahlungsdaten in WordPress nötig |
| N-3 | Personenbezogene Daten müssten ohne Einwilligung bzw. Rechtsgrundlage übertragen werden |
| N-4 | Anbieterbedingungen verbieten Nutzung in einem GPL-Plugin bzw. Weitergabe von SDK-Code |
| N-5 | Erweiterung erfordert Eingriff in WooCommerce-Checkout/Zahlung (widerspricht konzept.md 5) |
| N-6 | Kein Sandbox-/Testweg ohne echte Zahlungen bzw. echte Kundendaten |

**Ergebnis der Vorprüfung:** Go / No-Go / Zurückgestellt (mit Wiedervorlagedatum) – Entscheidung, Datum, Entscheider, Begründung.

## 4. Kostentabelle (Vorlage)

| Posten | Einmalig (PT) | Laufend (PT/Jahr) | Fremdkosten/Jahr | Annahme |
|---|---:|---:|---:|---|
| Spezifikation, Bedrohungsmodell, Rechtsprüfung | | | | |
| Implementierung | | | | |
| Tests (Unit, Vertrag, E2E, Sandbox) | | | | |
| Dokumentation, Übersetzung | | | | |
| Pflege API-Änderungen | | | | |
| Support | | | | |
| Testkonten, Gebühren, Monitoring | | | | |
| **Summe** | | | | |

## 5. Reihenfolge und Status

| Bereich | Voraussetzung | Vorprüfung | Releaseentscheidung |
|---|---|---|---|
| CRM-Connectoren | Pro v1 Webhooks stabil | offen | offen |
| Zahlungen | Pro v1 Webhooks + Idempotenz stabil, Provider-/Refund-Spezifikation | offen | offen |
| Analytics | Nutzungsdaten nach Free-Launch (P9-04) | offen | offen |
| White-Label | Nachfrage Agency-Tarif nach 6 Monaten | offen | offen |
