# E-Mail-Zustellung – Abnahme (P9-11)

P9-11 verlangt die Abnahme auf typischen Hostern. Dieser Teil ist lokal nicht machbar und bleibt offen. Lokal abgenommen ist das Verhalten von Form Captain selbst: Absender- und Reply-To-Regeln, Fehlerstatus, Wiederholung, kein Doppelversand.

## 1. Lokale Abnahme vom 16.11.2026

**Umgebung:** WordPress 6.8.3, PHP 8.3.6, MariaDB 10.11, Form Captain 1.0.0-dev, WP-Cron deaktiviert, Queue über `wp form-captain jobs run`. Statt eines Mailservers fängt ein Test-MU-Plugin `wp_mail()` über `pre_wp_mail` ab. Es nimmt entweder an und protokolliert Empfänger, Betreff und Header, oder es simuliert einen SMTP-Ausfall.

| # | Schritt | Erwartung | Ergebnis |
|---|---|---|---|
| 1 | Einsendung bei „SMTP-Ausfall“, Queue-Lauf | Eintrag gespeichert; Auftrag `mail.admin` `failed`, Fehlercode `mail_failed`, Versuch 1/8, nächster Versuch geplant | **erfüllt**: Eintrag 7 gespeichert, Auftrag `failed`, Versuch 1/8, nächster Versuch nach ca. 2 Minuten |
| 2 | Systemstatus | Mailfehler sichtbar, ohne Inhalte | **erfüllt**: `failed_24h: mail_failed=1`, Problem „mail“, `recent_errors` nur mit Code, Korrelations-ID und IDs |
| 3 | Mailserver „repariert“, `wp form-captain jobs retry 7`, Queue-Lauf | genau eine Zustellung | **erfüllt**: Auftrag `succeeded`, eine Mail protokolliert |
| 4 | Queue erneut laufen lassen | kein Doppelversand | **erfüllt**: 0 Aufträge verarbeitet |
| 5 | Header der Admin-Mail | Reply-To = geprüfte Besucheradresse; HTML mit Textalternative | **erfüllt**: `Reply-To: visitor@example.com`, `Content-Type: text/html` (Textteil über `phpmailer_init`) |
| 6 | Absender | From aus Formular- oder globaler Einstellung; ungültige Adresse oder Header-Injection → Standardabsender; Filter wirken nur auf Form-Captain-Mails | **erfüllt**, automatisiert: `MailJobHandlerTest::test_admin_mail_contents`, `::test_sender_filters_do_not_leak_and_invalid_sender_falls_back` |
| 7 | Wiederholungen bis Dead-letter, manuelles Wiederholen | 8 Versuche, dann `dead` mit Ereignis; manuelles Wiederholen stellt genau einmal zu | **erfüllt**, automatisiert: `MailJobHandlerTest::test_wp_mail_failure_retries_then_dead_letter_with_event` |
| 8 | CRLF in Reply-To und Betreff | abgewiesen, kein zusätzlicher Header | **erfüllt**, automatisiert: `MailJobHandlerTest::test_header_injection_in_reply_to_and_subject_is_rejected` |

**Hinweis zum Absender:** Form Captain setzt From über `wp_mail_from` und `wp_mail_from_name` mit Priorität 999, und zwar nur für die Dauer des eigenen Versands. SMTP-Plugins, die den Absender in `phpmailer_init` erzwingen („Force From“), haben Vorrang. Das ist gewollt, denn der Absender muss zur authentifizierten Domain passen (SPF/DKIM/DMARC). Der Systemstatus zeigt `domain_match: yes/no`.

## 2. Hoster-Matrix (offen)

Ab Release-Kandidat 1.0.0 auf mindestens fünf repräsentativen Umgebungen durchführen. Je Zeile gilt: Testeinsendung, Zustellung an ein Postfach mit Prüfung von SPF/DKIM/DMARC (Header „Authentication-Results“), Spam-Einstufung, Fehlerfall (falsches SMTP-Passwort) mit Wiederholung nach der Korrektur.

| Umgebung | Versand | Absenderdomain = Site | SPF | DKIM | DMARC | Zustellung | Fehler sichtbar / Wiederholung | Datum, Prüfer |
|---|---|---|---|---|---|---|---|---|
| Shared Hosting A, PHP `mail()` | wp_mail ohne SMTP | | | | | | | |
| Shared Hosting B, SMTP-Plugin mit Hoster-Postfach | SMTP | | | | | | | |
| Managed WordPress Hosting (Versand blockiert, Pflicht-SMTP) | SMTP / API-Plugin | | | | | | | |
| VPS mit Postfix | sendmail | | | | | | | |
| Transaktionsmail-Dienst über Plugin (API) | API | | | | | | | |

**Erwartung für jede Zeile:** Der Eintrag wird immer gespeichert, auch bei Fehlern. Fehler erscheinen am Eintrag und im Systemstatus. Nach der Korrektur stellt eine Wiederholung genau einmal zu. Ohne passenden Absender oder bei fehlendem SPF/DKIM stehen die Hinweise unter *Einstellungen → E-Mail* und im Systemstatus.
