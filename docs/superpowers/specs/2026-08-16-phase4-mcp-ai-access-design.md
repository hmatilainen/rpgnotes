# Phase 4 — MCP AI Access — Design

## Context

Phase 3 shipped player accounts, session report publishing, share links, and
production at https://rpg.kuura.art. Players want to connect Claude, ChatGPT,
Mistral, Cursor, etc. to campaign notes without scraping HTML.

This phase adds a **remote MCP server** on the existing Symfony app plus a
**logged-in “AI access” page** where players copy a short snippet and follow
platform-specific steps.

## Goals

1. **One place after login** — generate a personal token, copy connector URL
   (+ token where needed), read instructions for major AI clients.
2. **Remote MCP over HTTPS** at `/mcp` (Streamable HTTP) — no scraping.
3. **Helpful content for models** — markdown bodies, metadata, wikilinks,
   search; extra index work on the server is acceptable.
4. **Same visibility rules as the website** — players never see hidden/GM
   content; admins with an admin token do.
5. **Hobby-scale simplicity** — personal access tokens first; OAuth only if a
   platform refuses token/header auth.

## Non-goals

- AI publishing or editing vault content via MCP (website only).
- Wikilinks into hidden notes becoming clickable for admins in MCP (match web).
- Official listing in Claude/ChatGPT connector directories (nice later).
- stdio MCP for players (dev/debug only).

---

## Player experience — `/ai-access`

Available to any logged-in `ROLE_PLAYER` (admin inherits).

### Page layout

1. **Your connector**
   - Connector URL (copy button): `https://rpg.kuura.art/mcp`
   - Personal access token — show once on generate; copy button; **Regenerate**
     (invalidates previous token)
   - Short note: “Treat this like a password. Anyone with it can read everything
     you can read on the site.”

2. **How to connect** — tabs or accordion per platform:

| Platform | Free tier? | How auth works on our side | Player steps (summary) |
|----------|------------|----------------------------|----------------------|
| **Claude** (claude.ai / Desktop / mobile) | Yes — **1** custom connector on Free | URL + **Request headers**: `Authorization: Bearer <token>` (Claude “request headers” for connectors; beta) | Settings → Connectors → Add custom connector → paste URL → Advanced / Request headers → add Authorization header → Connect |
| **ChatGPT** | **No** — Plus, Pro, Business, Enterprise, Edu | URL + **Token** auth in connector dialog (or OAuth later) | Settings → Apps & Connectors → enable Developer mode → Add connector → paste URL → Authentication: Token → paste token |
| **Mistral Le Chat** | Yes | URL + token or OAuth (use token if supported; else OAuth in 4b) | Sidebar → Intelligence → Connectors → Custom MCP → paste URL → connect |
| **Cursor** | Cursor product subscription (not RPG Notes) | `mcp.json` snippet with `url` + `headers` | Paste JSON snippet from our page into Cursor MCP settings |

3. **What you can ask** — example prompts:
   - “Summarize the last three session reports.”
   - “What do we know about [[Locations/Deerwater]]?”
   - “Who is Malekith and where did we last see them?”

4. **Troubleshooting** — token regenerated? update connector; site down? wait;
   still on Free Claude with another custom connector? remove one first.

Keep instructions on the page — do not rely on players reading README.

### Copy-paste snippets (generated server-side with real token)

**Claude request header** (single line for Advanced settings):

```
Authorization: Bearer <token>
```

**Cursor `mcp.json`** fragment:

```json
{
  "mcpServers": {
    "forgotten-realms": {
      "url": "https://rpg.kuura.art/mcp",
      "headers": {
        "Authorization": "Bearer <token>"
      }
    }
  }
}
```

**ChatGPT** — URL field only in UI; token pasted in Authentication → Token.

Regenerate token updates the page; player must update connector if they
regenerate.

---

## Auth model

### Personal access token (Phase 4a)

New entity `user_api_tokens`:

| Column | Notes |
|--------|--------|
| `user_id` | FK, unique — one active token per user |
| `token_hash` | SHA-256 of raw token (never store plaintext) |
| `created_at` | |
| `last_used_at` | nullable, updated on MCP auth |

Raw token: 32 random bytes, hex-encoded (64 chars). Shown once on generate.

Symfony: separate firewall `mcp` for `^/mcp` with custom authenticator
reading `Authorization: Bearer …`, resolving user, granting roles from user.

Revoke: regenerate replaces hash; optional admin revoke later.

### OAuth (Phase 4b — only if needed)

If ChatGPT/Mistral reject token auth in practice, add OAuth 2.1 with PKCE
(`league/oauth2-server-bundle`): authorize with existing username/password,
token endpoint, MCP resource metadata. Player flow becomes “paste URL, click
Connect, log in” — no token copy for those clients.

Bearer tokens remain for Claude/Cursor.

---

## Rich content for AI (server investment)

Today `Note` stores **HTML only**. MCP should return **markdown** and **structured metadata**.

### Index-time additions (`notes` table)

| Column | Type | Purpose |
|--------|------|---------|
| `body_markdown` | `text` | Stripped frontmatter/callouts/images; what players wrote |
| `wikilinks` | `json` | List of `{path, slug, title}` for **visible** targets only |

Populate in `NoteIndexer` from vault file (same pass as HTML). Wikilinks:
parse `[[...]]` from markdown, resolve against visible `WikilinkIndex` only
(hidden targets omitted, matching web).

### Search

PostgreSQL `tsvector` on `title + body_markdown` (Finnish + simple config).
GIN index. Player MCP search excludes `hidden = true`.

### Tool response shape (consistent JSON)

Every note tool returns:

```json
{
  "slug": "reports/81-90/report-90-back-in-everlund",
  "vault_path": "Reports/81-90/Report-90 …",
  "title": "…",
  "report_number": 90,
  "session_date": "1367-08-16",
  "published_at": "2026-08-16T19:00:00+01:00",
  "markdown": "…",
  "url": "https://rpg.kuura.art/notes/…",
  "share_url": "https://rpg.kuura.art/share/…",
  "wikilinks": [{"path": "Locations/Deerwater", "slug": "…", "title": "…"}],
  "prev_report": 89,
  "next_report": null
}
```

HTML is not sent to MCP clients.

---

## MCP server

### Stack

- `symfony/mcp-bundle` + official `mcp/php-sdk`
- HTTP transport: Streamable HTTP at `/mcp`
- `config/packages/mcp.yaml`: `allowed_hosts: [rpg.kuura.art]`, instructions block
- Apache already proxies to FrankenPHP; no new public port

### Server instructions (shown to models)

Short static text, e.g.:

> Forgotten Realms D&D campaign notes. Session reports are in Finnish under
> Reports/. Use list_session_reports and get_session_report for sessions.
> Reference lore is in folders like People/, Locations/. Wikilinks use
> `[[Folder/Name]]` syntax.

### Tools (Phase 4a)

| Tool | Description |
|------|-------------|
| `get_site_overview` | Campaign name, newest report summary, folder list, how notes are organized |
| `list_session_reports` | Paginated: number, title, dates, slug, urls |
| `get_session_report` | By `report_number` or `slug` — full markdown + metadata |
| `get_note` | Any non-report note by `slug` or `vault_path` |
| `browse_vault` | Folder tree (sidebar shape), optional `folder` filter |
| `search_notes` | Full-text query, optional `folder`, `limit`; reports + lore |

### Tools (Phase 4b — optional)

| Tool | Description |
|------|-------------|
| `follow_wikilink` | Resolve `[[path]]` to note content |
| `list_reports_since` | Reports after a given report number or in-game date |

### Resources (optional 4b)

`rpgnotes://note/{slug}` — markdown resource for clients that prefer resources over tools.

---

## Security

- MCP firewall: valid token → user roles (`ROLE_PLAYER` / `ROLE_ADMIN`).
- All tool queries filter `hidden = false` unless `ROLE_ADMIN`.
- Rate limit `/mcp` (e.g. 60 req/min per token).
- No write tools in v1.
- Token page: CSRF on generate/regenerate; HTTPS only in production.

---

## Implementation slices

### 4a — MVP (player copy-paste works for Claude + Cursor)

1. Migration: `body_markdown`, `wikilinks`, `user_api_tokens`; search column/index
2. `NoteIndexer` fills markdown + wikilinks
3. `McpNoteService` — shared logic for tools (visibility, search, DTOs)
4. `symfony/mcp-bundle`, `/mcp` endpoint, PAT authenticator
5. Six tools listed above
6. `/ai-access` Twig page with token generate + platform tabs + snippets
7. Navbar link “AI access” for logged-in players
8. Tests: token auth, hidden note 404-equivalent, tool returns markdown

### 4b — Polish

- `follow_wikilink`, MCP resources
- OAuth if token auth insufficient for ChatGPT/Mistral in testing
- Screenshots on `/ai-access`

### 4c — Later

- Connector directory submissions
- Admin “revoke player token” in admin UI

---

## Platform notes for the help page (keep updated)

These change often — verify before each release:

- **Claude Free**: custom remote connectors allowed; **max 1** connector.
  Connection is from Anthropic’s cloud → server must be public HTTPS.
- **ChatGPT**: Developer mode + custom connectors require **paid** plan (not Free).
- **Mistral Le Chat**: custom MCP connectors on **all plans including Free**.
- **Cursor**: uses local `mcp.json`; remote URL + headers works; Cursor subscription separate from RPG Notes.

When Anthropic’s “request headers” for connectors is unavailable on an account,
fall back to OAuth (4b) for Claude.

---

## Routes

| Path | Access | Purpose |
|------|--------|---------|
| `/ai-access` | `ROLE_PLAYER` | Token + instructions + snippets |
| POST `/ai-access/token` | `ROLE_PLAYER` | Generate/regenerate token |
| `/mcp` | Bearer PAT | MCP Streamable HTTP endpoint |

---

## Success criteria

- Logged-in player opens `/ai-access`, copies snippet, connects Claude Free in
  under five minutes.
- Asking Claude “what happened in report 90?” uses MCP tools, not page HTML.
- Hidden GM notes never appear in search or get_note for player tokens.
- After Obsidian push + webhook sync, new notes appear in MCP without redeploy.
