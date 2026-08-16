# Frontend Visual Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the site a cohesive "Parchment & Ink" visual style, a full-width top navbar, a collapsible sidebar folder tree, and remove the duplicate `<h1>` on note pages — presentation-only, no controller/routing/data changes.

**Architecture:** One new plain CSS file (no build step) linked from the base Twig layout, a navbar block added above the existing sidebar+content layout, `_sidebar.html.twig` rewritten to use native `<details>`/`<summary>` for collapsible folders, and `note/show.html.twig` losing its redundant title heading.

**Tech Stack:** Twig templates, plain CSS (no preprocessor/build step), Symfony 7.4 app already running via docker-compose (app on `localhost:8091`).

Spec: [docs/superpowers/specs/2026-08-16-frontend-visual-polish-design.md](../specs/2026-08-16-frontend-visual-polish-design.md)

## Global Constraints

- Palette: background `#f4e9d5`, text `#4a3826`, borders `#c9b48a`, accent/links `#8a6d3b`, link hover `#6b5129`.
- Typography: `Georgia, 'Times New Roman', serif` throughout.
- Layout: full-width navbar at the very top; sidebar does NOT run under the navbar (starts below it); existing two-column sidebar+content structure otherwise unchanged.
- Navbar content: site title "RPG Notes — Forgotten Realms", linked to `/`.
- Sidebar folders are collapsible via native `<details>`/`<summary>` only — no JavaScript. Top-level folders default open (`open` attribute); nested subfolders default closed.
- Note pages must render exactly one `<h1>` (from the note's own rendered markdown), not two.
- No new routes, controllers, or database changes. No new composer/npm dependencies.
- Docker stack is already running (`docker compose up -d` from repo root); PHPUnit runs via `docker compose exec app bin/phpunit`.

---

## File Structure

```
public/css/site.css                              (new)
templates/base.html.twig                          (modified — <link>, navbar, sidebar include depth)
templates/partials/_sidebar.html.twig              (modified — details/summary)
templates/note/show.html.twig                      (modified — drop template <h1>)
tests/Functional/Controller/NoteControllerTest.php (modified — fixture HTML gets its own <h1>)
```

---

### Task 1: Stylesheet + navbar

**Files:**
- Create: `public/css/site.css`
- Modify: `templates/base.html.twig`

**Interfaces:**
- Produces: `public/css/site.css`, referenced by every page via `<link rel="stylesheet" href="/css/site.css">` in `base.html.twig`. CSS class names other tasks/templates rely on: `.navbar`, `.layout`, `.sidebar`, `.content`, `.report-list`, `.report-date`, `.pagination` (already used by `front_page/index.html.twig`, untouched by this plan).

- [ ] **Step 1: Write the stylesheet**

`public/css/site.css`:
```css
:root {
    --color-bg: #f4e9d5;
    --color-text: #4a3826;
    --color-border: #c9b48a;
    --color-accent: #8a6d3b;
    --color-accent-hover: #6b5129;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--color-bg);
    color: var(--color-text);
    font-family: Georgia, 'Times New Roman', serif;
    line-height: 1.6;
}

a {
    color: var(--color-accent);
    text-decoration: none;
}

a:hover {
    color: var(--color-accent-hover);
    text-decoration: underline;
}

.navbar {
    background: var(--color-bg);
    border-bottom: 2px solid var(--color-border);
    padding: 16px 24px;
}

.navbar a {
    font-size: 22px;
    font-weight: bold;
    color: var(--color-text);
}

.navbar a:hover {
    color: var(--color-accent);
    text-decoration: none;
}

.layout {
    display: flex;
    align-items: flex-start;
}

.sidebar {
    width: 260px;
    flex-shrink: 0;
    padding: 20px;
    border-right: 1px solid var(--color-border);
    min-height: calc(100vh - 65px);
}

.sidebar ul {
    list-style: none;
    margin: 0;
    padding-left: 14px;
}

.sidebar > ul {
    padding-left: 0;
}

.sidebar li {
    margin: 4px 0;
}

.sidebar details > summary {
    cursor: pointer;
    font-weight: bold;
    padding: 2px 0;
}

.sidebar details > summary:hover {
    color: var(--color-accent);
}

.content {
    flex: 1;
    padding: 24px 40px;
    max-width: 780px;
}

.content h1 {
    border-bottom: 2px solid var(--color-border);
    padding-bottom: 8px;
}

.report-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.report-list li {
    padding: 8px 0;
    border-bottom: 1px solid var(--color-border);
    display: flex;
    justify-content: space-between;
    align-items: baseline;
}

.report-date {
    color: var(--color-accent);
    font-size: 0.9em;
    white-space: nowrap;
    margin-left: 16px;
}

.pagination {
    margin-top: 20px;
    display: flex;
    gap: 16px;
    align-items: center;
}
```

- [ ] **Step 2: Add the navbar and stylesheet link to the base layout**

Modify `templates/base.html.twig` to:
```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{% block title %}RPG Notes{% endblock %}</title>
    <link rel="stylesheet" href="/css/site.css">
</head>
<body>
    <header class="navbar">
        <a href="/">RPG Notes — Forgotten Realms</a>
    </header>
    <div class="layout">
        <nav class="sidebar">
            {% include 'partials/_sidebar.html.twig' with { node: sidebar, depth: 0 } %}
        </nav>
        <main class="content">
            {% block body %}{% endblock %}
        </main>
    </div>
</body>
</html>
```
(Note the added `depth: 0` in the sidebar include — Task 2 depends on this being present, since `_sidebar.html.twig` will use `depth` to decide whether a folder starts open or closed.)

- [ ] **Step 3: Verify visually**

Run: `curl -s -o /dev/null -w '%{http_code}' http://localhost:8091/css/site.css`
Expected: `200`

Run: `curl -s http://localhost:8091/ | grep -c 'class="navbar"'`
Expected: `1`

- [ ] **Step 4: Commit**

```bash
git add public/css/site.css templates/base.html.twig
git commit -m "Add Parchment & Ink stylesheet and top navbar"
```

---

### Task 2: Collapsible sidebar folder tree

**Files:**
- Modify: `templates/partials/_sidebar.html.twig`

**Interfaces:**
- Consumes: `depth` variable passed in from `base.html.twig`'s Task-1 include (`depth: 0` at the root) and from this template's own recursive include.
- No change to `SidebarBuilder`/`SidebarNode` (`src/Service/Sidebar/`) — this task is template-only; `node.folders` and `node.notes` keep the exact same meaning as before.

- [ ] **Step 1: Rewrite the sidebar partial to use details/summary**

`templates/partials/_sidebar.html.twig`:
```twig
<ul>
    {% for folder in node.folders %}
        <li>
            <details{% if depth == 0 %} open{% endif %}>
                <summary>{{ folder.name }}</summary>
                {% include 'partials/_sidebar.html.twig' with { node: folder, depth: depth + 1 } %}
            </details>
        </li>
    {% endfor %}
    {% for note in node.notes %}
        <li><a href="/notes/{{ note.slug }}">{{ note.title }}</a></li>
    {% endfor %}
</ul>
```

- [ ] **Step 2: Run the existing sidebar integration test**

Run: `docker compose exec app bin/phpunit tests/Integration/Service/Sidebar/SidebarBuilderTest.php`
Expected: PASS (this test exercises `SidebarBuilder`/`SidebarNode` directly, not the Twig template, so it should be unaffected — confirms Task 2 didn't accidentally touch the PHP tree-building logic).

- [ ] **Step 3: Verify visually against the real site**

Run: `curl -s http://localhost:8091/notes/people/malekith | grep -c '<details'`
Expected: a number greater than `0` (top-level folders like People, Locations, General, Languages, Historical Entry each render as a `<details>` element).

Run: `curl -s http://localhost:8091/notes/people/malekith | grep -A1 '<details open>' | head -4`
Expected: at least one `<details open>` present (a top-level folder), confirming top-level folders default open.

- [ ] **Step 4: Commit**

```bash
git add templates/partials/_sidebar.html.twig
git commit -m "Make sidebar folder tree collapsible (top-level open, nested closed)"
```

---

### Task 3: Remove duplicate note-page heading

**Files:**
- Modify: `templates/note/show.html.twig`
- Modify: `tests/Functional/Controller/NoteControllerTest.php`

**Interfaces:**
- No new interfaces — this task only removes markup and updates one existing test fixture's stored HTML to match how real notes actually render (their own markdown already contains a top-level heading).

- [ ] **Step 1: Update the fixture HTML in the existing test first**

Modify `tests/Functional/Controller/NoteControllerTest.php`, change:
```php
        $note->setHtml('<p>A small settlement.</p>');
```
to:
```php
        $note->setHtml('<h1>Deerwater</h1><p>A small settlement.</p>');
```
(This matches how a real note's stored HTML looks in production — `NoteIndexer`'s CommonMark conversion turns the note's own `# Deerwater` into an `<h1>` before it's ever stored on the `Note` entity. The test's assertion, `assertSelectorTextContains('h1', 'Deerwater')`, does not need to change.)

- [ ] **Step 2: Run the test to confirm it still fails for the right reason before the template change**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/NoteControllerTest.php`
Expected: PASS at this point — the fixture now supplies its own `<h1>`, and the template *also* still renders one (two `<h1>` elements exist momentarily), so `assertSelectorTextContains('h1', 'Deerwater')` matches the first one either way. This step is a checkpoint, not a strict RED — the real change is Step 3.

- [ ] **Step 3: Remove the template's own heading**

Modify `templates/note/show.html.twig` to:
```twig
{% extends 'base.html.twig' %}

{% block title %}{{ note.title }} — RPG Notes{% endblock %}

{% block body %}
    <article>
        {{ note.html|raw }}
    </article>
{% endblock %}
```
(Removed the `<h1>{{ note.title }}</h1>` line — the `<article>` wrapper stays.)

- [ ] **Step 4: Run the test again to confirm it still passes with exactly one heading**

Run: `docker compose exec app bin/phpunit tests/Functional/Controller/NoteControllerTest.php`
Expected: PASS (2 tests). The `<h1>` now comes solely from the fixture's own stored HTML (mirroring production, where it comes solely from the note's own rendered markdown).

- [ ] **Step 5: Verify against a real note that a single `<h1>` now renders**

Run: `curl -s http://localhost:8091/notes/people/malekith | grep -c '<h1'`
Expected: `1` (previously `2`, before this task).

- [ ] **Step 6: Run the full test suite**

Run: `docker compose exec app bin/phpunit`
Expected: all tests pass (same count as before this plan, plus no new failures).

- [ ] **Step 7: Commit**

```bash
git add templates/note/show.html.twig tests/Functional/Controller/NoteControllerTest.php
git commit -m "Remove duplicate <h1> on note pages"
```

---

## Self-Review Notes

- **Spec coverage:** Parchment & Ink palette/typography (Task 1) · full-width navbar linking to `/` (Task 1) · sidebar below navbar, not underneath it (Task 1's layout is unchanged from the existing `.layout`/`.sidebar`/`.content` flex structure, navbar sits outside `.layout` entirely) · collapsible sidebar, top-level open/nested closed (Task 2) · single `<h1>` on note pages (Task 3) — every spec goal has a task.
- **Placeholder scan:** no TBD/TODO; every step has literal file content or an exact command.
- **Type consistency:** the `depth` variable is introduced in Task 1's `base.html.twig` change and consumed/propagated in Task 2's `_sidebar.html.twig` rewrite — same name, same starting value (`0`), no mismatch. `SidebarNode`/`SidebarBuilder` PHP interfaces are untouched by both tasks, confirmed via Task 2 Step 2's unmodified integration test.
