# Phase 3 — Player Session Reports — Design

## Context

Phase 1 (read-only vault rendering) and Phase 2 (admin auth, invites, hidden
paths) are done. Phase 3 adds the write path: a logged-in player publishes
session reports through the site, which pushes a new markdown file to GitHub
so the GM's Obsidian vault picks it up on pull.

## Goals

1. **Player-authored session reports** — any `ROLE_PLAYER` (admin inherits via
   role hierarchy) can write and publish a new report.
2. **GitHub push** — publish writes `Reports/{range}/Report-N {date} {title}.md`,
   commits, and pushes. GitHub remains source of truth.
3. **Postgres drafts** — unfinished reports saved server-side until publish.
4. **Dates** — in-game session date (filename + form) and real-world
   `published_at` in YAML frontmatter on app-published notes only.
5. **Share tokens** — public read-only `/share/{token}` URLs for WhatsApp.
6. **Manual WhatsApp share** — `wa.me` button with excerpt + share URL on
   publish success, note page, and front-page list. Reminder to share after
   publish.

## Non-goals

- Auto-posting to WhatsApp via Cloud API.
- Editing published reports in the app (GM edits in Obsidian).
- Markdown editor UI — plain textarea; wikilinks work if typed.
- Conflict merge UI — push failure shows an error; GM fixes on GitHub/Obsidian.
- `published_at` for legacy reports (only new app-published files).

## Sync model

**Inbound** (unchanged): `git fetch` + `reset --hard origin/HEAD` + full reindex.

**Outbound** (publish):

1. `git fetch` + `reset --hard origin/HEAD` (match remote before write)
2. Scan vault for max `Report-N` → allocate `N + 1`
3. Write file, `git add`, `commit`, `push`
4. Full reindex
5. Create `ShareToken` for the new note

On push failure: flash error, draft retained.

## File format (app-published reports)

```markdown
---
published_at: 2026-08-16T20:00:00+01:00
author: player_username
---

## Short session title

Body as the player typed it.
```

Filename: `Reports/{range}/Report-{N} {j.n.Y} {title}.md` (matches existing vault
convention).

## Data model

### `session_note_drafts`

- `id`, `author_id` (FK → users, unique — one draft per author)
- `title`, `session_date` (`date_immutable`), `body` (`text`)
- `updated_at`

### `share_tokens`

- `id`, `note_id` (FK → notes, unique — one token per note)
- `token` (unique, URL-safe random)
- `created_at`

### `notes` (modified)

- `published_at` (`datetime_immutable`, nullable) — from frontmatter on index

## Routes

| Path | Access | Purpose |
|------|--------|---------|
| `/reports/new` | `ROLE_PLAYER` | Draft form |
| POST `/reports/new` | `ROLE_PLAYER` | Save draft |
| POST `/reports/publish` | `ROLE_PLAYER` | Publish (git push + reindex) |
| `/reports/published` | `ROLE_PLAYER` | Success page after publish |
| `/share/{token}` | public | Read-only report via share token |

## UI

- English labels throughout.
- Navbar: "New session report" for players.
- After publish: success message, link to note, WhatsApp share button, reminder
  to share with the group.
- Share button on report note pages and front-page list rows (reports only).

## Security

- `ROLE_ADMIN` inherits `ROLE_PLAYER` via Symfony role hierarchy.
- `/share/{token}` shows report content only; minimal layout (no sidebar).
- Share tokens are unguessable (32 random bytes, hex-encoded).
