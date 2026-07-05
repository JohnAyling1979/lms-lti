import { useEffect, useState } from 'react'
import { fetchMe, logout, fetchRoster } from './api'

// The launch establishes an httpOnly session cookie (first-party, SameSite=Lax),
// so this SPA just asks the API who it is — and a hard refresh Just Works because
// the cookie persists. This is the model PowerNotes uses (new-window / first-party).
// httpOnly means JS can't read the token, so XSS can't exfiltrate the session.
export default function App() {
  const [status, setStatus] = useState('loading') // loading | ready | nolaunch | loggedout | error
  const [user, setUser] = useState(null)
  const [error, setError] = useState(null)
  const [roster, setRoster] = useState(null)
  const [busy, setBusy] = useState('')       // which service call is in flight
  const [svcError, setSvcError] = useState('')

  useEffect(() => {
    // idempotent GET — safe to run twice under StrictMode, no guard needed
    const loadSession = async () => {
      try {
        const data = await fetchMe()
        if (!data) {
          setStatus('nolaunch')
          return
        }
        setUser(data.user)
        setStatus('ready')
      } catch (e) {
        setError(e.message)
        setStatus('error')
      }
    }
    loadSession()
  }, [])

  const onLogout = async () => {
    await logout()
    setUser(null)
    setStatus('loggedout')
  }

  const onFetchRoster = async () => {
    setBusy('roster')
    setSvcError('')
    try {
      const { members } = await fetchRoster()
      setRoster(members)
    } catch (e) {
      setSvcError(e.message)
    } finally {
      setBusy('')
    }
  }

  const shortRole = (r) => r.split('#').pop().split('/').pop()

  if (status === 'loading') return <Centered>Loading session…</Centered>
  if (status === 'loggedout')
    return (
      <Centered>
        <h1>Logged out</h1>
        <p>You can close this window.</p>
      </Centered>
    )
  if (status === 'nolaunch')
    return (
      <Centered>
        <h1>LTI Tool</h1>
        <p>No active session. Launch this tool from inside Moodle.</p>
      </Centered>
    )
  if (status === 'error')
    return (
      <Centered>
        <h1>Session error</h1>
        <pre className="err">{error}</pre>
      </Centered>
    )

  const isInstructor = user.role === 'instructor'

  return (
    <main className="wrap">
      <header className="topbar">
        <strong>LMS-LTI Mock Tool</strong>
        <span>
          <span className={`badge ${user.role}`}>{user.role}</span>
          <button className="link" onClick={onLogout}>Log out</button>
        </span>
      </header>

      <section className="card">
        <h1>Welcome, {user.name ?? 'anonymous'}</h1>
        <p className="muted">Refresh this page — your session survives (httpOnly cookie).</p>
        <dl className="grid">
          <dt>Email</dt><dd>{user.email ?? '—'}</dd>
          <dt>User id (sub)</dt><dd><code>{user.sub}</code></dd>
          <dt>Course</dt><dd>{user.context?.title ?? '—'}</dd>
          <dt>Placement</dt><dd>{user.resourceLink?.title ?? '—'}</dd>
          <dt>Issuer</dt><dd><code>{user.iss}</code></dd>
        </dl>
      </section>

      {isInstructor ? (
        <section className="card panel-instructor">
          <h2>👩‍🏫 Instructor tools</h2>
          <button disabled>Sync a grade → gradebook (AGS)</button>
          <button onClick={onFetchRoster} disabled={busy === 'roster'}>
            {busy === 'roster' ? 'Fetching…' : 'Fetch roster (NRPS)'}
          </button>
          {svcError && <p className="err" style={{ padding: '.5rem .75rem' }}>{svcError}</p>}
          {roster && (
            <table className="roster">
              <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th></tr>
              </thead>
              <tbody>
                {roster.map((m) => (
                  <tr key={m.user_id}>
                    <td>{m.name}</td>
                    <td>{m.email ?? '—'}</td>
                    <td>{(m.roles || []).map(shortRole).join(', ')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>
      ) : (
        <section className="card panel-learner">
          <h2>🎓 Your work</h2>
          <p>You're enrolled as a learner. Your submissions would appear here.</p>
          <button disabled>Submit</button>
        </section>
      )}

      <details className="card">
        <summary>Raw LTI claims (what the launch carried)</summary>
        <pre className="claims">{JSON.stringify(user.claims, null, 2)}</pre>
      </details>
    </main>
  )
}

function Centered({ children }) {
  return <div className="centered">{children}</div>
}
