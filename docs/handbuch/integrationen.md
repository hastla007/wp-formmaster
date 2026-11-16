# Integrationen

*Form Captain → Integrationen* (Recht „Integrationen verwalten“) zeigt alle installierten Connectoren. Connectoren sind eigene WordPress-Plugins, die ausgewählte Formulardaten an ein anderes System übergeben.

## Connector aktivieren

1. Connector-Plugin installieren und aktivieren. Er erscheint als **Erkannt – nicht aktiviert**.
2. Karte prüfen: Plugin-Version, Vertragsversion, Anforderungen, Fähigkeiten.
3. **Aktivieren**. Erst jetzt können Aktionen laufen.
4. Im Formular-Editor die Aktion einschalten und Felder zuordnen. Nur zugeordnete Felder werden übertragen; als sensibel markierte Felder sind gesperrt, sofern die Aktion sie nicht ausdrücklich erlaubt.

Zustände: **Aktiv**, **Deaktiviert**, **Erkannt**, **Inkompatibel** (Vertragsversion passt nicht – Plugin oder Form Captain aktualisieren), **Plugin fehlt** (Plugin wurde entfernt/deaktiviert).

## Testlauf

„Test ausführen“ ruft die Aktion mit Beispielwerten im Testmodus auf. Es entsteht kein Eintrag und kein Job; der Connector darf im Testmodus nichts Echtes anlegen. Bis zu 5 Tests je 10 Minuten.

## Jobs, Fehler und Wiederholung

Übergaben laufen im Hintergrund nach dem Speichern der Einsendung. Fällt das Zielsystem aus, bleibt der Eintrag unverändert gespeichert und die Admin-Mail geht trotzdem raus.

| Status | Bedeutung |
|---|---|
| Wartet | eingeplant |
| Pausiert (Connector deaktiviert) | läuft nicht, solange der Connector deaktiviert ist; verfällt nicht und zählt keine Versuche; wird beim Aktivieren automatisch fortgesetzt |
| Pausiert (Connector fehlt) | wie oben, aber das Connector-Plugin ist nicht mehr installiert/aktiv; **Abbrechen** möglich |
| Fehlgeschlagen – wird wiederholt | temporärer Fehler, automatische Wiederholung mit wachsendem Abstand |
| Dead-letter | nach 8 Versuchen oder endgültigem Fehler; **Wiederholen** startet neu |
| Zugestellt | erfolgreich, ggf. mit Remote-ID |
| Abgebrochen | manuell abgebrochen, Eintrag gelöscht/anonymisiert oder Einwilligung widerrufen |

Zu jedem Fehlercode steht der nächste Schritt in der Liste (z. B. „Connector aktivieren“, „Zuordnung ergänzen“). Wiederholungen erzeugen beim Zielsystem keine Duplikate (Idempotency-Key).

## Deaktivieren und Entfernen

**Deaktivieren** stoppt alle Übergaben dieses Connectors. Seine offenen Jobs werden **pausiert**: Sie bleiben in der Liste sichtbar („Pausiert (Connector deaktiviert)“, Filter „Pausiert“), laufen nicht, verfallen nicht und verbrauchen keine Versuche. Andere Connectoren, eingebaute Aktionen und E-Mails laufen normal weiter. Im Zielsystem wird nichts gelöscht.

**Erneut aktivieren** setzt die pausierten Jobs automatisch fort; sie laufen mit dem nächsten Hintergrundlauf, der Versuchszähler bleibt, wie er war.

**Plugin entfernt:** Pausierte Jobs bleiben pausiert und werden als „Pausiert (Connector fehlt)“ angezeigt; oben auf der Seite erscheint ein Hinweis. Installieren und aktivieren Sie das Plugin wieder, um sie fortzusetzen, oder brechen Sie sie in der Jobliste mit **Abbrechen** ab. Wird ein Eintrag gelöscht oder anonymisiert, werden auch seine pausierten Jobs abgebrochen.

Systemstatus und Website-Zustand zählen pausierte Jobs getrennt; sie gelten nicht als Rückstau. Das Eintragsdetail zeigt unter „Versandstatus“ ebenfalls „Pausiert (Connector deaktiviert)“.

## Löschung und Datenschutz

Wird ein Eintrag gelöscht oder anonymisiert, werden offene Übergaben abgebrochen. Daten im Zielsystem löscht Form Captain nicht selbst; ob der Connector sie bereinigt, steht in dessen Dokumentation. Nennen Sie aktive Integrationen in Ihrer Datenschutzerklärung.

## Pro

Registry, Opt-in und einfache Connector-Aktionen sind Teil des kostenlosen Kerns. Ein Lizenzstatus erscheint nur, wenn Form Captain Pro installiert ist.

Für Entwickler: [`docs/CONNECTORS.md`](../CONNECTORS.md).
