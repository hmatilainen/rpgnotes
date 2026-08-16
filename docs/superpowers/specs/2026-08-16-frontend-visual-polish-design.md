# Frontend Visual Polish — Design

## Context

Phase 1 (docker foundation + read-only rendering site) is live and now syncing
the real Forgotten Realms vault (428 notes, 90 session reports). The site is
functionally complete but visually bare: no stylesheet exists anywhere in the
project, there's no top navigation, and the sidebar is a single flat nested
`<ul>` that would render all 428 notes fully expanded at once.

This is a presentation-only pass — no controller, routing, or data changes.

## Goals

1. A cohesive visual style ("Parchment & Ink" — warm cream background, dark
   ink text, serif typography, muted brown/gold accents) applied site-wide.
2. A top navbar spanning the full page width, showing the site title ("RPG
   Notes — Forgotten Realms") linked to the front page (`/`).
3. Below the navbar, the existing two-column layout (sidebar + main content)
   is preserved — the sidebar does not run under the navbar.
4. The sidebar's folder tree becomes collapsible: top-level folders start
   expanded, nested subfolders start collapsed. No JavaScript — native
   `<details>`/`<summary>`. This closes the "collapsible tree" requirement
   from the original Phase 1 spec, which was never actually implemented.
5. Note pages don't render a duplicate title heading. Originally scoped
   as "every real note already opens with its own top-level heading, so
   just drop the template's `<h1>`" — that premise turned out to be false
   for 347 of 428 real notes (81%), which have no markdown heading at
   all. The actual behavior is conditional: the template renders
   `<h1>{{ note.title }}</h1>` only when the note's own HTML doesn't
   already start with an `<h1>`. See "Known limitation" below for the
   one case this doesn't fully cover.

## Non-goals

- No new pages, routes, or data changes.
- No JavaScript — the collapsible sidebar uses only `<details>`/`<summary>`.
- No dark-mode toggle or theme switching — Parchment & Ink is the only
  theme.
- No responsive/mobile-specific layout work beyond basic reasonable
  behavior (not a requirement the user asked for; not explicitly excluded
  either, but not a design target for this pass).

## Known limitation

69 of the 428 real notes contain their own mid-document `# Extras`-style
markdown heading, which CommonMark renders as an in-body `<h1>Extras</h1>`
partway through the note — not at the very start. Since the template's
conditional only checks whether the note's HTML *starts with* `<h1`, these
69 notes still end up with two `<h1>` elements on the page (the template's
title heading, plus the in-body one). This is a pre-existing shape in the
vault's own content, not something this pass introduced or worsened — those
same notes already rendered two `<h1>`s before this pass too, just from a
different cause (the template always added its own, unconditionally).

Accepted as a known limitation for now, out of scope for this
presentation-only pass. A real fix means either editing the 69 source
markdown files, or having `NoteIndexer` demote any in-body `<h1>` that
isn't the note's first rendered element to `<h2>` at ingest time — the
latter is an ingestion-pipeline change, not a template change, and belongs
in its own future task with its own test coverage.

## Visual design

- **Palette**: background `#f4e9d5` (warm cream/parchment), text `#4a3826`
  (dark ink brown), borders/rules `#c9b48a` (muted tan), links/accents
  `#8a6d3b` (brown-gold), hover state slightly darker than link color.
- **Typography**: `Georgia, 'Times New Roman', serif` throughout. Page/note
  titles larger and bold with a bottom rule; sidebar folder labels
  (`<summary>`) same family, slightly smaller, bold.
- **Navbar**: full-width bar at the very top, cream background with a
  bottom border matching the rule color, site title as a link, comfortable
  padding.
- **Sidebar**: fixed-ish width column on the left below the navbar, folder
  tree using `<details>`/`<summary>` for each folder level, notes as plain
  links indented under their folder.
- **Content area**: comfortable reading width (not full-bleed edge to
  edge), consistent spacing between paragraphs/headings, front-page report
  list and pagination controls styled consistently with the rest.

## File structure

- Create: `public/css/site.css` — single stylesheet, no build step,
  referenced directly.
- Modify: `templates/base.html.twig` — add `<link rel="stylesheet">` to
  `site.css`, add the navbar markup above the existing `.layout` div.
- Modify: `templates/partials/_sidebar.html.twig` — rewrite folder
  rendering to use `<details>`/`<summary>` (top-level folders default
  open via the `open` attribute, nested folders default closed).
- Modify: `templates/note/show.html.twig` — render `<h1>{{ note.title
  }}</h1>` only when `note.html|trim` does not already start with
  `<h1`, since roughly a fifth of real notes have no markdown heading of
  their own and still need the template's fallback title.
- `templates/front_page/index.html.twig` needs no structural changes —
  the new stylesheet's selectors (`.report-list`, `.pagination`, etc.)
  target its existing markup as-is.
- Modify: `tests/Functional/Controller/NoteControllerTest.php` — two
  cases, both asserting `assertSelectorCount(1, 'h1')` in addition to the
  title text check, so each actually pins its branch of the conditional:
  `testRendersExistingNoteWithOwnHeading` (fixture HTML starts with its
  own `<h1>`, template must not add a second one) and
  `testRendersExistingNoteWithoutOwnHeading` (fixture HTML has no
  heading, template must supply one).

## Testing

This is presentation-only (CSS + Twig markup), so there's no new business
logic to unit test. Verification is manual/visual against the running app:

- Front page: navbar renders, sidebar shows collapsible top-level folders
  (open) with nested subfolders (closed), report list and pagination are
  styled, no layout overlap between navbar/sidebar/content.
- A note page with nested subfolders in its own path (e.g. under
  `Locations/Settlements/...`): confirm the sidebar correctly expands to
  reveal deeply nested folders when clicked, and the note itself shows a
  single `<h1>` (from its own markdown), not two.
- `NoteControllerTest` needs the fixture update described above to keep
  passing (its `<h1>` assertion currently depends on the template-level
  heading being removed). `FrontPageControllerTest` and `SidebarBuilderTest`
  need no changes — neither asserts on heading markup. Run the full suite
  to confirm no other incidental regressions (e.g. a test asserting on
  exact HTML structure affected by the navbar/sidebar markup change).
