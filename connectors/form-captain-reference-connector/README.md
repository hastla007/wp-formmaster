# Form Captain Reference Connector (Beispiel)

> **Nur ein Beispiel.** Dieses Plugin zeigt den Connector-Vertrag v1 von Form Captain. Es sendet keine Daten nach außen, sondern simuliert einen lokalen Ticketdienst in der Option `fcrc_tickets`. Nicht produktiv einsetzen und nicht Teil des Form-Captain-Release-ZIPs.

## Was es zeigt

- Registrierung über `form_captain_connectors_v1` erst nach `form_captain_loaded_v1` (auch bei spätem Laden).
- `FormCaptain\Integrations\ConnectorInterface` (Key `reference`, Contract `1.0`).
- Aktion `reference.create_ticket` mit Setting `queue` und Mapping `email` (Pflicht), `subject`, `message`.
- Idempotenz: Tickets werden je `idempotency_key` gespeichert – eine Wiederholung liefert dieselbe Ticket-ID.
- Testmodus (`test_mode = true`) ohne Seiteneffekt.
- Reaktion auf `form_captain_entry_deleted_v1`: entfernt lokal gespeicherte Personendaten, keine blinde Remote-Löschung.
- Reaktion auf `form_captain_consent_withdrawn_v1`: markiert das Ticket als `consent_withdrawn`. Personendaten werden nur entfernt, wenn die Option `fcrc_consent_withdrawal_mode` auf `delete` steht (`wp option update fcrc_consent_withdrawal_mode delete`); keine Remote-Löschung ohne Konfiguration.

## Installation

Ordner nach `wp-content/plugins/` kopieren, aktivieren, dann unter *Form Captain → Integrationen* den Connector aktivieren und im Formular die Aktion „Create example ticket“ zuordnen.

## Fehler-Modus für Tests

Option `fcrc_failure_mode`:

| Wert | Verhalten |
|---|---|
| `''` | normal |
| `fail_once` | erster Versuch je Key → `retry` `remote_unavailable` |
| `timeout_after_success` | Ticket wird gespeichert, dann Exception (verlorene Antwort); nächster Versuch liefert dasselbe Ticket |
| `permanent` | `fail` `remote_rejected` → Dead-letter |

```bash
wp option update fcrc_failure_mode timeout_after_success
```

Automatisierte Zwei-Plugin-Tests: `tests/integration/Contract/ReferenceConnectorTest.php` im Form-Captain-Repository. Entwicklerleitfaden: `docs/CONNECTORS.md`.
