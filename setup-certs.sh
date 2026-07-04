#!/usr/bin/env bash
# Generate a locally-trusted TLS cert for the HTTPS frontend (Caddy).
# Prereqs: brew install mkcert  &&  mkcert -install   (the latter needs sudo/TouchID, run once)
set -euo pipefail
cd "$(dirname "$0")/caddy/certs"
# localhost -> Moodle (platform); *.lvh.me -> the tool (auth + app), which need a
# real multi-label domain so a Domain=lvh.me session cookie can be shared across
# subdomains (Domain=localhost is rejected by browsers — localhost is a TLD).
mkcert -cert-file localhost.pem -key-file localhost-key.pem localhost auth.lvh.me api.lvh.me app.lvh.me 127.0.0.1 ::1
echo "✅ Wrote caddy/certs/localhost.pem + caddy/certs/localhost-key.pem"
echo "   If your browser still warns, run once:  mkcert -install"
