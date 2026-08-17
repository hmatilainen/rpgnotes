# RPG Notes

A self-hosted site that publishes an Obsidian vault of RPG campaign notes.
Session reports show up on the front page newest-first, other notes are 
browsaeble via a collapsible sidebar, and Obsidian wikilinks resolve into 
working links. Admin accounts and invite-only player registration gate a 
small set of hidden GM-only folders/files.

Just a hobby-project for my own amusement, created entirely with Cursor 
as I didn't want to spend weeks churning out relatively simple code to 
get this done asap. I still thought I'd make this public, in case someone
else is trying to solve the same issue (whatever it is). 

**Live demo:** [rpg.kuura.art](https://rpg.kuura.art) — a Forgotten Realms
campaign vault (read-only for visitors; log in only if you have an account).

![RPG Notes front page — featured session report and sidebar](docs/demo-home.png)

## How it works

1. **Publish your Obsidian vault to GitHub** — use a Git plugin in Obsidian
   to pull, commit, and push notes to a repository. Create a personal
   access token (PAT) with read/write access to that repo, and add a small
   GitHub Action (see [Auto-sync on vault push](#auto-sync-on-vault-push-github-actions))
   that calls the RPG Notes webhook after every push.
2. **Install this app on a server** — Docker on a VPS is enough; Apache or
   another reverse proxy terminates TLS and proxies to the app container.
3. **Point the app at your vault repo** — in `.env.local`, set
   `VAULT_REPO_URL` (with your PAT) and `SYNC_WEBHOOK_SECRET` (shared with
   the GitHub Action).

**Day to day (GM in Obsidian):** edit notes → commit → push → GitHub →
Action hits `/webhook/sync` → the server pulls and reindexes. Your players
see the updates on the site.

**Session reports (players on the site):** an invited player writes a
report in the browser; the app commits it to the vault repo on GitHub. Your
next Git pull in Obsidian brings that report back into your local vault.

## Session reports (`REPORTS_FOLDER`)

Session reports are **not** mixed into the sidebar tree. They live under a
configurable top-level folder in your vault (default `Reports`), appear on
the site home page (newest first), and are recognized by a strict filename
pattern.

Set the folder name in `.env` or `.env.local`:

```bash
REPORTS_FOLDER=Reports
```

Use a single top-level folder name — no slashes. Player publishing, report
number allocation, and sidebar exclusion all use this setting. The
`Report-{number} …` filename pattern is still required.

### Vault layout (default `REPORTS_FOLDER=Reports`)

```
Reports/                   # or your REPORTS_FOLDER value
  1-10/
    Report-1 20.2.1367 Short session title.md
    Report-2 16.8.1367 Another title.md
  11-20/
    Report-11 …
  21-30/
    …
```

- **Range subfolders** — ten reports per folder: `1-10`, `11-20`, `21-30`, …
  (report #41 → `Reports/41-50/`).
- **Filename** — `Report-{number} {in-game date} {title}.md`
  - `{number}` — session number, used for ordering and “report #N” on the site.
  - `{in-game date}` — optional but recommended: `D.M.YYYY` (e.g. `16.8.1367`).
    Older vault files with Finnish weekday/month names in the filename still
    index as reports, but without a parsed in-game date.
  - `{title}` — short session title; unsafe path characters are stripped when
    players publish via the site.

Example path:

`Reports/41-50/Report-41 16.8.1367 Matka laaksoon.md`

### Player-published reports

When a player publishes from `/reports/new`, the app:

1. Allocates the next report number by scanning existing `Report-*.md` files
   under your `REPORTS_FOLDER`.
2. Writes the file under the correct range folder with the filename above.
3. Adds YAML frontmatter (`published_at`, `author`) and a `## Title` heading
   in the body.
4. Commits and pushes to your vault repo (your PAT must allow write access).

That Git push triggers the vault Action → site webhook, so the report appears
on the home page without a manual sync.

### GM-authored reports in Obsidian

You can add or edit `Report-*.md` files directly in Obsidian and push like
any other note. Follow the same folder and filename conventions so the site
lists them as session reports. Non-matching `.md` files elsewhere in the
vault become regular notes in the sidebar.

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

# Top-level vault folder for session reports (default Reports).
REPORTS_FOLDER=Reports
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

Notes sync from GitHub in two ways: run a command yourself, or let a GitHub
Action on your **vault** repo call the site webhook after every push.

### Manual sync

```bash
docker compose exec app bin/console app:sync
```

### Auto-sync on vault push (GitHub Actions)

This is the usual setup: Obsidian → Git push to your vault repo → GitHub
Action → `POST /webhook/sync` on the RPG Notes server → site reindexes.

**On the RPG Notes server** (once):

1. Set `SYNC_WEBHOOK_SECRET` in `.env.local` to a long random string (see
   vault config section above). Restart the `app` container after editing.
2. Your public site must be reachable at `https://your-domain/webhook/sync`
   (Apache proxies to the Docker app).

**In your Obsidian vault GitHub repo** (not this `rpgnotes` app repo):

1. Copy `docs/vault-github-action-sync.yml` from this project to your vault
   repo as `.github/workflows/sync-rpgnotes.yml` and commit to `master` or
   `main`.
2. Open the vault repo on GitHub → **Settings** → **Secrets and variables** →
   **Actions** → **New repository secret**:
   | Secret name | Value |
   |-------------|--------|
   | `RPGNOTES_SYNC_URL` | `https://your-domain/webhook/sync` |
   | `RPGNOTES_SYNC_SECRET` | **exact same string** as `SYNC_WEBHOOK_SECRET` on the server |
3. Push any change to `master`/`main` on the vault repo. Under **Actions** you
   should see “Sync RPG Notes site” run and complete green.
4. Optional smoke test without a vault push:
   ```bash
   curl -fsS -X POST "https://your-domain/webhook/sync" \
     -H "Authorization: Bearer YOUR_SYNC_WEBHOOK_SECRET"
   ```
   A JSON body like `{"updated":525,"deleted":0}` means success.

The webhook runs `git pull` on the server vault clone and a full reindex —
the same work as `bin/console app:sync`. Player-published session reports
that push to the vault repo will also trigger this, so the site updates
after publish.

Every sync walks every `.md` file and re-renders each note. Folders/files
marked hidden (see below) still get indexed, just flagged so only admins can
see them.

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
3. **Deploy** — copy `scripts/deploy.local.example` to `scripts/deploy.local` (gitignored)
   with your SSH host and public URL, then deploy:
   ```bash
   ./scripts/deploy.sh
   ```
   You can still override per run: `REMOTE=your-server ./scripts/deploy.sh`.
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

### Redeploy after code changes

```bash
./scripts/deploy.sh
```

The vault auto-sync workflow lives in your **Obsidian vault repo** — see
[Auto-sync on vault push](#auto-sync-on-vault-push-github-actions) above.
