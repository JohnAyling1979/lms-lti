// The API is a separate origin (api.lvh.me) from this UI (app.lvh.me).
// - absolute URL to the api host
// - credentials:'include' so the browser sends the Domain=lvh.me session cookie
//   cross-origin (app.lvh.me and api.lvh.me are same-SITE, so a Lax cookie is sent)
// The api must reply with Access-Control-Allow-Origin: https://app.lvh.me and
// Access-Control-Allow-Credentials: true, or the browser hides the response.
// Build-time config (Vite VITE_* env), with local-dev defaults. The prod build
// (docker-compose.prod.yml) sets these to the real *.powernotes.com hosts.
const API = import.meta.env.VITE_API_URL || 'https://api.lvh.me'     // session API
const AUTH = import.meta.env.VITE_AUTH_URL || 'https://auth.lvh.me'  // LTI + LMS service calls (holds the tool key)

export async function fetchMe() {
  const res = await fetch(`${API}/api/me`, { credentials: 'include' })
  if (res.status === 401) return null // no session -> not launched
  if (!res.ok) throw new Error(`/api/me failed (HTTP ${res.status})`)
  return res.json() // { user }
}

export async function logout() {
  await fetch(`${API}/api/logout`, { method: 'POST', credentials: 'include' })
}

// NRPS — course roster (auth makes the LMS call with the tool's key)
export async function fetchRoster() {
  const res = await fetch(`${AUTH}/services/roster`, { credentials: 'include' })
  if (!res.ok) throw new Error(`roster failed (HTTP ${res.status})`)
  return res.json() // { members }
}

// AGS — list the tool's line items (assignments it owns)
export async function fetchLineitems() {
  const res = await fetch(`${AUTH}/services/lineitems`, { credentials: 'include' })
  if (!res.ok) throw new Error(`assignments failed (HTTP ${res.status})`)
  return res.json() // { lineitems: [{ id, label, scoreMaximum }] }
}

// AGS — read existing grades for a line item (who's already been graded)
export async function fetchResults(lineitem) {
  const res = await fetch(`${AUTH}/services/results?lineitem=${encodeURIComponent(lineitem)}`, {
    credentials: 'include',
  })
  if (!res.ok) throw new Error(`results failed (HTTP ${res.status})`)
  return res.json() // { results: [{ userId, resultScore, resultMaximum }] }
}

// The learner's saved work for the launched placement (null if not submitted)
export async function fetchSubmission() {
  const res = await fetch(`${AUTH}/services/submission`, { credentials: 'include' })
  if (!res.ok) throw new Error(`submission failed (HTTP ${res.status})`)
  return res.json() // { submission: {content, submittedAt}|null, grade: {resultScore, resultMaximum}|null }
}

// Save the learner's work to the tool + mark the LMS activity Submitted
export async function submitWork(content) {
  const res = await fetch(`${AUTH}/services/submit`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ content }),
  })
  if (!res.ok) throw new Error(`submit failed (HTTP ${res.status})`)
  return res.json() // { ok, submittedAt }
}

// Instructor — the "needs grading" queue: ungraded submissions per assignment
export async function fetchNeedsGrading() {
  const res = await fetch(`${AUTH}/services/needsgrading`, { credentials: 'include' })
  if (!res.ok) throw new Error(`needs-grading failed (HTTP ${res.status})`)
  return res.json() // { items: [{ lineitem, label, resourceLinkId, needsGrading }], total }
}

// Instructor — every learner's submitted work for a placement (from the tool DB)
export async function fetchSubmissionsFor(resourceLinkId) {
  const res = await fetch(`${AUTH}/services/submissions?resourceLinkId=${encodeURIComponent(resourceLinkId)}`, {
    credentials: 'include',
  })
  if (!res.ok) throw new Error(`submissions failed (HTTP ${res.status})`)
  return res.json() // { submissions: [{ userId, content, submittedAt }] }
}

// AGS — push a score to an EXISTING line item for a user
export async function syncGrade(lineitem, userId, score, scoreMaximum) {
  const res = await fetch(`${AUTH}/services/grade`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ lineitem, userId, score, scoreMaximum }),
  })
  if (!res.ok) throw new Error(`grade failed (HTTP ${res.status})`)
  return res.json() // { ok, lineitem, userId, score }
}
