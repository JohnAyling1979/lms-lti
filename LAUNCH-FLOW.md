# Launch flow — click to rendered UI

What happens when a user clicks the **Mock Tool Launch** activity in Moodle, traced
through this lab's actual services and URLs. It's an LTI 1.3 (OpenID Connect) launch
followed by the tool's own session hand-off.

## Registered config (grounds the URLs)

| | Value |
|---|---|
| Issuer (`iss`) | `https://localhost` |
| Client ID | `xHgrxkVTGHvPx25` |
| Deployment ID | `1` |
| Moodle auth endpoint | `https://localhost/mod/lti/auth.php` |
| Moodle keyset — browser / **server** | `https://localhost/mod/lti/certs.php` / **`http://moodle:8080/mod/lti/certs.php`** |
| Tool login init | `https://auth.lvh.me/lti/login` |
| Tool redirect / launch | `https://auth.lvh.me/lti/launch` |
| App UI | `https://app.lvh.me/` |
| Session API | `https://api.lvh.me/api/me` |

## Sequence

```mermaid
sequenceDiagram
    autonumber
    actor U as User
    participant B as Browser (new window)
    participant M as Moodle (localhost)
    participant A as auth.lvh.me
    participant R as Redis
    participant P as app.lvh.me
    participant I as api.lvh.me

    U->>M: Click "Mock Tool Launch"
    M-->>B: Open new window, begin OIDC login

    rect rgb(235, 244, 255)
    note right of B: 1 — OIDC login initiation
    B->>A: GET /lti/login (iss, login_hint,<br/>client_id=xHgrxkVTGHvPx25, target_link_uri)
    A->>R: store NONCE (300s)
    A-->>B: Set-Cookie lti1p3_STATE<br/>(SameSite=None, Secure)
    A-->>B: 302 → https://localhost/mod/lti/auth.php<br/>(response_mode=form_post, prompt=none, state, nonce)
    end

    rect rgb(235, 255, 238)
    note right of B: 2 — Platform signs the id_token
    B->>M: GET /mod/lti/auth.php
    note over M: already logged in (prompt=none)<br/>mint id_token (JWT, RS256)
    M-->>B: HTML auto-POST form → auth.lvh.me/lti/launch
    end

    rect rgb(255, 243, 235)
    note right of B: 3 — Launch validation (cross-site POST)
    B->>A: POST /lti/launch (id_token + state,<br/>Cookie: lti1p3_STATE)
    note over A: state cookie == posted state (CSRF)
    A->>R: check + delete NONCE (replay)
    A->>M: GET http://moodle:8080/mod/lti/certs.php<br/>Host: localhost, X-Forwarded-Proto: https (split-horizon)
    M-->>A: JWKS public keys
    note over A: verify signature, iss, aud,<br/>deployment_id=1, exp
    A->>R: write session sess:SID (3600s)
    A-->>B: Set-Cookie pn_session=SID<br/>(Domain=lvh.me, httpOnly, Secure, Lax)
    A-->>B: 302 → https://app.lvh.me/
    end

    rect rgb(244, 238, 255)
    note right of B: 4 — App loads, reads session
    B->>P: GET / (+ assets)
    P-->>B: React bundle
    B->>I: GET /api/me (cross-origin, credentials:include,<br/>Cookie: pn_session=SID)
    I->>R: read session sess:SID
    R-->>I: identity
    I-->>B: 200 user JSON<br/>Access-Control-Allow-Origin: https://app.lvh.me<br/>Access-Control-Allow-Credentials: true
    note over B: React renders "Welcome, Admin User"
    end
```

## The mechanism at each boundary

| Boundary | Mechanism | Why it's needed |
|---|---|---|
| launch POST back (2 → 3) | state cookie `SameSite=None; Secure` | the launch returns as a **cross-site POST** from Moodle; a Lax cookie wouldn't be sent |
| signature check (3) | split-horizon `http://moodle:8080` + `Host: localhost` + `X-Forwarded-Proto: https` | the container can't reach `https://localhost`; Moodle 303-redirects unless the forwarded headers say it's already https |
| auth → app (3 → 4) | `pn_session` cookie `Domain=lvh.me`, `SameSite=Lax`, `httpOnly` | shared across `*.lvh.me`, survives refresh, unreadable by JS |
| app → api (4) | cross-origin CORS + `credentials:'include'` | `app` and `api` are different **origins** but the same **site** |

**Refresh** just re-runs step 4 — the cookie persists and Redis still holds the session, so no re-launch is needed. **Logout** (`POST https://api.lvh.me/api/logout`) deletes `sess:SID` from Redis and expires the cookie.
