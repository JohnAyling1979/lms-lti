// The API is a separate origin (api.lvh.me) from this UI (app.lvh.me).
// - absolute URL to the api host
// - credentials:'include' so the browser sends the Domain=lvh.me session cookie
//   cross-origin (app.lvh.me and api.lvh.me are same-SITE, so a Lax cookie is sent)
// The api must reply with Access-Control-Allow-Origin: https://app.lvh.me and
// Access-Control-Allow-Credentials: true, or the browser hides the response.
const API = 'https://api.lvh.me'     // session API
const AUTH = 'https://auth.lvh.me'   // LTI + LMS service calls (holds the tool key)

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
