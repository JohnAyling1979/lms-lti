# lms-lti — local LTI 1.3 lab

A self-contained local environment for learning and building **LTI 1.3 / LTI Advantage**.
Moodle runs in Docker as the **Platform** (the LMS), served over trusted HTTPS, ready to
launch a **Tool** (the app you build).

The weekend learning curriculum lives in [LTI-WEEKEND.md](LTI-WEEKEND.md).

---

## Architecture

```
  browser ──HTTPS:443──► Caddy ──HTTP:8080──► Moodle ──► MariaDB
                          (TLS term.)         (Platform)   (DB)
```

- **Caddy** terminates TLS with a locally-trusted mkcert certificate and reverse-proxies to Moodle.
  Moodle is never exposed directly to the host — only through Caddy.
- **Moodle** (`erseco/alpine-moodle`) listens on `8080` internally (defined by the image, not us).
  `SSLPROXY=true` makes it trust Caddy's `X-Forwarded-Proto` so its wwwroot stays `https://localhost`.
- **MariaDB** holds Moodle's data.

---

## Prerequisites

- **Docker** (with Compose v2) — running
- **mkcert** — `brew install mkcert`

---

## First-time setup

```bash
# 1. Trust a local certificate authority (one-time, prompts for password/TouchID)
mkcert -install

# 2. Generate the localhost + tool.localhost cert used by Caddy
./setup-certs.sh

# 3. Tool: install deps, generate its signing key, seed local registration config
docker run --rm -v "$PWD/tool":/app -w /app composer:2 install --ignore-platform-req=ext-*
openssl genrsa -out tool/keys/private.key 2048 && openssl rsa -in tool/keys/private.key -pubout -out tool/keys/public.key
cp tool/registration.example.json tool/registration.json   # then fill client_id/deployment_id after Moodle registration

# 4. Start the stack (first boot installs Moodle — ~1–2 min)
docker compose up -d

# 5. Watch it come up; wait for "ready to handle connections"
docker compose logs -f moodle
```

Then open **https://localhost** — you should get a clean padlock (no warning).

> If the browser still warns, you skipped or need to re-run `mkcert -install`, then fully
> quit and reopen the browser.

---

## Access

| | |
|---|---|
| Moodle URL | https://localhost |
| Admin user | `admin` |
| Admin password | `Moodle123!` |

(Credentials are set in [docker-compose.yml](docker-compose.yml) — local lab only, not secrets.)

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
├── docker-compose.yml   # MariaDB + Moodle + Caddy
├── Caddyfile            # TLS termination + reverse proxy to moodle:8080
├── setup-certs.sh       # regenerate the localhost cert (per machine)
├── certs/               # TLS cert + key (gitignored — never committed)
├── LTI-WEEKEND.md       # the LTI 1.3 learning curriculum
└── README.md            # this file
```

---

## Notes & gotchas

- **Certs are never committed.** `certs/*` is gitignored. Each machine runs `./setup-certs.sh`
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
