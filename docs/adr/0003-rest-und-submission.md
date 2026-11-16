# ADR 0003 – REST-Routen und öffentliche Submission (P0-16, P3-06, P3-11, P3-13, P3-14)

**Routen** (Namespace `form-captain/v1`)

| Methode | Route | Zugriff |
|---|---|---|
| GET | `/forms/{public_id}/token` | öffentlich, `Cache-Control: no-store` |
| POST | `/forms/{public_id}/submissions` | öffentlich, Submission-Token |
| GET/POST | `/admin/forms` | `manage_forms` + `X-WP-Nonce` |
| GET/PUT/DELETE | `/admin/forms/{id}` | `manage_forms` |
| POST | `/admin/forms/{id}/publish` | `manage_forms` |
| GET | `/admin/forms/{id}/preview` | `manage_forms` |

No-JS-Fallback: `POST /wp-admin/admin-post.php?action=form_captain_submit` (auch `nopriv`), gleiche Logik, Antwort per Redirect mit `fc_result`-Parameter (nur Status-Code, keine Daten) bzw. Neuanzeige mit Fehlern über kurzlebigen Transient-Schlüssel.

**Token statt Nonce:** Seitencaches würden WordPress-Nonces an alle Besucher verteilen. Daher enthält das gerenderte HTML ein **statisches** Hidden-Field `_fc_form` (public_id) und ein vom Renderer erzeugtes Token `_fc_token`; ist die Seite gecacht, holt das Frontend-JS beim ersten Fokus ein frisches Token über `GET …/token` (`no-store`). Token = `base64url(form_public_id | issued_at | random16) . HMAC-SHA256(secret)`, 2 h gültig. Mindestzeit wird über `issued_at` gemessen. Ohne JS und mit gecachter Seite älter als 2 h: Fehlermeldung „Bitte Seite neu laden“ (Eingaben bleiben erhalten).

**Idempotenz:** `request_key = sha256(form_id | random16 aus Token)`; derselbe Token kann genau einen Entry erzeugen. Doppelter POST liefert das ursprüngliche Ergebnis (`created`, gleiche Entry-ID) ohne neue Mails.

**Weitere Regeln:** Nur `POST` mit `application/json` oder `application/x-www-form-urlencoded`; Body-Limit prüfen, bevor geparst wird; keine CORS-Header (same-origin); Antwortschema `{success, code, message, errors?, redirect?}`; HTTP-Codes 201/400/403/404/409/413/429/503. Eingeloggte Nutzer: REST-Cookie-Auth wird für die Submission-Route ignoriert (keine `X-WP-Nonce` nötig), `user_id` wird nur gesetzt, wenn ein gültiger Nonce mitkommt.
