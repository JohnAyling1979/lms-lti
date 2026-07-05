# lms-lti — local LTI 1.3 lab

A self-contained local environment for learning and building **LTI 1.3 / LTI Advantage**.
Moodle runs in Docker as the **Platform** (the LMS), served over trusted HTTPS, ready to
launch a **Tool** (the app you build).

The weekend learning curriculum lives in [LTI-WEEKEND.md](LTI-WEEKEND.md).

---

## Architecture

Everything sits behind **Caddy** (TLS termination via a trusted mkcert cert). Moodle is
the **Platform**; the **Tool** is split into three services like a real deployment:

```
                     ┌─ https://localhost      → Moodle (Platform) → MariaDB
  browser ─HTTPS:443─┤─ https://auth.lvh.me    → auth  (LTI: validate launch, set session)
        via Caddy    │─ https://api.lvh.me     → api   (/api/me, /api/logout — reads session)
                     └─ https://app.lvh.me     → app   (React UI bundle)

  auth + api share the session store — real Redis (as in prod)
  session cookie is Domain=lvh.me so it's sent to all three subdomains
```

- **Moodle** (`erseco/alpine-moodle`) — the LMS. Listens on `8080`; `SSLPROXY=true` keeps its
  wwwroot at `https://localhost`. The tool reaches it server-side at `http://moodle:8080`.
- **auth** (PHP + `packbackbooks/lti-1p3-tool`) — handles the LTI launch, writes the session,
  sets the `Domain=lvh.me` cookie, redirects to app.
- **api** (plain PHP, no deps) — reads the shared session; answers the UI with CORS + credentials.
- **redis** — the shared session store. `auth` writes `sess:<sid>`; `api` reads the same key.
  (auth + api each build their own image — `php:8.4-cli` + the **phpredis** extension — from their own `Dockerfile`.)
- **app** (React 19 / Vite) — the UI; calls `api.lvh.me` cross-origin with `credentials:'include'`.
- **`*.lvh.me`** resolves to `127.0.0.1` via public DNS (no `/etc/hosts`). We use it instead of
  `*.localhost` because a shared `Domain=` cookie can't be set on `localhost` (it's a single-label TLD).

---

## Prerequisites

- **Docker** (with Compose v2) — running
- **mkcert** — `brew install mkcert`

---

## First-time setup

```bash
# 1. Trust a local certificate authority (one-time, prompts for password/TouchID)
mkcert -install

# 2. Generate the cert (localhost + auth/api/app.lvh.me) used by Caddy
./setup-certs.sh

# 3. auth service: install PHP deps, generate its signing key, seed registration config
docker run --rm -v "$PWD/auth":/app -w /app composer:2 install --ignore-platform-req=ext-*
openssl genrsa -out auth/keys/private.key 2048 && openssl rsa -in auth/keys/private.key -pubout -out auth/keys/public.key
cp auth/registration.example.json auth/registration.json   # then fill client_id/deployment_id after Moodle registration

# 4. Start the stack (first boot installs Moodle ~1–2 min; the app container
#    runs `npm install` itself on first start, then the Vite dev server with HMR)
docker compose up -d

# 5. Watch it come up; wait for "ready to handle connections"
docker compose logs -f moodle
```

> The **app** service runs the **Vite dev server** (HMR — edit React and it hot-reloads,
> no rebuild). In prod the SPA is `vite build` → `./dist` uploaded to a GCS bucket
> behind the load balancer.

Then open **https://localhost** (Moodle) — you should get a clean padlock (no warning).
The tool is launched *from* Moodle; you don't visit `app.lvh.me` directly.

> If the browser still warns, you skipped or need to re-run `mkcert -install`, then fully
> quit and reopen the browser.

---

## Access

| | |
|---|---|
| Moodle (Platform) | https://localhost |
| Admin user | `admin` |
| Admin password | `Moodle123!` |
| Tool — auth | https://auth.lvh.me (LTI endpoints) |
| Tool — api | https://api.lvh.me (session API) |
| Tool — app | https://app.lvh.me (React UI) |

(Credentials are set in [docker-compose.yml](docker-compose.yml) — local lab only, not secrets.)

The Moodle External Tool must point at `https://auth.lvh.me/lti/login` + `/lti/launch`,
with **Launch container = New window** (the session cookie needs a first-party context).

---

## Common commands

```bash
docker compose ps                 # container status
docker compose logs -f moodle     # tail Moodle logs
docker compose down               # stop (keeps all data)
docker compose down -v            # stop AND wipe data (fresh Moodle)
docker compose up -d              # (re)start; applies compose changes

# run Moodle admin CLI, e.g. read/set a config value:
docker compose exec -T moodle php /var/www/html/admin/cli/cfg.php --name=timezone
```

---

## Project layout

```
.
├── docker-compose.yml   # Moodle + MariaDB + Caddy + redis + auth + api + app
├── setup-certs.sh       # regenerate the cert (per machine) → caddy/certs
├── caddy/               # Caddyfile + certs/ (TLS cert + key, gitignored)
├── auth/                # LTI service — PHP + packback; Dockerfile, public/, src/, keys/, registration
├── api/                 # session API — plain PHP; Dockerfile, public/, src/SessionStore.php
├── app/                 # React UI — Vite dev server locally (prod: build -> GCS bucket)
├── LTI-WEEKEND.md       # the LTI 1.3 learning curriculum
└── README.md            # this file
```

---

## Notes & gotchas

- **Certs are never committed.** `caddy/certs/*` is gitignored. Each machine runs `./setup-certs.sh`
  to mint its own cert (yours is signed by *your* mkcert CA and wouldn't be trusted elsewhere anyway).
- **`mkcert -install` must be run in your own terminal** — it needs an interactive sudo/TouchID
  prompt and can't be automated.
- **Image pins.** `mariadb` is pinned (was the floating `:lts` tag, which drifted to a version newer
  than Moodle certifies). `erseco/alpine-moodle` and `caddy` are less strictly pinned — pin them too
  if you want a fully reproducible reference environment.
- **`MOODLE_SITENAME` must have no spaces** — the image's install script doesn't quote it, so spaces
  crash the first-boot install.
- **Timezone.** Server default is set to `America/Chicago`. A `docker compose down -v` (fresh install)
  resets it to the image default (Europe/London); reset with:
  ```bash
  docker compose exec -T moodle php /var/www/html/admin/cli/cfg.php --name=timezone --set=America/Chicago
  ```

---

## Next steps

See [LTI-WEEKEND.md](LTI-WEEKEND.md). In short:

1. Create a test course + a student (Manual account, enrol as Student).
2. Register the **saltire** test tool in Moodle and do a zero-code launch to prove the platform config.
3. Build the Tool (login → launch → JWKS), then add Deep Linking, AGS (grades), and NRPS (roster).
