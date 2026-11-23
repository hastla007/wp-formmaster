# Eingebauter Spamschutz: Proof of Work (`pow`)

Stand 23.11.2026 · Aufgabe SET-FC-05 (a) · Entscheidungen E1 (Captcha im Kern, kostenlos) und S7 (Review Captain übernimmt dasselbe Verfahren).

## Zweck und Eigenschaften

- Selbst gehostet: keine Anfrage an Dritte, keine Cookies, keine personenbezogenen Daten (keine IP, kein Fingerabdruck).
- Barrierefrei: kein Rätsel, kein Bild, nichts zu klicken. Der Browser rechnet im Hintergrund (Ø ≈ 25 000 SHA-256, unter einer Sekunde auf einem Mittelklasse-Telefon).
- Ohne JavaScript: Fallback auf die bestehenden Prüfungen (Honeypot, Mindestzeit, Sperrliste, Sendegrenzen). Formulare mit Spamschutz „strenger“ verlangen die Lösung auch ohne JavaScript (dann ist das Formular ohne JS nicht nutzbar – bewusste Wahl des Betreibers).
- Prinzip wie ALTCHA / Friendly Captcha. Es macht Massen-Spam teurer, verhindert ihn aber nicht allein.

## Dateien (zum Übernehmen mit eigenem Präfix)

| Datei | Inhalt | Abhängigkeiten |
|---|---|---|
| `src/Spam/ProofOfWork/ProofOfWork.php` | Aufgabe erzeugen, prüfen, Einmalverwendung, Schwierigkeit | keine (nur PHP; Schlüssel, Uhr und Einmal-Speicher werden übergeben) |
| `assets/src/proof-of-work.js` | Löser im Browser, eigene SHA-256 (läuft auch ohne HTTPS), globale Variable `window.formCaptainPow` | keine |
| `src/Security/PowCaptcha.php` | WordPress-Anbindung von Form Captain: Schlüssel, Einmal-Speicher, Anbieterwahl je Formular | Form Captain |

Review Captain kopiert die ersten beiden Dateien, ändert nur Namespace und den Namen der globalen JS-Variable und schreibt eine eigene Anbindung (entsprechend `PowCaptcha.php`).

## Datenformat

Aufgabe (Server → Browser, nur über eine nicht zwischengespeicherte Antwort; in Form Captain im Feld `pow` der Token-Route `GET /form-captain/v1/forms/{id}/token`):

```json
{
  "algorithm": "SHA-256",
  "challenge": "<64 hex>",
  "maxnumber": 50000,
  "salt": "<24 hex Zufall>.<Ablauf Unix-Zeit>.<16 hex Bereichskennung>",
  "signature": "<64 hex>",
  "expires": 1763900000
}
```

- `challenge` = SHA-256( `salt` . n ) für eine geheime Zufallszahl 0 ≤ n ≤ `maxnumber` (n als Dezimalzahl ohne führende Nullen).
- `signature` = HMAC-SHA-256( `challenge`, Schlüssel ).
- Bereichskennung = erste 16 hex von HMAC-SHA-256( `"scope|" . Bereich`, Schlüssel ). Bereich in Form Captain: die öffentliche Formular-ID. Eine Lösung gilt nur für dieses Formular.

Lösung (Browser → Server, Formularfeld `_fc_pow`): Base64 von JSON

```json
{ "algorithm": "SHA-256", "challenge": "…", "number": 31337, "salt": "…", "signature": "…" }
```

## Prüfregeln (Reihenfolge)

1. Fehlt die Lösung → `missing`.
2. Kein gültiges Base64-JSON, falscher Algorithmus, Felder nicht im Format (hex, `salt`-Muster, `number` ganzzahlig 0…1 000 000) oder Nutzlast > 1024 Byte → `malformed`.
3. `signature` ≠ HMAC(`challenge`) (Vergleich in konstanter Zeit) → `bad_signature`.
4. Ablaufzeit aus `salt` < jetzt → `expired`.
5. Bereichskennung passt nicht zum Formular → `wrong_scope`.
6. SHA-256(`salt` . `number`) ≠ `challenge` → `wrong_solution`.
7. Einmalverwendung: `challenge` wird im Einmal-Speicher bis Ablauf + 60 s vermerkt; schon vorhanden → `replayed`.
8. Sonst `ok`.

Der Einmal-Speicher wird erst bei Schritt 7 berührt, also nur für korrekt signierte, richtige Lösungen.

## Parameter

| Parameter | Vorgabe | Wo |
|---|---|---|
| Schwierigkeit `maxnumber` | 50 000 (1 000 … 1 000 000) | technischer Wert; Filter `form_captain_pow_max_number_v1` |
| Gültigkeit einer Aufgabe | mindestens 1 800 s (`ProofOfWork::DEFAULT_TTL`), in Form Captain so lange wie das Sendetoken (`spam.token_minutes`, Vorgabe 2 h) | Aufgabe und Token werden zusammen geholt; `frontend.js` behält das Token bis 115 min. Eine kürzere Gültigkeit hätte Einsendungen langsamer Besucher still in den Spam gelegt (SET-FC-P4-01). |
| Schlüssel | `Secret::key( 'form-captain/pow/v1' )` | aus dem Website-Geheimnis abgeleitet, nie ausgegeben |
| Einmal-Speicher | Zeile in `fc_rate_limits` (`INSERT IGNORE`, Bucket = SHA-256 der Aufgabe) | wird mit den übrigen Sendegrenzen aufgeräumt |

## Einbindung in Form Captain

- Auswahl: Einstellung `spam.captcha_provider` = `pow` (Vorgabe bleibt `none`; keine Drittland-Bestätigung nötig). Externe Anbieter melden sich über `form_captain_captcha_providers_v1` an (Auswahl in der Einstellung) und prüfen die Einsendung über `form_captain_spam_checks_v1`, wenn `$context['captcha']` ihr Schlüssel ist (siehe `docs/spec/api-v1.md`).
- Je Formular `spam.mode`: `global` (Einstellung gilt), `strict` (Captcha immer, `pow` falls global keiner gewählt, kein Fallback ohne JS), `off` (kein Captcha für dieses Formular; übrige Prüfungen bleiben).
- Spam-Gründe der Einsendung: `pow_missing`, `pow_invalid`, `pow_replayed`. Wie bei allen Spam-Gründen sieht der Besucher eine normale Erfolgsmeldung, die Einsendung landet im Spam.
- Die REST-Route des Formular-Skripts erwartet eine Lösung; die No-JS-Route (`admin-post.php`) nur bei `strict`.
