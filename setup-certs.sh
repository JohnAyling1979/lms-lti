#!/usr/bin/env bash
# Generate a locally-trusted TLS cert for the HTTPS frontend (Caddy).
# Prereqs: brew install mkcert  &&  mkcert -install   (the latter needs sudo/TouchID, run once)
set -euo pipefail
cd "$(dirname "$0")/certs"
mkcert -cert-file localhost.pem -key-file localhost-key.pem localhost 127.0.0.1 ::1
echo "✅ Wrote certs/localhost.pem + certs/localhost-key.pem"
echo "   If your browser still warns, run once:  mkcert -install"
