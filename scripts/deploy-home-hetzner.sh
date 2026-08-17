#!/usr/bin/env bash
# Deploy RPG Notes to home_hetzner (/opt/rpgnotes).
set -euo pipefail

REMOTE="${REMOTE:-home_hetzner}"
DEST="${DEST:-/opt/rpgnotes}"
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

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
DEFAULT_URI=https://rpg.kuura.art
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
