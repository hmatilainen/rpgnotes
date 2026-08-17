#!/usr/bin/env bash
# Deploy RPG Notes to a remote server (/opt/rpgnotes by default).
# Copy scripts/deploy.local.example to scripts/deploy.local for your SSH host and URL.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

if [[ -f "${SCRIPT_DIR}/deploy.local" ]]; then
  # shellcheck source=/dev/null
  source "${SCRIPT_DIR}/deploy.local"
fi

REMOTE="${REMOTE:-your-server}"
DEST="${DEST:-/opt/rpgnotes}"
DEFAULT_URI="${DEFAULT_URI:-https://notes.example.com}"

echo "→ Syncing files to ${REMOTE}:${DEST}"
ssh "${REMOTE}" "mkdir -p ${DEST}"
rsync -avz --delete \
  --exclude '.git' \
  --exclude 'var/' \
  --exclude 'vendor/' \
  --exclude '.env.local' \
  "${PROJECT_ROOT}/" "${REMOTE}:${DEST}/"

if [[ "${UPLOAD_ENV_LOCAL:-}" == "1" && -f "${PROJECT_ROOT}/.env.local" ]]; then
  echo "→ Uploading local .env.local (UPLOAD_ENV_LOCAL=1)"
  scp "${PROJECT_ROOT}/.env.local" "${REMOTE}:${DEST}/.env.local"
else
  echo "→ Keeping server .env.local (set UPLOAD_ENV_LOCAL=1 to overwrite from local)"
fi

echo "→ Ensuring production secrets exist on server"
ssh "${REMOTE}" "cd ${DEST} && touch .env.local && if ! grep -q '^POSTGRES_PASSWORD=' .env.local 2>/dev/null; then
  PW=\$(openssl rand -hex 16)
  SECRET=\$(openssl rand -hex 32)
  cat >> .env.local <<EOF
APP_SECRET=\${SECRET}
DEFAULT_URI=${DEFAULT_URI}
POSTGRES_PASSWORD=\${PW}
DATABASE_URL=postgresql://app:\${PW}@db:5432/app?serverVersion=16&charset=utf8
EOF
  echo 'Generated APP_SECRET, POSTGRES_PASSWORD, DATABASE_URL on server.'
fi"

echo "→ Building and starting containers"
ssh "${REMOTE}" "cd ${DEST} && docker compose -f docker-compose.yml -f docker-compose.prod.yml -p rpgnotes up -d --build"

echo "→ Running migrations"
ssh "${REMOTE}" "cd ${DEST} && docker compose -f docker-compose.yml -f docker-compose.prod.yml -p rpgnotes exec -T app bin/console doctrine:migrations:migrate --no-interaction"

echo "→ First vault sync"
ssh "${REMOTE}" "cd ${DEST} && docker compose -f docker-compose.yml -f docker-compose.prod.yml -p rpgnotes exec -T app bin/console app:sync" || true

echo "Done. Site should answer on http://127.0.0.1:8091 on the server."
echo "Configure Apache + TLS with deploy/apache/*.conf and certbot if not already done."
