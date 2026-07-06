# LTI 1.3 / LTI Advantage — Capabilities & Endpoint Reference

A single-page map of **what LTI can do**, **the actual calls/scopes behind each capability**, and **how this lab implements them**. Tool = our app (PowerNotes). Platform = the LMS (Moodle here; Canvas etc. later).

---

## 0. The one mental model

LTI has exactly **two kinds of interaction**, and everything below is one or the other:

1. **Messages** — browser-mediated, signed **JWTs** passed via redirects/form-POSTs. The launch itself. No REST, no tokens. Trust = signature verified against the sender's JWKS.
2. **Services** — server-to-server **REST** calls the Tool makes to the Platform (AGS, NRPS, …). Trust = **OAuth2 client-credentials** access token, obtained by signing a JWT with the tool's private key.

> Messages carry **identity + context**. Services carry **data** (grades, rosters). The launch never carries a grade; a service call never logs anyone in.

---

## 1. Messages — what a launch can be (message types)

Every launch is an OIDC flow that ends in a signed `id_token` whose `.../claim/message_type` says which of these it is:

| Message type | What it does | LTI spec |
|---|---|---|
| **`LtiResourceLinkRequest`** | The normal launch — open the tool for a placement (SSO + context). | Core 1.3 |
| **`LtiDeepLinkingRequest`** → **`LtiDeepLinkingResponse`** | Tool returns content items the Platform then creates (activities + line items). | Deep Linking (Content-Item) |
| **`LtiSubmissionReviewRequest`** | Launch an instructor into the tool's view of a *specific learner's* submission for a line item. | AGS / Submission Review |
| **`LtiEulaRequest`** | Present/accept an end-user license before use. | Newer (LTI 2p1) |
| **`LtiAssetProcessorSettingsRequest`** / **`LtiReportReviewRequest`** | Asset Processor: tool inspects submitted files, returns reports. | Asset Processor (new) |

**What a `ResourceLinkRequest` carries (the useful claims):**

| Claim (short) | Meaning |
|---|---|
| `sub`, `name`, `email`, `given_name`… | Who launched (identity) |
| `.../claim/roles` | Institution/context/system roles → Instructor vs Learner |
| `.../claim/context` | Course id + title/label |
| `.../claim/resource_link` | The placement id + title (which activity) |
| `.../claim/custom` | Custom params (e.g. our `pn_project` / `pn_milestone`) |
| `.../claim/deployment_id` | Which deployment within the registration |
| `.../lti-ags/claim/endpoint` | AGS service URLs + scopes (see §2) |
| `.../lti-nrps/claim/namesroleservice` | NRPS roster URL (see §2) |
| `.../claim/lti11_legacy_user_id` etc. | 1.1→1.3 migration helpers |

> **Gradable placement vs course-level:** a launch from a *graded activity* adds a **specific** `ags.lineitem` (that activity's column) to the endpoint claim. A course-level launch (our hidden "Sign on") carries only the `lineitems` **collection**. This is how the tool knows "you launched *this* assignment" vs "you launched the course dashboard."

---

## 2. Services — the REST surface (the "LTI Advantage" services)

Each service is a set of REST calls gated by **OAuth2 scopes**. The tool requests only the scopes the Platform granted at registration.

### AGS — Assignment & Grade Services (the gradebook)
The tool can only touch **line items it owns** (created via Deep Linking or `POST lineitems`) — never native LMS assignments.

| Call | HTTP | Scope | Purpose |
|---|---|---|---|
| List line items | `GET {lineitems}` | `…/ags/scope/lineitem` (or `lineitem.readonly`) | All columns the tool owns in the context |
| Create line item | `POST {lineitems}` | `…/ags/scope/lineitem` | New gradebook column (no activity) |
| Read / update / delete one | `GET/PUT/DELETE {lineitem}` | `lineitem` (`lineitem.readonly` for GET) | Manage a column |
| **Post a score** | `POST {lineitem}/scores` | `…/ags/scope/score` | Push a grade + status (`activityProgress`, `gradingProgress`) |
| **Read results** | `GET {lineitem}/results` | `…/ags/scope/result.readonly` | Who has what score (scores only — **not** "submitted" status) |

Content types: `…lis.v2.lineitem+json`, `…lis.v2.lineitemcontainer+json`, `…lis.v1.score+json`, `…lis.v2.resultcontainer+json`.

### NRPS — Names & Role Provisioning Services (the roster)
| Call | HTTP | Scope | Purpose |
|---|---|---|---|
| Get members | `GET {context_memberships_url}` | `…/lti-nrps/scope/contextmembership.readonly` | Course roster: users + roles (+ optional `?role=`, `?rlid=` filters) |

Content type: `…lti-nrps.v2.membershipcontainer+json`.

### Deep Linking — content creation (no REST)
A **JWT round-trip**, not a REST call:
1. Platform sends `LtiDeepLinkingRequest` (carries `deep_linking_settings`: `deep_link_return_url`, `accept_types`, `accept_multiple`, …).
2. Tool shows a picker, builds content items, signs an `LtiDeepLinkingResponse` JWT.
3. Tool auto-POSTs it to `deep_link_return_url`; the Platform creates the activities/line items.

Content-item types: `ltiResourceLink` (launchable activity, may carry a `lineItem`), `link`, `file`, `html`, `image`.

### Course Groups Service (less common)
| Call | Purpose |
|---|---|
| `GET {context_groups_url}` → groups / sets / groups-by-set | Read the course's groups (for group assignments) |

### Other services in the LTI family
| Service | What it enables | Maturity |
|---|---|---|
| **Submission Review** | LMS gradebook link that deep-launches an instructor into the tool's view of *one* learner's submission for a line item (`LtiSubmissionReviewRequest`). Gives the *entry point*, not the UI. | AGS extension |
| **Dynamic Registration** | Tool + Platform auto-exchange registration config — no manual copy-paste of client_id/URLs. **Highest-value next step for multi-LMS.** | Growing |
| **Resource Search** | Tool exposes a searchable catalog of learning resources to the Platform. | Niche |
| **Platform Notices (PNS)** | Platform pushes async notices to the tool (`…/scope/noticehandlers`). | New |
| **Asset Processor** + **Report Review** | Tool inspects learner-submitted files (plagiarism/analysis) and returns reports (`…/scope/report`, `asset.readonly`). | New (2024) |
| **EULA service** | Record license acceptance per user/deployment (`…/scope/eula/user`, `eula/deployment`). | New |
| **ACS (Assessment Control)** | Proctoring: pause/resume/terminate an assessment. | Niche |

> **"Hand-built" vs the spec:** LTI services standardize *doorways and data*, never *UI*. We built the instructor submission-review **screen** (always required) and our own doorway to it (the needs-grading queue). The **Submission Review** spec would add a *second* doorway — a link in the LMS gradebook that launches straight into that screen for a specific user + line item. Complementary, not redundant.

---

## 3. The OAuth2 token dance (how every service call is authorized)

Before any REST call the tool trades a **signed JWT** for a short-lived **bearer token**:

```
POST {auth_token_url}
  grant_type            = client_credentials
  client_assertion_type = urn:ietf:params:oauth:client-assertion-type:jwt-bearer
  client_assertion      = <JWT signed with the TOOL's private key, aud = token endpoint>
  scope                 = "<space-separated scopes needed for this call>"
→ { access_token, expires_in }         # cache it until expiry
```

Then: `Authorization: Bearer <access_token>` on the AGS/NRPS/… request. In this lab the packback `LtiServiceConnector` does this automatically and caches the token in Redis.

---

## 4. Registration — what the two sides exchange (once, per Platform)

| Tool gives the Platform | Platform gives the tool |
|---|---|
| OIDC **login** URL (`/lti/login`) | **Issuer** (`iss`) |
| **Launch/redirect** URL(s) (`/lti/launch`, `/lti/deeplink`) | **Client ID** |
| **JWKS** URL *or* a pasted public key | **Deployment ID**(s) |
| **Deep Linking** URL (`/lti/deeplink`) | **OIDC auth** URL (where `/lti/login` redirects) |
| Which services/scopes it wants | **Token** URL + **JWKS** URL (to verify tool sigs) |

> Redirect URLs must be **whitelisted** on the Platform — a redirect_uri not on the list is rejected (we hit this adding `/lti/deeplink`).

---

## 5. This lab's endpoints

### `auth.lvh.me` — LTI service (holds the tool's private key)
| Endpoint | Method | LTI concept | Notes |
|---|---|---|---|
| `/lti/login` | GET | OIDC 3rd-party login init | Forwards `target_link_uri` → serves both launch + deep-linking |
| `/lti/launch` | POST | `ResourceLinkRequest` validate | Builds session, sets cookie, → app |
| `/lti/jwks` | GET | Tool's public keyset | (Moodle here uses a pasted key, so unused by Moodle) |
| `/lti/deeplink` | POST | Deep Linking req → response | Picker (hop A) + signed response auto-POST (hop B) |
| `/services/roster` | GET | NRPS `getMembers` | Instructors |
| `/services/lineitems` | GET | AGS `getLineItems` | Lists tool-owned line items (+ `resourceLinkId`) |
| `/services/results` | GET | AGS `getGrades` | Existing scores for a line item |
| `/services/grade` | POST | AGS `putGrade` | Instructor posts a score (`Completed`/`FullyGraded`) |
| `/services/submit` | POST | AGS `putGrade` + tool DB | Learner: save work + `Submitted`/`PendingManual` (no score) |
| `/services/submission` | GET | tool DB + AGS `getGrades` | Learner's own work + own grade |
| `/services/submissions` | GET | tool DB | Instructor: all learners' work for a placement |
| `/services/needsgrading` | GET | AGS × tool DB | Tool-computed "to grade" queue (the notice Moodle won't send) |

### `api.lvh.me` — session API (no LTI library, no tool key)
| Endpoint | Method | Purpose |
|---|---|---|
| `/api/me` | GET | Return the session identity (or 401) |
| `/api/logout` | POST | Clear session + cookie |

---

## 6. The packback library surface (`Packback\Lti1p3`, what we call)

| Class | Key methods |
|---|---|
| `LtiOidcLogin` | `getRedirectUrl(launchUrl, request)` |
| `Factories\MessageFactory` | `create(request)` → a `LaunchMessage` (`ResourceLinkRequest` / `DeepLinkingRequest` / …) |
| `Messages\DeepLinkingRequest` | `getDeepLink()`, `deepLinkSettingsClaim()` |
| `LtiDeepLink` | `getResponseJwt(resources)`, `returnUrl()`, `canAcceptMultiple()`, `acceptTypes()` |
| `DeepLinkResources\Resource` | `setTitle/setUrl/setCustomParams/setLineItem/setIframe/…` |
| `LtiAssignmentsGradesService` | `getLineItems`, `getLineItem`, `createLineitem`, `updateLineitem`, `deleteLineitem`, `findLineItem`, `findOrCreateLineitem`, `putGrade`, `getGrades`, `getResourceLaunchLineItem` |
| `LtiNamesRolesProvisioningService` | `getMembers(options)` |
| `LtiCourseGroupsService` | `getGroups`, `getSets`, `getGroupsBySet` |
| `LtiGrade` / `LtiLineitem` | Builders for score/line-item payloads |
| `LtiServiceConnector` | Does the token dance + signed request; caches tokens |
| `JwksEndpoint` | `fromIssuer(db, issuer)->getPublicJwks()` |
| Interfaces: `IDatabase`, `ICache`, `ICookie` | We implement these (`Database`, `Cache`, `Cookie`) |

---

## 7. Who can do what (roles)

| Capability | Learner | Instructor |
|---|---|---|
| Launch / SSO | ✅ | ✅ |
| See own submission + own grade | ✅ | ✅ |
| Submit work (`Submitted` status, no score) | ✅ | — |
| Deep Linking (create activities) | — | ✅ |
| NRPS roster | — | ✅ |
| AGS list/read/post grades | — | ✅ |
| "Needs grading" queue | — | ✅ |

> Enforcement is the **tool's** job: LTI hands you roles in the launch; you gate endpoints on them (we check `role === 'instructor'`).

---

## 8. The wider standards landscape (know they exist)

### The foundation everything sits on
- **LTI 1.3 Security Framework** — the OIDC + OAuth2 + JWT/JWKS layer. Not a "service," but *the* spec that makes launches and the token dance work. Everything in §1–2 is built on it.

### Legacy LTI (still in the wild)
| Version | Notes |
|---|---|
| **LTI 1.1 / 1.0** | OAuth **1.0** signatures; grade passback via the **Basic Outcomes Service** — the predecessor to AGS (single score, no line-item model). Still widely deployed. |
| **LTI 2.0** | A REST-based version between 1.1 and 1.3 that never caught on; effectively dead. |

> PowerNotes' old `pn-www` uses a 2020-era **LTI 1.1** library — this lab's whole point is moving to **1.3**.

### Sibling 1EdTech standards — paired with LTI, but NOT LTI
These solve adjacent problems and come up in the same integration conversations:

| Standard | Purpose | How it differs from LTI |
|---|---|---|
| **OneRoster** | Bulk SIS rostering + gradebook sync (REST/CSV), **institution-wide** | NRPS is per-launch/per-course; OneRoster needs no launch |
| **Caliper Analytics** | Emit learning-activity events for analytics | Different data plane; complements LTI |
| **QTI** | Assessment/question content format | Content, not connection |
| **Common Cartridge / Thin CC** | Course-content packaging (Thin CC can embed LTI links) | Packaging, not runtime |
| **CASE** | Competencies & academic-standards exchange | Standards data |
| **CLR / Open Badges** | Verifiable achievements & credentials | Credentialing |

### Relevance to PowerNotes
| Standard | Priority |
|---|---|
| Deep Linking · AGS · NRPS | ✅ done — the workhorses |
| **Dynamic Registration** | ⭐ highest-value next — scales "any major LMS" (goal #3) without per-institution hand-config |
| **Submission Review** | Nice-to-have — a gradebook doorway into the review screen we already built |
| **OneRoster** | Know it exists — if whole-institution rostering is ever needed outside a launch |
| Asset Processor · PNS · ACS · Resource Search | Situational |

---

## 9. Hard-won gotchas (from building this)

- A tool grades **only line items it owns** — never native LMS assignments.
- **AGS Results returns scores, not "submitted" status** → submission state must live in the tool's DB.
- **The LMS doesn't notify on submission** (the submission happens in the tool) → the "to-grade" queue is a tool feature.
- **Deep-Linking redirect URI must be whitelisted** on the Platform.
- Join key between an instructor's line item and learner submissions = **line item `resourceLinkId` ↔ launch `resource_link.id`**.
- Split-horizon URLs, cross-subdomain cookies, and `SameSite` are local-dev concerns (see `LAUNCH-FLOW.md`).
