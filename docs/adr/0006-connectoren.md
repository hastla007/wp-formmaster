# ADR 0006 – Connector-Architektur (P8-23)

**Entscheidung**

- Projektspezifische Logik lebt in separaten WordPress-Plugins (Connector-Plugins). Form Captain Core enthält nur Registry, Vertrag, Opt-in, Feld-Mapping, Outbox-Ausführung und Diagnose.
- Form Captain bleibt Eigentümer von Formular, Schema, Entry, Status und Aufbewahrung. Der Connector besitzt Zielsystem-Zugangsdaten, Remote-IDs und den Zustand der externen Leistung.
- Registrierung über den Filter `form_captain_connectors_v1` (Objekte, die `FormCaptain\Integrations\ConnectorInterface` implementieren) und `form_captain_register_action_v1` für Formularaktionen.
- Generische Webhooks (Pro) sind ein separater Mechanismus und nutzen dieselbe Outbox, aber keinen Connector-Vertrag.
- Registry, Vertrag und einfache Aktionen sind Teil des Free-Kerns (P8-38); Pro-Entitlements gelten nur für Pro-Funktionen.
- Referenz-Connector: `connectors/form-captain-reference-connector/` (nicht Teil des Release-ZIPs).

Details: [`docs/spec/api-v1.md`](../spec/api-v1.md), Entwicklerleitfaden [`docs/CONNECTORS.md`](../CONNECTORS.md).
