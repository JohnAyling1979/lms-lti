# LTI 1.3 Weekend — from zero to expert

**Goal:** understand LTI 1.3 / LTI Advantage deeply enough to build it into PowerNotes (PHP API + auth, React front end). Moodle in Docker plays the **Platform**; our mock app is the **Tool**.

---

## 0. Mental model (read this first)

Two actors:

- **Platform** = the LMS = **Moodle** (also Canvas, Blackboard, D2L…). Owns the user, the course, the gradebook.
- **Tool** = our app = **PowerNotes**. Gets launched *from inside* the LMS.

LTI versions:

- **LTI 1.1 (legacy)** — signed form POST using OAuth **1.0** HMAC-SHA1 with a **shared secret**. Simple, insecure-ish, still everywhere.
- **LTI 1.3 + LTI Advantage (current)** — built on **OpenID Connect + OAuth 2.0 + JWT/JWS (RS256) + JWKS**. Asymmetric keys, no shared secret. **This is what we learn.**

The single biggest conceptual upgrade in 1.3: **asymmetric signing**. The platform signs the launch with *its* private key; the tool verifies with the platform's public key (fetched from the platform's JWKS URL). For calling services back, the tool signs with *its* private key; platform verifies via the tool's JWKS URL.

---

## 1. The launch flow (memorize this sequence)

An LTI 1.3 launch is an **OpenID Connect third-party-initiated login**. Four hops:

```
  Moodle (Platform)                         PowerNotes (Tool)
        |                                          |
  (1)   |  OIDC login initiation  -------------->  |   /lti/login
        |  iss, login_hint, target_link_uri,       |   (tool generates state + nonce,
        |  lti_message_hint, client_id             |    stores them)
        |                                          |
  (2)   |  <-------  redirect to auth endpoint  -- |
        |  scope=openid response_type=id_token     |
        |  response_mode=form_post prompt=none     |
        |  client_id redirect_uri login_hint       |
        |  state nonce                             |
        |                                          |
  (3)   |  form POST id_token (JWT) + state ----->  |  /lti/launch
        |  (signed with platform private key)      |  (tool validates everything)
        |                                          |
  (4)   |                                          |  tool creates its OWN session,
        |                                          |  renders React app
```

### What the Tool MUST validate on the id_token (step 3)

1. Fetch platform public keys from its **JWKS** endpoint; select by `kid` in the JWT header.
2. Verify the **JWS signature** (RS256).
3. `iss` == the registered platform issuer.
4. `aud` contains our `client_id` (check `azp` if multiple audiences).
5. `exp` not passed, `iat` sane.
6. `nonce` matches the one we stored **and has not been used before** (replay protection).
7. `state` (returned as form field) matches what we stored (CSRF).
8. LTI claims present and correct:
   - `.../claim/message_type` = `LtiResourceLinkRequest` (or `LtiDeepLinkingRequest`)
   - `.../claim/version` = `1.3.0`
   - `.../claim/deployment_id`
   - `.../claim/target_link_uri`
   - `.../claim/resource_link`, `.../claim/roles`, `.../claim/context`
   - user identity: `sub`, and (if released) `name`, `email`

> Claim URIs are namespaced, e.g. `https://purl.imsglobal.org/spec/lti/claim/roles`. Libraries hide this, but look at a raw token at least once.

---

## 2. Registration — what the two sides exchange (one-time, per institution)

**Tool gives the Platform:**
- Login initiation URL (`/lti/login`)
- Redirect/launch URI(s) (`/lti/launch`) — must be pre-registered exactly
- Tool public **JWKS URL** (`/lti/jwks`)
- Deep Linking URL (`/lti/deeplink`)

**Platform gives the Tool** (Moodle shows these after you register a tool):
- **Platform issuer** (`iss`)
- **Client ID**
- **Deployment ID**
- **Auth request / login URL** (OIDC auth endpoint)
- **Access token URL** (for AGS/NRPS OAuth2 tokens)
- **Platform JWKS / public keyset URL**

> In PowerNotes this is **per-platform data in a DB table**, not config — you'll onboard many institutions. Design for multi-tenant from day one.

---

## 3. LTI Advantage services (the reason 1.3 matters)

All three call *back* into the platform. Auth = OAuth2 **client_credentials**, where the client assertion is a **JWT signed with the tool's private key**, exchanged at the platform's token endpoint for a short-lived bearer token.

- **AGS — Assignment & Grade Services**: read/create line items, POST scores → LMS gradebook. Scopes: `…/ags/lineitem`, `…/ags/result.readonly`, `…/ags/score`.
- **NRPS — Names & Role Provisioning Services**: fetch the course roster. Scope: `…/contextmembership.readonly`.
- **Deep Linking (Content-Item)**: user picks content inside the tool; tool returns a signed `LtiDeepLinkingResponse` JWT that the platform stores as a resource link.

---

## 4. Tooling choices

### Moodle (Platform) in Docker
- **Easiest:** `bitnami/moodle` compose — up in minutes.
- **Proper dev harness:** `moodlehq/moodle-docker` — supports phpunit/behat, DB choice. Better long-term.
- Use **Moodle 4.x** (LTI 1.3 is mature). Configure under
  *Site administration → Plugins → Activity modules → External tool → Manage tools → configure a tool manually*.

### PHP LTI library (Tool side)
- **`packbackbooks/lti-1p3-tool`** — the maintained, IMS-certified successor to the old IMSGlobal reference library. Composer package is **`packbackbooks/lti-1p3-tool`** (v6.x, PHP ^8.1, namespace `Packback\Lti1p3`); its GitHub repo is confusingly named `packbackbooks/lti-1-3-php-library`. Covers launch validation + Deep Linking + AGS + NRPS + JWKS. **Recommended.**
  > Heads-up: `packbackbooks/lti-1-3-php-library` and `packback/lti-1-3-php-library` do NOT exist on Packagist — the `composer require` name is `packbackbooks/lti-1p3-tool`.
- **`ceLTIc/LTI-PHP`** (Stephen Vickers) — very mature, supports **1.1 and 1.3**, tool + platform, many DB connectors. Heavier; great reference and useful if you ever need 1.1 fallback.

### Test/inspection tools (huge for learning)
- **saltire** (`suite.saltire.lti.app`) — a test **Tool** *and* test **Platform** that dumps every parameter. Point Moodle at saltire's test tool to validate your Moodle config with **zero code**; or point your tool at saltire's test platform.
- 1EdTech **LTI Reference Implementation** (`lti-ri`).

### The #1 practical gotcha: HTTPS + cookies
Cross-site form POST + tool session cookie means browsers require `SameSite=None; Secure` → **you need HTTPS**. Options:
- **mkcert + Caddy** reverse proxy giving `moodle.localhost` and `tool.localhost` on trusted HTTPS (cleanest).
- **ngrok / cloudflared** tunnel for HTTPS.
Plan for this early — it's where most people lose an afternoon.

---

## 5. Weekend schedule

### Friday night — setup (1–2h)
- [ ] Skim the 1EdTech LTI 1.3 core spec + the flow diagram above.
- [ ] Get Moodle running in Docker; log in as admin; create a test course + a student.
- [ ] Sort the HTTPS story (mkcert+Caddy or ngrok). Confirm Moodle reachable over HTTPS.

### Saturday AM — understand + first launch
- [ ] Re-draw the 4-hop flow from memory.
- [ ] In Moodle, register **saltire's test tool** and do a launch → inspect the JWT/claims. No code yet; this proves your Moodle config.
- [ ] Stand up a minimal **PHP tool** (`packback` lib): `/lti/login`, `/lti/launch`, `/lti/jwks`. Get ONE successful `LtiResourceLinkRequest` and dump all claims.

### Saturday PM — React + session
- [ ] After PHP validates the launch and mints a tool session, hand off to **React** (server renders a bootstrap with a session token). Understand: LTI authenticates *once*; React routing rides your tool session, not repeated launches.

### Sunday AM — LTI Advantage
- [ ] **Deep Linking**: return a content item; see it become a link in the course.
- [ ] **AGS**: create a line item, POST a score, see it in the Moodle gradebook.
- [ ] **NRPS**: fetch the roster.
- [ ] Do the OAuth2 client-credentials token dance at least once and understand it.

### Sunday PM — consolidate + PowerNotes fit
- [ ] (Optional, expert move) Rebuild raw `id_token` validation by hand with a JOSE lib to cement it.
- [ ] Design notes for PowerNotes (see below).

---

## 6. PowerNotes integration notes (the actual endgame)

- **Registration storage**: DB table per platform — `issuer`, `client_id`, `deployment_id(s)`, auth URL, token URL, JWKS URL, tool keypair ref. Multi-tenant, not config.
- **Identity mapping / account linking**: `(iss, sub)` is a stable unique user key per platform. Map it to a PowerNotes user. First launch → JIT provision or link to existing account (email claim, handled carefully — email may be withheld by platform privacy settings; handle anonymous launches).
- **Auth bridge**: the LTI launch authenticates the user for that session only; then mint a normal PowerNotes session/JWT. **Keep LTI JWTs at the edge** — don't let them become your app session token.
- **Key management**: tool RSA keypair per environment (or per registration), rotation, expose current + previous public keys in JWKS via `kid`.
- **Security checklist**: nonce replay store (with TTL), state validation, `exp`/`iat` windows, deployment_id scoping, strict per-registration isolation (never trust `iss` you don't know).
- **Beyond Moodle**: Canvas (developer keys) and Blackboard use the same LTI Advantage core with minor config differences — the abstraction you build should not be Moodle-specific.

---

## 7. Reference links to pull up
- 1EdTech LTI 1.3 core spec + LTI Advantage (Deep Linking, AGS, NRPS) specs
- `packbackbooks/lti-1p3-tool` on Packagist (repo: `github.com/packbackbooks/lti-1-3-php-library`, README + example app)
- `ceLTIc/LTI-PHP` (GitHub)
- saltire test suite: `suite.saltire.lti.app`
- `moodlehq/moodle-docker` and/or `bitnami/moodle`
