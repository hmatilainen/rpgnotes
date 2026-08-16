# Forms, Tables & Navbar Polish — Design

## Context

Phase 2 (admin/auth) added four pages — login, the navbar's auth controls,
`/admin/players`, and `/admin/hidden-paths` — with zero page-specific CSS.
They render as raw unstyled forms/tables, inheriting only the global
body/link/heading rules from the existing "Parchment & Ink" theme
established during the earlier visual-polish pass. This is a small,
contained styling pass extending that already-approved theme to these four
pages — no new visual direction, no structural or routing changes.

## Goals

1. **Navbar** becomes a proper flex layout: site title left, auth controls
   (login link, or "logged in as X" + admin link + logout button) right,
   vertically centered — currently they just stack inline with no
   separation.
2. **Forms** (login, add player, generate/regenerate invite, add hidden
   path) get consistent styling: labels above inputs, bordered inputs with
   a focus state, buttons styled to match the site's accent color instead
   of raw browser defaults.
3. **Players table** (`/admin/players`) gets bordered rows consistent with
   the existing `.report-list` pattern, and the invite-link cell —
   currently a full raw URL that would wrap awkwardly once styled — is
   truncated with CSS ellipsis and a `title` attribute showing the full
   URL on hover. The link itself and its `href` are unchanged; only the
   *displayed* text truncates.
4. **Hidden paths list** (`/admin/hidden-paths`) gets styled list items
   with borders between entries (matching `.report-list`) and a
   consistently-styled "Remove" button.

## Non-goals

- No new JavaScript. The invite-link truncation is CSS + a `title`
  attribute, not a "copy to clipboard" button (which would need JS this
  project doesn't otherwise have, beyond the existing sidebar-state
  script).
- No structural/markup changes beyond what's needed for the ellipsis
  truncation (adding a wrapping `<span>` with a fixed max-width, if
  needed) and no copy/wording changes anywhere.
- No changes to routes, controllers, or any behavior — this is a pure
  presentation pass, like the earlier visual-polish plan.
- No dark mode, no responsive/mobile-specific work beyond what falls out
  naturally from reusing the existing theme's approach.

## Design

### Navbar

`.navbar` becomes `display: flex; align-items: center;
justify-content: space-between;` so the title (`<a href="/">...`) and the
`.navbar-auth` block sit at opposite ends. `.navbar-auth` itself becomes
`display: flex; align-items: center; gap: 16px;` so its children (the
"Logged in as X" text, the optional Admin link, and the logout form/login
link) sit inline with even spacing instead of browser-default block/inline
stacking. The logout `<button>` inside `.navbar-logout-form` gets styled
to look like a plain text link (no button chrome), matching the other
navbar items, consistent with how it was already styled during the
Phase 2 CSRF fix — this task just confirms/extends that, not replaces it.

### Shared form styling

A `.form-group` wrapper (label + input stacked, `margin-bottom`) used
consistently in `login.html.twig`, `admin/players.html.twig`, and
`admin/hidden_paths.html.twig`'s add forms. Inputs get a `--color-border`
border, matching background/text colors from the existing theme, and a
`:focus` state using `--color-accent` for the border color (no color
outside the existing five custom properties — no new palette entries).
Buttons (submit buttons across all forms, including the per-row invite
and remove actions) share one `.btn` style: `--color-accent` background,
white/cream text, `--color-accent-hover` on hover, consistent padding —
replacing raw unstyled `<button>` elements site-wide on these four pages.

### Players table

`table` gets `border-collapse: collapse; width: 100%`, `th`/`td` get
padding and a `border-bottom` in `--color-border`, matching the visual
weight of `.report-list li`. The invite-link `<a>` gets wrapped in a
fixed-max-width container styled with `white-space: nowrap; overflow:
hidden; text-overflow: ellipsis;`, and the anchor tag gains a
`title="{{ url(...) }}"` attribute holding the full URL (browsers show
this as a tooltip on hover) — the visible link text truncates, the actual
`href` and click behavior are unchanged.

### Hidden paths list

`ul` becomes unstyled (`list-style: none; padding: 0`), each `li` gets a
`border-bottom` in `--color-border` and flex layout
(`display: flex; justify-content: space-between; align-items: center;`)
so the path text and the "Remove" button sit on the same line with space
between them, rather than the button trailing directly after the text.

## File structure

- Modify: `public/css/site.css` — all new rules; no new file.
- Modify: `templates/security/login.html.twig`,
  `templates/admin/players.html.twig`,
  `templates/admin/hidden_paths.html.twig` — wrap existing label/input
  pairs in `.form-group`, add `class="btn"` to buttons, add the `title`
  attribute + wrapping span to the invite-link cell.
- Modify: `templates/base.html.twig` — no structural change expected
  beyond what CSS class hooks already exist (`.navbar`, `.navbar-auth`,
  `.navbar-logout-form`/`.navbar-logout-button`) — verify during
  implementation whether any additional class hook is needed.

## Testing

Presentation-only, no new business logic — verified manually/visually
against the running app, consistent with how the earlier visual-polish
plan was verified:

- `/login`: form renders with styled inputs/labels, error message (if
  any) still shows, button uses the shared `.btn` style.
- Navbar on any page: title left, auth controls right, evenly spaced,
  both logged-out ("Log in" link) and logged-in (as player and as admin,
  confirming the conditional Admin link still only shows for
  `ROLE_ADMIN`) states.
- `/admin/players`: add-player form styled, table has visible row
  borders, a long invite URL truncates with an ellipsis and its full
  value shows on hover (inspect the rendered `title` attribute), the
  invite/regenerate button text is unchanged from Phase 2 (still keys off
  invite-token validity, not username — that logic is untouched).
- `/admin/hidden-paths`: add-path form styled, list items have visible
  separators, "Remove" button styled consistently with other buttons.
- Existing functional tests for these four pages/routes must keep passing
  unmodified — none of them assert on CSS classes or exact HTML structure
  beyond text content and specific `href`/`class` values already present
  (e.g. `report-nav-prev`), so adding CSS classes and a `title` attribute
  should not require test changes. Run the full suite to confirm.
