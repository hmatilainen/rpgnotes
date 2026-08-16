# Navigation & Sidebar Enhancements — Design

## Context

The site now has its first visual pass (Parchment & Ink theme, navbar,
collapsible sidebar) live against the real Forgotten Realms vault (428
notes, 90 session reports). This is the next round of UX polish: five
small, related changes to how the front page and sidebar behave.

## Goals

1. **Sidebar state persists across page loads.** Whatever folders a user
   manually expands/collapses stays that way as they navigate between
   pages, via `localStorage` — the one deliberate JavaScript addition to
   the site so far.
2. **The `Home` note (`/notes/home`) is hidden**, using the existing
   hidden-folder mechanism (no new code — a one-line config addition).
3. **Navbar "Home" item** — already satisfied by the existing site-title
   link to `/`. No change needed; documented here for completeness.
4. **The front page shows the newest session report in full**, not just
   as a title link — readers land on `/` and immediately see the latest
   session's actual content. Older reports remain listed below as title
   links, paginated as before.
5. **Previous/Next session navigation** — every report page (and the
   front page's featured report) gets links to the adjacent report by
   report number, skipping any gaps in numbering.

## Non-goals

- No general "hide any note via UI" feature — hiding is still a
  hardcoded config list, same mechanism as `A - GM`. An admin-configurable
  visibility system is Phase 2 territory.
- No search, no additional navbar links beyond what exists today.
- No change to the sidebar's default open/closed behavior for
  folders a user has never touched (top-level open, nested closed stays
  the default — `localStorage` only overrides folders the user has
  actually interacted with).
- No auto-expand-to-reveal-current-page behavior — that was considered
  and explicitly not chosen in favor of true persisted manual state.

## Design

### 1. Sidebar state persistence (JavaScript)

- `templates/partials/_sidebar.html.twig`'s `<details>` elements each get
  a `data-path` attribute holding the folder's full vault-relative path
  (e.g. `data-path="General/Provisions/Food"`), used as a stable
  `localStorage` key. Top-level folders keep their existing `open`
  attribute as the *default* (unchanged from the current visual-polish
  behavior) — JavaScript only overrides state for paths that have an
  explicit saved value.
- New `public/js/sidebar.js`, loaded via `<script defer src="/js/sidebar.js">`
  in `base.html.twig`:
  - On `DOMContentLoaded` (or as soon as it runs, since it's `defer`red
    and the sidebar HTML is already present by then), reads
    `localStorage['sidebar-state']` (a JSON object mapping path →
    `true`/`false`), and for every `<details data-path>` whose path has a
    saved value, sets `.open` to match it (overriding the server-rendered
    default).
  - Listens for the native `toggle` event on every `<details data-path>`
    in the sidebar and updates `localStorage['sidebar-state'][path] =
    details.open`, persisting on every user toggle.
  - A folder the user has never touched keeps using the server-rendered
    default (open for top-level, closed for nested) — there's no need to
    write an initial "default" entry into storage.
- No other JS on the site; no build step; a single small vanilla-JS file.

### 2. Hide the `Home` note

- `config/services.yaml`'s `app.vault.hidden_dirs` parameter gains one
  entry: `'Home.md'`. `VaultFileScanner::scan()` already compares a
  file's *top-level path segment* against this list case-insensitively —
  for a root-level file like `Home.md`, that segment is the filename
  itself (there's no folder to split on), which is exactly why it showed
  up as a "top-level folder" named `Home.md` in earlier inspection. No
  code changes: the existing mechanism already covers this case.
- After this change, `/notes/home` 404s and the note is fully absent from
  the sidebar and any wikilink resolution, identically to how `A - GM`
  content behaves today.

### 3. Navbar "Home" item

- No change. `templates/base.html.twig`'s existing
  `<a href="/">RPG Notes — Forgotten Realms</a>` already serves this
  purpose.

### 4. Front page shows the newest report in full

- `FrontPageController` fetches the single newest report (highest
  `reportNumber`) separately from the paginated list, and passes it to
  the template as `featuredReport` (only relevant/rendered on page 1).
- The paginated list query changes its offset calculation to always skip
  the featured report, regardless of page: `offset = (page - 1) *
  perPage + 1` (previously `(page - 1) * perPage`). This means the
  "second newest" report is always the first item in the list, on every
  page — there's no special-casing needed between page 1 and later pages,
  since the featured report never appears in the list query's result set
  at any offset.
- `countReports()`'s result is used to compute `totalPages` against
  `total - 1` (excluding the featured report) rather than `total`.
- `templates/front_page/index.html.twig`: when `page == 1` and
  `featuredReport` is not null, render the featured report's full HTML
  (title + `{{ featuredReport.html|raw }}`) above the existing report
  list, followed by the "← Previous session" link from Goal 5. On page
  2+, `featuredReport` is not fetched/rendered at all — only the list.

### 5. Previous/Next session navigation

- Two new `NoteRepository` methods:
  - `findPreviousReport(int $reportNumber): ?Note` — the report with the
    largest `reportNumber` strictly less than the given one (`ORDER BY
    reportNumber DESC, LIMIT 1`), so gaps in numbering are skipped
    automatically.
  - `findNextReport(int $reportNumber): ?Note` — the report with the
    smallest `reportNumber` strictly greater than the given one (`ORDER
    BY reportNumber ASC, LIMIT 1`).
- `NoteController` (which serves every note page, not just reports) looks
  up Previous/Next only when the note being viewed has a non-null
  `reportNumber`, and passes them to `note/show.html.twig`. Non-report
  notes get no such buttons — the template checks `note.reportNumber is
  not null` before rendering the navigation block.
- `templates/note/show.html.twig`: below the note's content (still inside
  `<article>` or immediately after it), a nav block with a "← Previous
  session" link (if `previousReport` is not null) and a "Next session →"
  link (if `nextReport` is not null) — either or both may be absent
  (report-1 has no Previous; the newest report's own page has no Next).
- `FrontPageController`'s featured-report block reuses
  `findPreviousReport()` for its own "← Previous session" link (no "Next"
  there, since the featured report is always the newest). This is the
  same repository method as report pages use, not a separate query.

## File structure

- Create: `public/js/sidebar.js`
- Modify: `templates/base.html.twig` — add `<script defer src="/js/sidebar.js">`.
- Modify: `templates/partials/_sidebar.html.twig` — add `data-path` to
  each `<details>`.
- Modify: `config/services.yaml` — add `'Home.md'` to
  `app.vault.hidden_dirs`.
- Modify: `src/Repository/NoteRepository.php` — add
  `findPreviousReport()`, `findNextReport()`, and `findNewestReport():
  ?Note` (highest `reportNumber`, `LIMIT 1`) — a dedicated, single-purpose
  method rather than repurposing `findReportsPaginated()` for this.
- Modify: `src/Controller/FrontPageController.php` — fetch the featured
  report, adjust list pagination offset/`totalPages` math, pass
  `featuredReport` and (when present) its `previousReport` to the
  template.
- Modify: `src/Controller/NoteController.php` — fetch
  `previousReport`/`nextReport` when the viewed note has a
  `reportNumber`, pass to the template.
- Modify: `templates/front_page/index.html.twig` — render the featured
  report block + its Previous link on page 1.
- Modify: `templates/note/show.html.twig` — render the Previous/Next nav
  block for report notes.
- CSS additions to `public/css/site.css` for the featured-report block
  and the Previous/Next nav buttons, styled consistently with the
  existing Parchment & Ink theme (no new colors — reuse the existing
  custom properties).

## Testing

- `NoteRepository::findPreviousReport()`/`findNextReport()`: integration
  tests against a small set of fixture reports with an intentional gap in
  numbering (e.g. reports 1, 2, 5), confirming gaps are skipped correctly
  and that the boundary cases (no previous for the lowest, no next for
  the highest) return `null`.
- `FrontPageController`: functional test confirming page 1 renders the
  featured report's full content and excludes it from the list below;
  page 2 renders no featured block and its list starts at the correct
  offset (no off-by-one against the new pagination formula).
- `NoteController`: functional test confirming a report note renders
  Previous/Next links when applicable, and a non-report note renders
  neither.
- The hidden-`Home.md` change is config-only; covered by re-running the
  existing `NoteIndexer` integration test suite against a fixture vault
  that includes a root-level file, confirming it's excluded — or,
  simpler, verified manually against the real synced vault (`/notes/home`
  404s, absent from the sidebar) since the underlying mechanism already
  has test coverage from Phase 1.
- `sidebar.js` is plain client-side JavaScript with no test framework in
  this project — verified manually: toggle a nested folder, reload the
  page, confirm it's still open; toggle it closed, navigate to a note
  page and back, confirm it's still closed; confirm an never-touched
  folder still uses today's server-rendered default.
