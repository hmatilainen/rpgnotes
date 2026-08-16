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
5. Note pages no longer render a duplicate heading (currently `{{
   note.title }}` in an `<h1>` *and* the note's own markdown `# Title` both
   render, producing two identical `<h1>`s) — drop the template's own
   `<h1>`, since every real note already opens with its own top-level
   heading.

## Non-goals

- No new pages, routes, or data changes.
- No JavaScript — the collapsible sidebar uses only `<details>`/`<summary>`.
- No dark-mode toggle or theme switching — Parchment & Ink is the only
  theme.
- No responsive/mobile-specific layout work beyond basic reasonable
  behavior (not a requirement the user asked for; not explicitly excluded
  either, but not a design target for this pass).

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
- Modify: `templates/note/show.html.twig` — remove the template's own
  `<h1>{{ note.title }}</h1>`, since the rendered note HTML already
  supplies its own top-level heading.
- `templates/front_page/index.html.twig` needs no structural changes —
  the new stylesheet's selectors (`.report-list`, `.pagination`, etc.)
  target its existing markup as-is.
- Modify: `tests/Functional/Controller/NoteControllerTest.php` —
  `testRendersExistingNote` currently sets fixture HTML with no heading
  of its own (`$note->setHtml('<p>A small settlement.</p>')`) and only
  passes today because the template's own `<h1>{{ note.title }}</h1>`
  supplies the `<h1>` the test's `assertSelectorTextContains('h1',
  'Deerwater')` checks. Once that template `<h1>` is removed, the
  fixture's stored HTML must supply its own heading instead — change the
  fixture to `$note->setHtml('<h1>Deerwater</h1><p>A small
  settlement.</p>')`, matching how a real note's rendered HTML actually
  looks (CommonMark renders the note's own `# Deerwater` into an `<h1>`
  before it's ever stored). The assertion itself does not need to change.

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
