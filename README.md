# RPG Notes

A self-hosted site that publishes an Obsidian vault of RPG campaign notes
(the "Forgotten Realms" vault, synced from a private GitHub repo). Session
reports show up on the front page newest-first, other notes are browsable
via a collapsible sidebar, and Obsidian wikilinks resolve into working
links. Admin accounts and invite-only player registration gate a small set
of hidden GM-only folders/files.

## Stack

Symfony 7.4 (PHP 8.3) on FrankenPHP, PostgreSQL 16, all via docker-compose.
No JavaScript beyond a single small file for remembering sidebar
expand/collapse state.

## Running locally

```bash
docker compose up -d --build
```

This starts two containers:

| service | purpose        | host port |
|---------|----------------|-----------|
| `app`   | FrankenPHP/Symfony | `8091` (site at http://localhost:8091) |
| `db`    | PostgreSQL 16  | `5434` |

First-time setup, once the containers are up:

```bash
docker compose exec app composer install
docker compose exec app bin/console doctrine:database:create --if-not-exists
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
```

For running the test suite, the `app_test` database also needs migrating:

```bash
docker compose exec app bin/console doctrine:database:create --env=test --if-not-exists
docker compose exec app bin/console doctrine:migrations:migrate --env=test --no-interaction
```

## Configuring the notes repo (`.env.local`)

The vault syncs from a **private** GitHub repo, so credentials go in a
gitignored `.env.local` file at the project root — never in the committed
`.env`:

```bash
# Personal fine-grained PAT, paired with your GitHub *username* (not
# "x-access-token" — that's only for GitHub App tokens, not personal PATs).
VAULT_REPO_URL=https://<your-github-username>:<PAT>@github.com/<owner>/<repo>.git

# Shared secret the GitHub Action sends as "Authorization: Bearer <this>"
# when calling the sync webhook. Pick your own value — don't use the
# committed dev default.
SYNC_WEBHOOK_SECRET=<pick-a-real-secret>
```

`docker-compose.yml` deliberately does **not** set `VAULT_REPO_URL` or
`SYNC_WEBHOOK_SECRET` as container environment variables — if it did, that
would silently override anything in `.env.local` (real process env vars
always win over `.env*` files in Symfony). Only `.env.local` should carry
these two values.

**After creating or editing `.env.local`, restart the `app` container**
(`docker compose restart app`) — this sandbox/setup has shown the
container's file view lag behind host edits, and a restart forces a fresh
read.

## Syncing notes

Notes sync from GitHub either via a manual command or a webhook a GitHub
Action can call after every push:

```bash
docker compose exec app bin/console app:sync
```

`POST /webhook/sync` with header `Authorization: Bearer <SYNC_WEBHOOK_SECRET>`
does the same thing over HTTP, for wiring up a GitHub Action.

Every sync is a **full reindex** — it clones/pulls the repo, walks every
`.md` file, and re-renders each note. Folders/files marked hidden (see
below) still get indexed, just flagged so only admins can see them.

## Admin & players

### Create the admin account

```bash
docker compose exec app bin/console app:create-admin
```

Prompts for a username and password. This is the only account created
outside the invite flow.

### Invite a player

Log in as admin, go to **Admin → Players** (`/admin/players`):

1. Add a player by a display name (just a label for your own reference —
   the player picks their own login username when they register).
2. Click **Generate invite link**, copy the URL, and send it to the player
   yourself (Discord, WhatsApp, whatever) — the app never sends email.
3. The player visits the link and sets their own username + password.

Invite links are single-use and expire after 2 weeks. If a player forgets
their password, generate a new invite link for them the same way — it
doubles as a password reset (it invalidates their old link and lets them
set new credentials).

### Hide a folder or file

Log in as admin, go to **Admin → Hidden paths** (`/admin/hidden-paths`):
add any vault-relative path — a whole folder (`A - GM`) or a single file
(`Locations/Deerwater.md`), at any depth. Hidden content is only visible
to logged-in admins; everyone else (including logged-in players) gets a
plain 404, indistinguishable from a note that doesn't exist. Wikilinks
pointing into hidden content never become clickable, even for admins.

Changes take effect on the **next sync**, not immediately — re-run
`app:sync` (or wait for the webhook) after adding or removing a hidden
path.

## Running tests

```bash
docker compose exec app bin/phpunit
```

## Routes

| Path | Purpose |
|------|---------|
| `/` | Front page — newest session report in full, older reports listed/paginated below |
| `/notes/{slug}` | Any note, mirroring its vault folder path |
| `/login`, `/logout` | Auth |
| `/register/{token}` | Invite-link registration (and password reset) |
| `/admin` | Admin dashboard |
| `/admin/players` | Manage players and invite links |
| `/admin/hidden-paths` | Manage which folders/files are hidden |
| `/reports/new` | Write and publish session reports (players) |
| `/share/{token}` | Public read-only share link for a report |
| `/webhook/sync` (POST) | Triggers a sync, bearer-token authenticated |

## Deployment

Example layout: a VPS with Apache terminating TLS and proxying to the app
container on `127.0.0.1:8091`.

### One-time server setup

1. **DNS** — `A` record `notes.example.com` → your server IP (e.g. `203.0.113.10`).
2. **Secrets** — on the server, copy `.env.prod.example` to `.env.local` and
   fill in `APP_SECRET`, `VAULT_REPO_URL`, `SYNC_WEBHOOK_SECRET`,
   `POSTGRES_PASSWORD`, and `DATABASE_URL` (see vault section above).
   Set `DEFAULT_URI=https://notes.example.com`.
3. **Deploy** from your machine (configure `REMOTE` / `DEST` for your SSH host
   and install path):
   ```bash
   REMOTE=your-server ./scripts/deploy-home-hetzner.sh
   ```
   By default the deploy script keeps the server's existing `.env.local`; set
   `UPLOAD_ENV_LOCAL=1` only if you intend to overwrite it from your laptop.
4. **Create admin** (once):
   ```bash
   ssh your-server 'cd /opt/rpgnotes && docker compose -f docker-compose.yml -f docker-compose.prod.yml -p rpgnotes exec app bin/console app:create-admin'
   ```
5. **Apache + TLS** on the server — copy `deploy/apache/` vhost files, set
   `ServerName` to your hostname, enable sites, then obtain a certificate:
   ```bash
   sudo cp /opt/rpgnotes/deploy/apache/*.conf /etc/apache2/sites-available/
   # Edit ServerName and paths in the copied files for your domain.
   sudo a2ensite your-http-site.conf
   sudo systemctl reload apache2
   sudo certbot certonly --apache -d notes.example.com
   sudo a2ensite your-ssl-site.conf
   sudo systemctl reload apache2
   ```

### Vault webhook (Obsidian → GitHub → site)

Add `docs/vault-github-action-sync.yml` to your **vault** repo as
`.github/workflows/sync-rpgnotes.yml`. Set GitHub Actions secrets:

| Secret | Value |
|--------|--------|
| `RPGNOTES_SYNC_URL` | `https://notes.example.com/webhook/sync` |
| `RPGNOTES_SYNC_SECRET` | same as `SYNC_WEBHOOK_SECRET` on the server |

Every push to the vault repo triggers a pull + reindex on the server.

### Redeploy after code changes

```bash
REMOTE=your-server ./scripts/deploy-home-hetzner.sh
```

## Roadmap

- **Phase 1** — docker foundation, GitHub-synced read-only rendering site. ✅ Done.
- **Phase 2** — admin account, invite-only player registration, admin-only
  hidden content. ✅ Done.
- **Phase 3** — player session report publishing with GitHub push-back, share
  tokens, and manual WhatsApp share buttons. ✅ Done.
- **Phase 4** — MCP server + logged-in “AI access” page (copy-paste connector
  setup for Claude, ChatGPT, Mistral, Cursor). Spec:
  `docs/superpowers/specs/2026-08-16-phase4-mcp-ai-access-design.md`
- **Later / unscoped idea** — an MCP server so players can point Claude (or
  another MCP-aware AI) at the site to browse content directly, gated
  behind login once it exists. Raised in passing, not committed to any
  phase yet.
