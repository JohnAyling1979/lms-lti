import { useEffect, useState } from 'react'
import { fetchMe, logout, fetchRoster, fetchLineitems, fetchResults, fetchSubmissionsFor, fetchNeedsGrading, syncGrade, fetchSubmission, submitWork } from './api'

// The launch establishes an httpOnly session cookie (first-party, SameSite=Lax),
// so this SPA just asks the API who it is — and a hard refresh Just Works because
// the cookie persists. This is the model PowerNotes uses (new-window / first-party).
// httpOnly means JS can't read the token, so XSS can't exfiltrate the session.
export default function App() {
  const [status, setStatus] = useState('loading') // loading | ready | nolaunch | loggedout | error
  const [user, setUser] = useState(null)
  const [error, setError] = useState(null)
  const [roster, setRoster] = useState(null)
  const [assignments, setAssignments] = useState(null) // line items the tool owns
  const [lineitem, setLineitem] = useState('')         // selected line item id (url)
  const [scores, setScores] = useState({})   // userId -> entered score (string)
  const [submissions, setSubmissions] = useState({}) // userId -> {content, submittedAt} for the selected assignment
  const [needsGrading, setNeedsGrading] = useState({ items: [], total: 0 }) // the instructor "to grade" queue
  const [graded, setGraded] = useState({})   // `${lineitem}|${userId}` -> posted score
  const [busy, setBusy] = useState('')       // which service call is in flight
  const [svcError, setSvcError] = useState('')
  const [submitted, setSubmitted] = useState(false) // learner turned in their work
  const [submittedAt, setSubmittedAt] = useState(null)
  const [content, setContent] = useState('')        // the learner's work (lives in the tool)
  const [myGrade, setMyGrade] = useState(null)       // the learner's own grade, if graded

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
        // A learner's work + submitted state live in the tool, so read them back
        // — that's what makes "Submitted" survive a refresh (AGS can't tell us).
        if (data.user.role === 'learner') {
          const { submission, grade } = await fetchSubmission()
          if (submission) {
            setSubmitted(true)
            setSubmittedAt(submission.submittedAt)
            setContent(submission.content || '')
          }
          if (grade) setMyGrade(grade)
        }
        // Instructors get a "needs grading" notice on launch — the alert Moodle
        // won't send, computed by the tool from its own submissions vs AGS grades.
        if (data.user.role === 'instructor') {
          setNeedsGrading(await fetchNeedsGrading())
        }
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
  const isLearner = (m) => (m.roles || []).some((r) => shortRole(r) === 'Learner')
  const selected = (assignments || []).find((a) => a.id === lineitem)

  // Read existing grades (AGS results) for a line item and fold them into
  // `graded` so learners who already have a score show "✓ score/max" — whether
  // graded here or straight in Moodle.
  const loadResults = async (lineitemId) => {
    if (!lineitemId) return
    try {
      const { results } = await fetchResults(lineitemId)
      setGraded((g) => {
        const next = { ...g }
        for (const r of results) {
          if (r.resultScore != null) {
            next[`${lineitemId}|${r.userId}`] = `${r.resultScore}/${r.resultMaximum ?? ''}`
          }
        }
        return next
      })
    } catch (e) {
      setSvcError(e.message)
    }
  }

  // The learners' submitted work for a placement — read from the tool's DB
  // (the LMS never has it), keyed by the line item's resourceLinkId.
  const loadSubmissions = async (resourceLinkId) => {
    if (!resourceLinkId) { setSubmissions({}); return }
    try {
      const { submissions: list } = await fetchSubmissionsFor(resourceLinkId)
      setSubmissions(Object.fromEntries(list.map((s) => [s.userId, s])))
    } catch (e) {
      setSvcError(e.message)
    }
  }

  const onFetchAssignments = async () => {
    setBusy('assignments')
    setSvcError('')
    try {
      const { lineitems } = await fetchLineitems()
      setAssignments(lineitems)
      const first = lineitems.length && !lineitem ? lineitems[0] : lineitems.find((a) => a.id === lineitem)
      if (first) {
        setLineitem(first.id)
        await loadResults(first.id)
        await loadSubmissions(first.resourceLinkId)
      }
    } catch (e) {
      setSvcError(e.message)
    } finally {
      setBusy('')
    }
  }

  const onSelectAssignment = (id) => {
    setLineitem(id)
    setScores({})       // clear editable inputs for the newly selected assignment
    loadResults(id)     // show which learners it already has grades for
    loadSubmissions((assignments || []).find((a) => a.id === id)?.resourceLinkId)
  }

  const loadNeedsGrading = async () => {
    try {
      setNeedsGrading(await fetchNeedsGrading())
    } catch (e) {
      setSvcError(e.message)
    }
  }

  // Jump straight from the notice to grading an assignment: load roster if
  // needed, select the assignment, and pull its submissions + grades.
  const onReviewAssignment = async (item) => {
    setBusy('review:' + item.resourceLinkId)
    setSvcError('')
    try {
      let list = assignments
      if (!list) { list = (await fetchLineitems()).lineitems; setAssignments(list) }
      if (!roster) { setRoster((await fetchRoster()).members) }
      setLineitem(item.lineitem)
      setScores({})
      await loadResults(item.lineitem)
      await loadSubmissions(item.resourceLinkId)
    } catch (e) {
      setSvcError(e.message)
    } finally {
      setBusy('')
    }
  }

  const onGrade = async (userId) => {
    if (!selected) return
    const max = selected.scoreMaximum ?? 100
    const raw = scores[userId]
    const score = Number(raw)
    if (raw == null || raw === '' || Number.isNaN(score)) {
      setSvcError('Enter a score first')
      return
    }
    setBusy('grade:' + userId)
    setSvcError('')
    try {
      await syncGrade(selected.id, userId, score, max)
      setGraded((g) => ({ ...g, [`${selected.id}|${userId}`]: `${score}/${max}` }))
      loadNeedsGrading() // one fewer to grade — refresh the notice
    } catch (e) {
      setSvcError(e.message)
    } finally {
      setBusy('')
    }
  }

  const onSubmit = async () => {
    setBusy('submit')
    setSvcError('')
    try {
      const { submittedAt } = await submitWork(content)
      setSubmitted(true)
      setSubmittedAt(submittedAt)
    } catch (e) {
      setSvcError(e.message)
    } finally {
      setBusy('')
    }
  }

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

          {needsGrading.total > 0 && (
            <div className="notice">
              🔔 <strong>{needsGrading.total}</strong> submission{needsGrading.total > 1 ? 's' : ''} awaiting grading
              <ul>
                {needsGrading.items.map((it) => (
                  <li key={it.resourceLinkId}>
                    <button
                      className="link"
                      onClick={() => onReviewAssignment(it)}
                      disabled={busy === 'review:' + it.resourceLinkId}
                    >
                      {it.label}
                    </button>{' '}— {it.needsGrading} to grade
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="toolbar">
            <button onClick={onFetchAssignments} disabled={busy === 'assignments'}>
              {busy === 'assignments' ? 'Fetching…' : 'Fetch assignments (AGS)'}
            </button>
            <button onClick={onFetchRoster} disabled={busy === 'roster'}>
              {busy === 'roster' ? 'Fetching…' : 'Fetch roster (NRPS)'}
            </button>
          </div>

          {assignments && assignments.length === 0 && (
            <p className="muted">No assignments yet — create one via <strong>Deep Linking</strong> (the "Select content" button when adding the activity).</p>
          )}
          {assignments && assignments.length > 0 && (
            <p className="muted">
              Grade for assignment:{' '}
              <select value={lineitem} onChange={(e) => onSelectAssignment(e.target.value)}>
                {assignments.map((a) => (
                  <option key={a.id} value={a.id}>{a.label} (max {a.scoreMaximum})</option>
                ))}
              </select>
            </p>
          )}

          {svcError && <p className="err" style={{ padding: '.5rem .75rem' }}>{svcError}</p>}

          {roster && (
            <table className="roster">
              <thead>
                <tr><th>Name</th><th>Role</th><th>Submission</th><th></th></tr>
              </thead>
              <tbody>
                {roster.map((m) => {
                  const key = selected ? `${selected.id}|${m.user_id}` : null
                  const sub = submissions[m.user_id]
                  return (
                    <tr key={m.user_id}>
                      <td>{m.name}</td>
                      <td>{(m.roles || []).map(shortRole).join(', ')}</td>
                      <td>
                        {isLearner(m) && (sub ? (
                          <details>
                            <summary>📄 View</summary>
                            <div className="submission-view">{sub.content || <em>(empty)</em>}</div>
                          </details>
                        ) : selected ? (
                          <span className="muted">—</span>
                        ) : null)}
                      </td>
                      <td>
                        {isLearner(m) && key && graded[key] != null && (
                          <span className="muted">✓ {graded[key]}</span>
                        )}
                        {isLearner(m) && !(key && graded[key] != null) && !selected && (
                          <span className="muted">pick an assignment</span>
                        )}
                        {isLearner(m) && !(key && graded[key] != null) && selected && (
                          <span className="grade-input">
                            <input
                              type="number"
                              min="0"
                              max={selected.scoreMaximum}
                              value={scores[m.user_id] ?? ''}
                              placeholder={`0–${selected.scoreMaximum}`}
                              onChange={(e) => setScores((s) => ({ ...s, [m.user_id]: e.target.value }))}
                            />
                            <button
                              onClick={() => onGrade(m.user_id)}
                              disabled={busy === 'grade:' + m.user_id || (scores[m.user_id] ?? '') === ''}
                            >
                              {busy === 'grade:' + m.user_id ? 'Posting…' : 'Post'}
                            </button>
                          </span>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          )}
        </section>
      ) : (
        <section className="card panel-learner">
          <h2>🎓 Your work</h2>
          <p>
            You're working on <strong>{user.resourceLink?.title ?? 'this assignment'}</strong>.
            Your work is stored in the tool — the LMS only receives your grade.
          </p>
          {myGrade ? (
            <>
              <p className="grade-banner">✓ Graded: {myGrade.resultScore}/{myGrade.resultMaximum ?? '—'}</p>
              {content ? (
                <>
                  <p className="muted">Your submission:</p>
                  <textarea className="submission" rows={5} value={content} disabled readOnly />
                </>
              ) : (
                <p className="muted">No submission was recorded for this activity.</p>
              )}
            </>
          ) : (
            <>
              <textarea
                className="submission"
                rows={5}
                value={content}
                disabled={submitted}
                placeholder="Write your response here…"
                onChange={(e) => setContent(e.target.value)}
              />
              {submitted ? (
                <p className="muted">
                  ✓ Submitted{submittedAt ? ` ${new Date(submittedAt).toLocaleString()}` : ''} — awaiting your instructor's grade.
                </p>
              ) : (
                <button onClick={onSubmit} disabled={busy === 'submit' || content.trim() === ''}>
                  {busy === 'submit' ? 'Submitting…' : 'Submit'}
                </button>
              )}
            </>
          )}
          {svcError && <p className="err" style={{ padding: '.5rem .75rem' }}>{svcError}</p>}
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
