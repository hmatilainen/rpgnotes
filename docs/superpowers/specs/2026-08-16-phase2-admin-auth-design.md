# Phase 2 — Admin & Invite-Only Access — Design

## Context

Phase 1 (docker foundation + read-only rendering site) and its follow-up
polish passes are live: the site publishes the real Forgotten Realms vault
(428 notes, 90 session reports) with no authentication, and hidden content
(`A - GM`, `Home.md`) is fully excluded from indexing via a static config
list (`app.vault.hidden_dirs` in `config/services.yaml`) — there is no
admin who could view it anyway.

This is Phase 2, as originally scoped: an admin account, invite-only player
registration, and admin-only visibility for hidden content — replacing the
static hidden-folder config with an admin-editable list that also supports
hiding individual files, not just whole folders.

Phase 3 (player-authored session notes, WhatsApp share links, and the
previously-discussed MCP server for AI access) remains out of scope here.

## Goals

1. **Admin account**, created via a console command at deploy time — not
   through the invite flow.
2. **Invite-only player registration** via an admin web UI: admin adds a
   player by a label, generates a single-use invite link (valid 2 weeks),
   and shares it manually (Discord, WhatsApp, however). The player uses
   the link to set their own username and password. The same mechanism
   (admin regenerates an invite for an existing player) doubles as
   password reset — there's no separate "forgot password" flow.
3. **Admin-only visibility for hidden content.** Hidden folders/files are
   now indexed (not skipped entirely, as in Phase 1) so an admin can
   browse them directly, but remain invisible — including via direct
   URL guessing — to anyone without `ROLE_ADMIN`, logged-in players
   included.
4. **Admin-editable hidden-path list**, replacing the static config.
   Supports hiding individual files as well as whole folders, at any
   depth in the vault (not just top-level, which is all Phase 1
   supported). Seeded from today's static list (`A - GM`, `Home.md`) at
   migration time, after which the config entry is removed.

## Non-goals

- Player-authored session notes, GitHub push-back, WhatsApp share links —
  all Phase 3.
- The MCP server / AI-browsing idea discussed separately — depends on this
  phase but isn't part of it.
- Email anywhere in the system. No email collection, no email-based
  password reset, no notification emails. Invite links are shared
  manually by the admin through whatever channel they choose.
- Wikilinks into hidden content becoming real clickable links for admins.
  They render as plain text for every viewer, admin included — the only
  way to reach hidden content is browsing directly (sidebar, when logged
  in as admin) or a direct URL. This was an explicit simplification to
  avoid rendering/storing two HTML versions per note.
- Any change to how logged-in players experience the site beyond a
  login/logout UI. A registered player sees exactly what an anonymous
  visitor sees — the account exists so Phase 3 has something to build on,
  not because Phase 2 itself unlocks anything for players.
- "Remember me" / persistent login, rate limiting on login attempts, CSRF
  hardening beyond what Symfony's form login provides by default —
  standard security-component defaults are enough for this scale and user
  base.

## Data model

### `User` entity (new)

One entity for both roles — not separate `Admin`/`Player` tables.

- `id`
- `label` (string) — admin-facing display name. For the admin's own
  account, this can just be their username. For a player, this is set by
  the admin when creating the pending invite and never changes; it's for
  the admin's own reference, distinct from whatever username the player
  eventually picks.
- `username` (string, nullable, unique when set) — null until the player
  completes registration via their invite link. Set immediately for the
  bootstrap admin account.
- `passwordHash` (string, nullable) — null until registration completes.
- `role` (string: `ROLE_ADMIN` | `ROLE_PLAYER`)
- `inviteToken` (string, nullable, unique when set) — a random opaque
  token embedded in the invite URL. Null once consumed (or if never
  invited yet).
- `inviteTokenExpiresAt` (datetime, nullable) — set to `now + 2 weeks`
  whenever a new invite token is generated. A token past this timestamp
  is treated as invalid, same as a null/already-consumed one.

Generating a new invite for a player who already has an unused token
overwrites `inviteToken`/`inviteTokenExpiresAt` — the old link stops
working immediately (there is deliberately never more than one valid
invite per player at a time).

### `HiddenPath` entity (new)

- `id`
- `path` (string, unique) — vault-relative, e.g. `A - GM` (a folder) or
  `Locations/Deerwater.md` (a single file). No `type` column needed —
  matching logic (below) treats folders and files identically.

Seeded via migration with the two paths currently hardcoded in
`config/services.yaml`'s `app.vault.hidden_dirs` (`A - GM`, `Home.md`),
after which that config parameter is deleted and
`App\Service\Vault\NoteIndexer`/`VaultFileScanner` stop taking a
`hiddenTopLevelDirs` constructor argument sourced from config — they
query `HiddenPath` instead (see below).

### `Note` entity — new field

- `hidden` (bool, default `false`) — set at indexing time based on
  whether the note's path (or any ancestor directory) matches a
  `HiddenPath` entry. Unlike Phase 1 (where hidden notes were never
  indexed at all), hidden notes now get a full `Note` row like everything
  else, just flagged.

## Hidden-content matching

Phase 1's `VaultFileScanner` only compared a file's **top-level** path
segment against the hidden list, which was sufficient for whole-top-level
folders (`A - GM`) and root-level files (`Home.md`, via the existing quirk
where a root file's own filename *is* its top-level segment) but can't
express "hide this one nested file" or "hide this one nested folder."

New matching rule, evaluated per vault file during indexing: a file is
hidden if its own vault-relative path, or any ancestor directory's path,
case-insensitively equals a `HiddenPath.path` entry. Concretely, for
`Locations/Settlements/Silverymoon.md`, the candidate paths checked
against the hidden list are `Locations/Settlements/Silverymoon.md`,
`Locations/Settlements`, and `Locations` — a match on any of them hides
the file.

This replaces `VaultFileScanner`'s current skip-at-scan-time behavior:
since hidden notes are now indexed (not skipped), `VaultFileScanner`
no longer filters by hidden-ness at all — `.obsidian` and `docs` remain
hardcoded exclusions (never real content, never toggleable), but
everything else gets scanned and indexed, with `NoteIndexer` setting the
`hidden` flag per the matching rule above rather than omitting the file.

## Enforcement

- **`NoteController`**: a note with `hidden = true` returns a plain 404
  for any request without `ROLE_ADMIN` — indistinguishable from a note
  that doesn't exist. Admins get the normal 200 response.
- **`SidebarBuilder`**: `findAllForSidebar()` excludes hidden notes unless
  the current user has `ROLE_ADMIN`, in which case they're included
  (visually unmarked — no special styling needed beyond what the folder
  tree already shows).
- **`FrontPageController`**: `findNewestReport()`/`findReportsPaginated()`
  exclude hidden reports unless the viewer is admin.
- **Wikilink rendering**: unchanged in spirit from Phase 1 — a hidden note
  is absent from the `WikilinkIndex` used at render time (built once, at
  sync time, without viewer context), so any link pointing at it renders
  as plain text for every viewer, admin included. This is deliberate (see
  Non-goals) — the alternative (dual rendering per viewer role) was
  explicitly rejected for this phase.

## Auth & invite flow

Symfony Security component, session-based form login.

- `GET/POST /login` — username + password. Failure: generic "invalid
  username or password," no field-specific hints, no user enumeration.
- `POST /logout`
- `/admin/*` routes require `ROLE_ADMIN` (Symfony access control), 403 or
  redirect-to-login for anyone else, consistent with normal Symfony
  security behavior.

**Admin bootstrap**: `bin/console app:create-admin` prompts for a
username and password (or takes them as arguments), creates the one
`ROLE_ADMIN` `User` row with `username`/`passwordHash` set immediately —
no invite token involved, since this is the very first account.

**Admin UI — `/admin/players`**: lists all `User` rows with
`role = ROLE_PLAYER` (label, username if registered, invite
status/expiry). An "Add player" form takes just a `label` and creates a
`User` row with `role = ROLE_PLAYER`, no username/password/token yet. A
"Generate invite link" button (relabeled "Regenerate" once one's already
been issued) sets a new token + 2-week expiry and shows the resulting
`/register/{token}` URL for the admin to copy and send manually.

**Registration — `GET/POST /register/{token}`**: public route, but only
functionally reachable with a token that matches some `User.inviteToken`
and hasn't expired. An invalid/expired/already-consumed token (including
one that simply doesn't match any row) shows a plain "This invite link is
no longer valid" message — no distinction between the three cases, to
avoid leaking which. A valid token shows a form to set username +
password; on submit, sets those fields on the matched `User` row and
clears `inviteToken`/`inviteTokenExpiresAt` (single use). Username
uniqueness is validated with a normal form error, not a crash. The form
is identical whether this is the player's first registration or a
password reset via a regenerated invite — both fields are always blank
and both are always set on submit, so a returning player can change their
username as well as their password if they want to; there's no
pre-filling or "password only" variant of this form.

**Admin UI — `/admin/hidden-paths`**: lists current `HiddenPath` entries;
a form to add a new path (free-text, vault-relative — the admin is
expected to type e.g. `Locations/Deerwater.md` correctly; no
autocomplete/browser in this phase) and a remove action per entry.
Adding/removing takes effect on the next sync (`app:sync` / webhook), not
retroactively on already-indexed data until that sync runs — same
"changes apply on next sync" behavior the rest of the indexing pipeline
already has.

**Navbar**: anonymous visitors see a "Log in" link. Logged-in users see
"Logged in as {username}" plus "Log out." Admins additionally see an
"Admin" link to a small `/admin` dashboard linking to `/admin/players` and
`/admin/hidden-paths`.

## File structure (indicative — finalized in the implementation plan)

- New: `src/Entity/User.php`, `src/Entity/HiddenPath.php`
- New: `src/Repository/UserRepository.php`,
  `src/Repository/HiddenPathRepository.php`
- Modify: `src/Entity/Note.php` (add `hidden` field)
- New migration(s): create `users` and `hidden_paths` tables, add
  `notes.hidden`, seed `hidden_paths` from the current static config.
- New: `src/Command/CreateAdminCommand.php`
- Modify: `src/Service/Vault/VaultFileScanner.php` (drop hidden-dir
  filtering, keep `.obsidian`/`docs` exclusion)
- Modify: `src/Service/Vault/NoteIndexer.php` (set `Note.hidden` via the
  ancestor-path matching rule against `HiddenPath`, no longer receives a
  `hiddenTopLevelDirs` constructor argument)
- Modify: `config/services.yaml` (remove `app.vault.hidden_dirs`,
  `NoteIndexer`'s argument wiring for it)
- New: `config/packages/security.yaml` (Symfony Security configuration —
  form login, access control for `/admin`, password hasher)
- New: `src/Controller/SecurityController.php` (login/logout)
- New: `src/Controller/RegistrationController.php` (`/register/{token}`)
- New: `src/Controller/Admin/PlayerController.php`
  (`/admin/players`)
- New: `src/Controller/Admin/HiddenPathController.php`
  (`/admin/hidden-paths`)
- Modify: `src/Controller/NoteController.php`,
  `src/Controller/FrontPageController.php` (hidden-content filtering per
  viewer role)
- Modify: `src/Service/Sidebar/SidebarBuilder.php`,
  `src/Repository/NoteRepository.php` (viewer-role-aware queries)
- New templates: `templates/security/login.html.twig`,
  `templates/registration/register.html.twig`,
  `templates/admin/players.html.twig`,
  `templates/admin/hidden_paths.html.twig`,
  `templates/admin/dashboard.html.twig`
- Modify: `templates/base.html.twig` (navbar login/logout/admin links)

## Error handling

- Invalid/expired/already-used invite token → plain "no longer valid"
  message, no distinction between the underlying reasons.
- Duplicate username at registration → validation error, not a crash.
- Failed login → generic message, no user enumeration.
- Hidden note requested by non-admin (including a guessed URL) → plain
  404, identical to a genuinely nonexistent note.
- Non-admin hitting an `/admin/*` route → standard Symfony
  access-denied handling (redirect to login if anonymous, 403 if logged
  in as a non-admin).

## Testing

- Integration tests for the ancestor-path hidden-matching logic (a file
  directly named in `HiddenPath`, a file under a hidden folder at
  various depths, a file that partially matches a hidden path's prefix
  as a string but not as a path segment — e.g. `Locations2/...` must NOT
  be hidden by a `HiddenPath` entry of `Locations`).
- Integration tests for invite token validation: valid/unexpired,
  expired, already-consumed (null token), never-existed.
- Functional tests: login success/failure, logout, `/admin/*` access
  control (anonymous → redirect, player → 403, admin → 200),
  registration with a valid token (sets credentials, consumes token) and
  an invalid one (shows the "no longer valid" message, does not touch any
  `User` row), hidden-note 404 for anonymous/player vs 200 for admin,
  hidden report excluded from the front page for non-admins vs included
  for admin.
- Functional tests for the two admin UI pages: adding a player, adding a
  hidden path, removing a hidden path, generating/regenerating an invite
  link.
