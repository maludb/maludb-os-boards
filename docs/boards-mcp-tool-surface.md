# Boards — MCP tool surface

2026-10-10 · **Phase 1, for the checkpoint.** These are the read tools that answer every question in `docs/boards-design.md` §7
(Q1–Q18 and A1–A6), plus the two K7 shares and the resolvers the kernel's actions server names.

Writes are never here. They are the action tools the kernel's Actions MCP builds from `docs/boards-action-manifest.md`.

**Every row a tool returns comes from an `mcp_*` view or a `bd_*` read function** (db/012, db/013). A board is read **search → region**,
never whole: there is no tool that returns every item of a board (decision record §3.6, R8).

## Conventions

**The servers** (`mcp-and-api.md`):
- Python 3 + FastMCP (`mcp>=1.2,<2`), streamable HTTP at `/mcp`, bound to `127.0.0.1`.
- One systemd unit each: `boards-records-mcp` on `MCP_RECORDS_PORT` 8849, `boards-activity-mcp` on 8850.
- `mcp/db.py` and `mcp/server_common.py` are the kit's, already here.

**The caller.** Every query runs through `db.fetch_scoped()` with `app.member_id` set **transaction-locally** from the verified bearer.
The `mcp_*` views and the `bd_*` functions then decide the rows and answer as the caller.

**Tool shape:**
- Tool names are snake_case.
- Every tool takes one `params` object: Pydantic v2, `extra="forbid"`, `str_strip_whitespace=True`, a described `Field` on every field.
- Every list tool takes `limit` (1–100, default 25) and, where it pages, `offset` (0–10,000).
- Every tool carries `readOnlyHint: true` and `openWorldHint: false`.
- Every result is a JSON string (`db.to_json`), except `board_read_region` and `frame_read`, which answer the SVG dialect inside a small
  JSON envelope.

**Values:**
- A board is named by its uuid or its title: `board` resolves a title with `find_boards`, and two matches answer `ambiguous`.
- An item, a comment or a version is an id.
- Times are ISO 8601 with the zone.
- Coordinates are canvas units. The tools give both an item's **centre** (`cx`, `cy` — what the actions take) and its box (`x1`, `y1`,
  `x2`, `y2`, absolute).

**Errors are answers, not exceptions.** A tool that cannot answer returns `{"ok": false, "error": {"code", "message", "retry": bool}}`. It
never raises, except for the run-facts gate's own refusal, which is the contract's ToolError.

| Code | Means |
|---|---|
| `not_found` | no such record, **or one the caller may not see**. The same words either way, so a tool never confirms that a private board exists |
| `not_allowed` | the record is visible but the tool is someone else's: the usage report is the admin's, the dispatches the admin's or the asker's own |
| `invalid_argument` | a parameter failed validation; `fields` names it |
| `ambiguous` | a name matched several records; `candidates[]` (≤ 6, id + label) so the agent asks one question |
| `too_large` | the answer would pass the tool's cap (a region over 400 items or 64 kB, an outline over 256 kB): narrow the box, or read a frame |

**Words used below** (db/006, db/012; design §3):

| Word | Means |
|---|---|
| `view`, `comment`, `edit`, `full` | the caller's level on the board, `bd_board_level()`: the highest of owner, admin, a member/agent row, a department row, `everyone`, a business or shipped template — capped by the role |
| `insider` | `bd_is_member_here()`: admitted (any role, agents included) |
| `own` | the caller's own: notifications, tokens, trail, dispatches they asked for |
| a right | `bd_has_right(…)`: `boards.admin`, `agents.manage`, `settings.manage` … (db/004) |
| `kernel` | the kernel's own token (`kernel.{exp}.{app}.{nonce}.{hmac}`): `app_roles` and the two shares, nothing else |

**An agent is a member**, with the same functions and no special case. In a person's chat turn (the command bar, a mention), the kernel
runs the agent *as the agent*. It sees what the agent's member id may see (D11). A mention of an agent who cannot see the board made no
dispatch (db/011), so a turn never starts on a board the agent cannot read.

**The run-facts gate** (the kit's `server_common.py`): an agent's run token is admitted to exactly the tools the kernel's run facts name for
this endpoint, and refused every tool when the kernel cannot be reached (fail closed). A person's token is admitted to every tool except
`app_roles` and the shares. The kernel's token is admitted to those three alone, and a share runs as the consumer's agent named in
`X-OS-Consumer-Agent` (K26), never as anyone an argument names.

## The two servers

| | Records | Activity |
|---|---|---|
| FastMCP name | `boards_records_mcp` | `boards_activity_mcp` |
| Database role | `boards_records_ro` (the `mcp_*` views and the `bd_*` read functions only) | `boards_activity_ro` (`mcp_activity_log`, `mcp_members`, `mcp_departments`, `mcp_boards`) |
| Port | 8849 | 8850 |
| Guarded search | `records_search` | `activity_search` |

## Records server — the tools

| Tool | Answers | Arguments | Returns | Reads | Who | Slice |
|---|---|---|---|---|---|---|
| `find_boards` | Q1 · resolver | q (a title fragment), folder, owner (a member), shared_with_me (bool), favorites (bool), templates (bool — templates only), updated_since, limit, offset | `{rows: [{board_id, title, owner_name, my_level, item_count, open_threads, last_activity_at, is_favorite, is_template}]}` newest first | `mcp_boards`, `mcp_folder_entries` | insider | 1 |
| `board_get` | Q2 | **board** | the board: title, description, owner, my_level, is_locked, item_count against the budget (`soft`, `hard`), counts by type, open threads, folders I keep it in, start view, last change (seq, who, when), the URL | `mcp_boards`, `bd_board_overview()` (counts) | view | 1 |
| `board_access` | Q10 | **board** | `{owner, everyone_level, rows: [{principal_kind, principal_id, principal_name, level, granted_by, created_at}]}` | `mcp_board_permissions` | view | 1 |
| `find_folders` | Q13 · resolver | q, team (bool), limit | `{rows: [{folder_id, name, is_team, owner_member_id, board_count}]}` | `mcp_folders` | insider | 1 |
| `trash_list` | the trash | limit | `{rows: [{board_id, title, item_count, deleted_at, deleted_by, purge_after}]}` | `mcp_trash` | own (admin: all) | 1 |
| `board_overview` | Q2, Q3 — **always the first read of a board** | **board** | `bd_board_overview()`: `{board_id, title, my_level, item_count, soft_budget, extent: [x1,y1,x2,y2], frames: [{item_id, title, box, counts_by_type, sample: [≤ 3 texts]}], clusters: [{box, count, counts_by_type, sample}] (unframed items in 1,000-unit cells), totals_by_type, last_change: {seq, member_name, at}, active_last_hour: [names]}` | `bd_board_overview()` | view | 2 |
| `board_search` | Q4 | **board**, **text** (words to match), type (an item type), frame (an item id), limit (≤ 50) | `{rows: [{item_id, type, frame_id, frame_title, excerpt (≤ 160 chars), cx, cy, x1, y1, x2, y2}]}` best match first (frames' titles, then texts) | `mcp_board_items` (`plain_text`, `data.title`) | view | 2 |
| `board_read_region` | Q5 | **board**, and either **x1, y1, x2, y2** (a box) or **around** (an item id — its box padded by `pad`, default 200), limit (≤ 400) | `{ok, items: n, truncated: bool, svg: "<svg …>"}` in **the SVG dialect** (below) | `bd_region_svg()` | view | 2 |
| `item_get` | Q5 detail | **item** | one item: type, frame, cx, cy, width, height, rotation, z, style, data (the text as plain and as runs), locked, version, created/updated by and at, its connectors | `mcp_board_items` | view | 2 |
| `board_outline` | Q6 · **K7 share** | **board** | `{board_id, title, outline: "markdown"}`: each frame a heading with its items as lines (`- [sticky, light_yellow] text`), the unframed items under "On the canvas", connectors as `A → B (caption)`, open comments with their anchor; ≤ 256 kB | `bd_board_outline()` | view (kernel: as the consumer's agent) | 2 |
| `frame_read` | Q5 | **board**, **frame** (an item id), limit (≤ 400) | the frame's children in the SVG dialect, **frame-relative** (the frame's top-left is 0,0) | `bd_region_svg()` over the frame's box | view | 4 |
| `item_connections` | Q7 | **item** | `{incoming: [{connector_id, from_id, from_type, from_excerpt, caption}], outgoing: [{connector_id, to_id, …}]}` | `mcp_board_items` | view | 4 |
| `find_attachments` | Q14 | **board**, kind (image or document), limit | `{rows: [{attachment_id, item_id, filename, mime_type, byte_size, width, height, uploaded_by, created_at}]}` — never a path | `mcp_attachments` | view | 5 |
| `find_comments` | Q9 · resolver | board, open (bool), mentions_me (bool), unanswered (bool — no reply and not resolved), author (a member), q, limit, offset | `{rows: [{comment_id, board_id, board_title, item_id, author_name, excerpt, replies, resolved_at, created_at}]}` threads only (roots) | `mcp_comments`, `mcp_boards` | view on each board | 6 |
| `comment_thread` | Q9 | **comment** (any comment of the thread) | `{root: {…, anchor: {item_id, x, y}, context_items: [{item_id, type, excerpt}]}, replies: [{comment_id, author_name, author_kind, text, created_at}], resolved_at}` | `mcp_comments`, `mcp_board_items` | view | 6 |
| `my_notifications` | Q15 | unread_only (bool), limit | `{rows: [{notification_id, kind, title, body, board_id, actor, read_at, created_at}]}` | `mcp_notifications` | own | 6 |
| `board_changes` | Q8 | **board**, since, until, member (a member), source (web or agent or command_bar or restore), limit (≤ 100) | `{rows: [{seq, at, member_name, member_kind, source, counts: {create, update, delete, restore}, types: {…}, box: [x1,y1,x2,y2], undoes}]}` newest first, and `since_my_last_visit` when `since` is omitted | `mcp_board_changes`, `mcp_boards.my_last_visit` | view | 7 |
| `board_versions` | Q11 | **board**, limit | `{rows: [{version_no, label, reason, item_count, saved_by, created_at}]}` | `mcp_board_versions` | view | 7 |
| `version_outline` | Q11 — "what did it look like on Friday" | **board**, **version** | the version as `board_outline` would have written it | `bd_version_outline()` | view | 7 |
| `find_templates` | Q12 · resolver | q, category, scope (shipped or business or personal), limit | `{rows: [{board_id, title, template_scope, template_category, item_count, owner_name}]}` | `mcp_boards` (`is_template`) | insider | 8 |
| `records_search` | Q18 — the guarded search | **q** (words — `tsquery` built server-side, never raw), kind (board or item or comment), board, limit | `{rows: [{kind, id, board_id, board_title, item_type, title, excerpt, occurred_at, rank}]}` | `mcp_search` | insider (each row on a board the caller may see) | 9 |
| `agent_dispatches` | the mentions of agents and what became of them | status, agent (a member), board, limit | `{rows: [{dispatch_id, board_title, comment_id, agent_name, acting_name, status, reply_excerpt, attempts, created_at, answered_at}]}` | `mcp_agent_dispatches` | agents.manage, else own | 9 |
| `boards_index` | **K7 share** | q, limit | `{schema: "os.boards-index/1", rows: [{board_id, title, owner_name, folder_names, item_count, updated_at, url}]}` | `mcp_boards` | kernel only: as the consumer's agent | 9 |
| `boards_usage` | Q16 | sort (items or idle or open_threads), idle_days, owner_left (bool), limit | `{soft_budget, hard_cap, rows: [{board_id, title, owner_name, owner_active, item_count, over_soft, idle_days, open_threads, last_activity_at}]}` | `mcp_boards_usage` | boards.admin | 10 |
| `find_members` | Q17 · resolver | q, kind (human or agent), department, limit | `{rows: [{member_id, display_name, member_kind, roles_resolved, department_ids}]}` | `mcp_members` | insider | shell |
| `find_departments` | Q17 · resolver | q, limit | `{rows: [{department_id, name, parent_id}]}` | `mcp_departments` | insider | shell |
| `app_roles` | the kernel's | — | `os.app-roles/1`: the three roles and their rights from `mcp_app_roles` | `mcp_app_roles` | kernel only | shell |

**The resolvers** (`deploy/kernel-registry-boards.json`):
- `find_boards`, `find_templates`, `find_folders`, `find_members` and `find_departments` each answer `{"rows": [...]}` with the id
  field and the label field the registry names, when given `q` alone.
- An item, a comment, a version, a notification, a token and a dispatch are ids only. An agent finds an item with `board_search` or
  `board_read_region` on the board it names.

### The SVG dialect (normative — `bd_region_svg()`, db/013; the SVG export door uses the same function)

One document per read; the grammar:

```
<svg xmlns="http://www.w3.org/2000/svg" data-board="{uuid}" data-box="{x1} {y1} {x2} {y2}" data-items="{n}" data-truncated="{true|false}"
     viewBox="{x1} {y1} {w} {h}">
  <g data-id="{uuid}" data-type="frame" data-title="{title}" data-z="{z}" data-cx data-cy data-box="…" transform="translate({left} {top})">
     <rect x="0" y="0" width="{w}" height="{h}"/>
     … the frame's children, their x/y RELATIVE to the frame's top-left …
  </g>
  <foreignObject data-id data-type="sticky_note|text|shape" [data-shape="ellipse"] data-z data-cx data-cy [data-color="light_yellow"]
     [data-locked="true"] [data-rotation="15"] x="{left}" y="{top}" width height>{the text, XML-escaped}</foreignObject>
  <image data-id data-type="image|document" data-z data-cx data-cy data-title="{title}" x y width height/>
  <rect data-id data-type="drawing" data-z data-cx data-cy data-points="{count}" data-tool="pen|highlighter" x y width height/>
  <path data-id data-type="connector" data-start="{uuid|}" data-end="{uuid|}" data-shape="straight|elbowed|curved" [data-caption="…"]
     d="M {sx} {sy} L {ex} {ey}"/>
</svg>
```

**Rules:**
- **x and y are SVG's top-left.** `data-cx` and `data-cy` are the centre, which is what every action takes.
- Inside a frame's group, every coordinate is frame-relative. `frame_read` answers the group alone, so its children read from 0,0.
- A connector's line runs between its ends' centres (or its free points). It is a reading aid, not the rendered route.
- Text is the item's plain text, at most 500 characters an item. Longer text ends in `…`, and `item_get` gives it whole.
- **Order:** frames first, then by `z`. At most `limit` items (400): past it, `data-truncated="true"` and the envelope says "narrow the box".
- The whole answer is at most 64 kB, or `too_large`.
- A deleted item is never in it; a drawing's points never are.
- An agent writes back through actions, never in SVG.

## Activity server — the tools

Each tool reads `mcp_activity_log` (the board's wall applied in the view), or the tenant's MaluDB episodes through the kit's activity-memory
client for what is older than the local log.

| Tool | Answers | Arguments | Returns | Who | Slice |
|---|---|---|---|---|---|
| `board_trail` | A1 — who opened or worked on a board | **board**, since, until, limit | `{rows: [{at, member_name, member_kind, action, counts, source}]}` | view | 7 |
| `member_activity` | A2 — what a person did on boards | **member**, since, until, limit | `{rows: [{at, action, board_title, entity_type, summary}]}` (only boards the caller may see) | insider | shell |
| `record_history` | A3 — when a board was shared, renamed, restored | **kind** (board or comment or folder), **id**, limit | `{rows: [{at, member_name, action, before, after}]}` | view | shell |
| `most_active_boards` | A4 | since (default 30 days), limit | `{rows: [{board_id, title, batches, members, last_at}]}` | insider | 10 |
| `agent_activity` | A5 — what agents did on boards | agent (a member), board, since, limit | `{rows: [{at, agent_name, action, board_title, counts, run_id}]}` | insider | 9 |
| `recent_activity` | A6 | limit | the caller's visible recent events | insider | shell |
| `activity_search` | A6 — the guarded search | **q**, since, limit | episodes from the tenant's MaluDB tagged `boards`, each filtered through `mcp_activity_log`'s wall | insider | shell |

## Coverage — every question has a tool

| Questions (design §7) | Tools |
|---|---|
| Q1 boards I can see | `find_boards` |
| Q2 what is this board · Q3 area by area | `board_get`, `board_overview` |
| Q4 where is "pricing" | `board_search` |
| Q5 what is in this area or frame | `board_read_region`, `frame_read`, `item_get` |
| Q6 the board as text | `board_outline` |
| Q7 what is connected | `item_connections` |
| Q8 what changed since | `board_changes` |
| Q9 open comments | `find_comments`, `comment_thread` |
| Q10 who can open it | `board_access` |
| Q11 versions | `board_versions`, `version_outline` |
| Q12 templates | `find_templates` |
| Q13 folders | `find_folders` |
| Q14 images and files | `find_attachments` |
| Q15 my notifications | `my_notifications` |
| Q16 usage | `boards_usage` |
| Q17 people and departments | `find_members`, `find_departments` |
| Q18 search everything | `records_search` |
| A1–A6 | `board_trail`, `member_activity`, `record_history`, `most_active_boards`, `agent_activity`, `recent_activity`, `activity_search` |
| the kernel's | `app_roles`, `boards_index`, `board_outline` |

**The count:** 27 records tools — 22 answering questions, 5 of which are resolvers, plus `app_roles` and the shares, one of which is
`board_outline`, also a question tool — and 7 activity tools.
