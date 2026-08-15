# RPG Notes — Phase 1: Docker Foundation + Read-Only Rendering Site

## Context

This is the first of three planned phases for a self-hosted web app that
publishes an Obsidian vault of RPG campaign notes ("Forgotten Realms")
online.

- **Phase 1 (this spec)**: local docker stack, GitHub-driven sync of the
  notes repo, markdown rendering with wikilink resolution, a front page
  listing session reports, and a sidebar for other notes. No authentication.
- **Phase 2 (future)**: admin bootstrap + invite-only player registration,
  enforcement of hidden-folder visibility (admin-only), redaction of links
  into hidden content for non-admins.
- **Phase 3 (future)**: in-app session note creation by players (pushes to
  GitHub), WhatsApp-only share links for published session notes.

Each phase gets its own spec and implementation plan. This document covers
Phase 1 only.

## Source material

The vault lives locally at
`/home/hannu-matilainen/Documents/obsidian/FG/Forgotten Realms` and is also
pushed to a **private** GitHub repository. Key structure:

- `Reports/<range>/Report-N <date> <title>.md` — session reports (Finnish),
  the "session notes" that populate the front page. Also contains one
  non-report summary file (`Tähän mennessä tapahtunutta.md`) that must be
  excluded from the session list.
- `People/`, `Locations/`, `Historical Entry/`, `General/`, `Languages/` —
  world reference notes, shown in the sidebar.
- `A - GM/` — GM-only content (plots, secrets, quests, organizations,
  items). **Hidden** from the start.
- `.obsidian/` — Obsidian config/plugin data, never indexed.
- `docs/` — unrelated local tooling directory, never indexed.
- Notes use Obsidian wikilinks: `[[Page]]`, `[[Path/Page]]`,
  `[[Path/Page|Display Text]]`, sometimes immediately followed by trailing
  text with no space (e.g. `[[Locations/Deerwater]]ista`).
- Notes have YAML frontmatter (e.g. `type: plot`).
- Some notes use Obsidian callout syntax (`> [!note] ...`), which in this
  vault has been used for GM-only asides.
- Some Reports contain `[img:NNNNNN]` placeholders with no backing image
  file anywhere in the vault (no attachments folder, no `![[...]]` embeds
  found) — these are leftover cruft, not a real feature.

## Goals

1. A local docker-compose stack that runs alongside the user's other local
   docker projects without port collisions.
2. Pushing to the notes GitHub repo results in the server picking up
   changes automatically via a GitHub Action calling a webhook.
3. Notes render as HTML with working wikilinks, on stable URLs derived from
   vault paths.
4. A front page lists session reports (Reports/Report-N ...) newest-first,
   paginated.
5. A sidebar shows the rest of the vault's folder structure for browsing.
6. `A - GM/` is fully excluded from the site (not indexed/rendered/linked)
   from day one — the *mechanism* for hiding folders is real, but Phase 1
   has no admin UI to toggle it (toggling is data-seeded via a fixture/config,
   not a UI feature yet).

## Non-goals (deferred to later phases)

- Any authentication, user accounts, or roles.
- Admin UI for toggling folder visibility (Phase 1 hides `A - GM/` via a
  hardcoded/seeded config value, not a UI).
- Showing hidden content to anyone, including admins (no admins exist yet).
- Players creating or editing notes.
- Share links.
- Real image/attachment rendering (no images exist in the vault currently;
  if that changes, revisit).

## Architecture

- **App**: Symfony (PHP), served via FrankenPHP (Symfony's official modern
  Docker setup — single container for PHP + HTTP).
- **Database**: PostgreSQL — stores the note index (see Data model).
- **Notes storage**: a private, persistent git clone of the notes GitHub
  repo, mounted as a docker volume. This is the source of truth for content;
  Postgres stores derived/indexed data (metadata + pre-rendered HTML), not
  the raw markdown as a separate copy of truth.
- **Sync trigger**: GitHub Action in the notes repo, on push to main,
  performs an HTTP POST to `https://<server>/webhook/sync` with a
  bearer-token shared secret (stored as a GitHub Actions secret and a
  server env var). For local Phase 1 development this endpoint is only
  reachable locally / manually curled — there's no public server yet.

### Docker services (ports to avoid collision with existing local containers
`bh-db-1` (3307), `peppol-service-app-1` (8080), `peppol-service-db-1`
(5433), `peppol-service-mailer-1` (1025/8025)):

| service | image/base            | container port | host port |
|---------|------------------------|-----------------|-----------|
| app     | FrankenPHP (Symfony)   | 80/443          | 8091      |
| db      | postgres:16            | 5432            | 5434      |

Exact port values are configurable via `.env` / `docker-compose.override.yml`
and can be revisited in the implementation plan if a collision is found at
build time.

## Ingestion & indexing

On webhook call (and on a manual CLI command for local dev, e.g.
`bin/console app:sync`):

1. `git pull` (or fetch + reset to origin) the local clone of the notes
   repo.
2. Walk the vault, skipping `.obsidian/`, `docs/`, non-`.md` files, and any
   folder marked hidden (Phase 1: `A - GM/`, hardcoded in config).
3. For each remaining `.md` file:
   - Parse and strip YAML frontmatter (not rendered, not currently used for
     anything user-facing in Phase 1 — parsed and discarded).
   - Strip Obsidian callout blocks (`> [!type] ...` and their continuation
     lines) entirely from the content before rendering.
   - Strip `[img:NNNNNN]` placeholders.
   - Render remaining CommonMark to HTML (tables, headers, emphasis, lists,
     blockquotes, etc. via `league/commonmark`).
   - Resolve wikilinks (see below) as part of rendering.
   - Derive: title (filename without extension), vault-relative path, slug
     (path-derived), top-level folder, and — if the filename matches
     `Report-(\d+) ...` — a `report_number` and a `session_date` parsed from
     the filename's date fragment.
   - Upsert a `Note` row keyed by vault path.
4. Delete `Note` rows for vault paths that no longer exist or now fall
   under a hidden folder.

This is a **full reindex** on every sync call (not an incremental diff) —
the vault is small enough (a few hundred files) that this is simple and
robust rather than premature optimization.

Rendered HTML is stored on the `Note` row (pre-rendered at ingest time), so
page requests are simple DB reads with no render-on-request cost.

## Wikilink resolution

Wikilink syntax handled: `[[Page]]`, `[[Path/Page]]`,
`[[Path/Page|Display]]`, including when immediately followed by trailing
non-space text (e.g. `[[Locations/Deerwater]]ista` → link text "Deerwater",
followed by literal "ista").

Resolution algorithm, run against the in-progress index (all non-hidden
notes from the current ingestion pass):

1. If the link target includes a path, look for an exact vault-path match
   (case-insensitive).
2. Otherwise (or if no exact path match), look for a filename-only match
   across all indexed notes.
   - If exactly one match: link to it.
   - If multiple matches: link to the first (stable ordering by path);
     this is a known limitation, not solved in Phase 1.
   - If no match, or the only match is in a hidden folder: render as plain
     text (the display text, or the raw target if no `|Display` given) —
     **not** a link.

## URL routing

`/notes/{vault-path-with-extension-stripped-and-slugified}`, mirroring
folder structure, e.g.:

- `Locations/Deerwater.md` → `/notes/locations/deerwater`
- `Reports/41-50/Report-41 20.2.1367 Matka Brokenstonen laaksoon.md` →
  `/notes/reports/41-50/report-41-20-2-1367-matka-brokenstonen-laaksoon`

Slugification: lowercase, non-alphanumeric runs (including Finnish
diacritics — transliterated, e.g. ä→a, ö→o) collapsed to single hyphens.

## Front page

- Query: `Note` rows where `report_number IS NOT NULL`, ordered by
  `report_number DESC`.
- Paginated, 20 per page.
- Each item: title (as derived above) linking to `/notes/...`, and the
  session date parsed from the filename.
- The non-report file (`Tähän mennessä tapahtunutta.md`) is excluded
  automatically since it won't match the `Report-N` filename pattern and
  gets no `report_number`.

## Sidebar

- Built from the `Note` index, grouped by top-level vault folder
  (`People`, `Locations`, `Historical Entry`, `General`, `Languages`, plus
  any others that appear — excluding `Reports` and hidden folders).
- Rendered as a collapsible tree matching subfolder structure within each
  top-level group.
- Present on every note page and on the front page, using a shared layout.

## Error handling

- Webhook endpoint: reject requests with missing/incorrect bearer token
  (401). Reject if git pull or reindex fails (500), logging the error;
  previously-indexed content remains served (no partial/broken state from a
  failed sync).
- Note page for an unresolvable slug: 404.
- Empty/malformed frontmatter: treated as absent, note still indexed.

## Testing

- Unit tests for: frontmatter stripping, callout stripping, `[img:...]`
  stripping, wikilink resolution (exact path, filename-only, ambiguous,
  hidden-target, unresolvable), slug generation, report-number/date parsing
  from filenames.
- Integration test for the sync command against a small fixture vault
  (checked into the test suite, not the real notes repo) covering: normal
  notes, a hidden-folder note (verify excluded), a report file (verify
  `report_number` set), a file deleted between runs (verify row removed).
- Feature/functional test hitting the webhook endpoint (auth rejection +
  success path) and rendered note/front-page routes.
