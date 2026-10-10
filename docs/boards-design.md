# Boards — design (Phase 0 of the new-app workflow, fitted to the Business OS)

2026-10-10 · the business's **infinite whiteboard**, Miro's job for a MaluDB Business OS installation: boards that people
and the OS's agents work on together — sticky notes, shapes, text, connectors that stay attached, frames, freehand pen,
images and files, comments pinned to the canvas — with everyone's cursor and every change live on every screen, every
object a row in the record memory, every change an episode in the activity memory, and an agent's sticky notes arriving
on the canvas the way a colleague's do. The owner's brief (the README, 2026-10-10): *a Miro-style infinite whiteboard as
an application of the MaluDB Business OS: people and OS agents on the same canvas, every object in the record memory
(PostgreSQL 17), every change in the activity memory (MaluDB).* Its own repository `/srv/apps/boards` (origin
`github.com/maludb/maludb-os-boards`, private), served as `boards.<domain>`, fitted to `maludb-os-integration` 0.9.0 and
`htmx-php-builder` 0.9.0 exactly as Spaces, DocCloud, Sales CRM and the Data Lake were. The nearest built exemplars:
`/srv/apps/spaces` (comments with mentions, the rich-text format, agents as members answering a mention through the
kernel's chat endpoint, page permissions, versions, templates), `/srv/apps/doccloud` (uploads, the attachment store,
thumbnails), `/srv/apps/datalake` (the newest kit).

> **What this document is.** The plan, in the kernel's words, and the brief a worker model builds from. The research and
> the tech-stack decision were made on the owner's Mac on 2026-10-10 and are **accepted** —
> [research/tech-stack-decision.md](research/tech-stack-decision.md) (the record) and
> [research/miro-and-whiteboard-landscape.md](research/miro-and-whiteboard-landscape.md) (the evidence); this document does
> not reopen them. It adds what the dev server and the estate say ([research/dev-server-survey.md](research/dev-server-survey.md)
> and §0.3), the memory model, the questions, the actions, the walls, the slices and the decisions still open (§13). Phase 0's
> first half is this document and the owner's answers (§15); the **realtime spike**, the schema in `db/`, `maludb-os.json`,
> `os/`, `skills/` and `deploy/` complete it. Phase 1 adds `docs/boards-mcp-tool-surface.md`, `docs/boards-action-manifest.md`,
> `docs/build-specs/canvas-core.md`, `docs/build-specs/live-relay.md`, `docs/build-specs/walls-and-sharing.md` and the slice
> specs. **No feature PHP is written until the owner approves them together.**

## 0. What is decided, and what the server and the estate say

### 0.1 Decided on 2026-10-10 — not reopened

| # | Decision (the record's §3 and §7) | In one line |
|---|---|---|
| R1 | Screens | PHP + HTMX + Bootstrap 5.3 nxl for every screen and panel; the canvas alone is a **TypeScript + Konva.js island** (esbuild bundle **committed** under `html/assets/boards/`; no Node on a server) |
| R2 | Sync | **Server-authoritative rows, per-property last-writer-wins**; every object a row in `board_items` shaped like Miro's REST item; op batches sequenced in `board_ops`; **no CRDT** in v1 |
| R3 | Write path | every write is a PHP handler in the action manifest — the canvas, the command bar and the kernel's agents use the same doors; the handler's transaction `NOTIFY`s |
| R4 | Live | a **Python asyncio WebSocket relay** `LISTEN`s and fans committed ops out to the room, and relays cursors, selections, drag previews and presence (never persisted); it **never writes board data**; with no socket the client polls `ops?after=` every 3 s |
| R5 | Realtime ambition | live cursors, presence and live edits **in v1** |
| R6 | Key and scope | catalog key `boards`, scope kind `none`, access per board plus department grants |
| R7 | v1 objects | sticky, shape, text, connector, frame, pen, image/file, comment pins; cards linked to Projects issues are v2 |
| R8 | Agents read | the records MCP reads a board as **search → region**, in an **SVG dialect** modelled on Miro's own MCP; no full-board reads |

### 0.2 What Miro is, in the shape we copy

Miro's REST v2 item is the row: `type`, `position {x, y}` (the **centre**; relative to the parent frame's top-left when the
item sits in a frame), `geometry {width, height, rotation}`, `parent`, `style`, `data`, created/modified by/at; connectors
attach to an item id with a snap side or a relative position; frames carry their children. Miro's own MCP disables
full-board reads: an agent searches (overview, areas, matches) and reads a bounding box as SVG with stable ids. Miro removed
its board webhooks (2025-12-05) as unreliable — the change feed must be designed in, here `board_ops` + `activity_log`.
Miro recommends staying under 5,000 objects a board (hard cap 100,000) and names pen strokes and images the heavy cases.
The detail is in the landscape document.

### 0.3 What the dev server says (survey of 2026-10-10)

The full survey is [research/dev-server-survey.md](research/dev-server-survey.md). What it changes:

- **No Apache module to enable for the socket.** Apache 2.4.58's `mod_proxy_http` (enabled) tunnels `upgrade=websocket` to an
  `http://` backend; `proxy_wstunnel` is not needed. `mod_headers` is off on this host; `ROOT_STEPS.sh` enables it (Sales CRM's lesson).
- **The front proxy is OpenResty (Nginx Proxy Manager).** It passes `Upgrade` only when the boards proxy host has
  *Websockets Support* switched on — the owner's switch at Phase 5, beside DNS and TLS.
- **The installer assigns ports to `APP_INTERNAL_PORT` and `endpoints[].port_env` only.** `BOARDS_LIVE_PORT` is pinned by
  `ROOT_STEPS.sh` with the other three before the first `apply` (the estate's practice since Help Desk); templates render any env key.
- **Ports 8198 / 8849 / 8850 / live 8851**, scratch 9101–9107 (8194–8197 are txtSchedules' proof servers).
- **The relay uses asyncpg** (`add_listener`) — every application's venv already has it — and adds `websockets`; psycopg
  is not needed. The installer builds one venv (`mcp/venv`), so the relay lives in it.
- **Thumbnails with GD** (no imagick), a PDF's first page with `pdftoppm` (present). The vhost raises PHP's 2 MB upload limit.
- **Node 24 is already installed** — a development tool for `client/` only.
- The kernel is at db/179; Boards' catalog row is **K40 = db/180**, category `communication` (already admitted), area Operations.

### 0.4 What the estate gives (the survey of the siblings' `db/*.sql`, 2026-10-10)

1,050 `CREATE TABLE`s across seventeen applications were listed (`shared-schema.md` §1). The nearest families, and what they
change in the record's table list (record §3.7):

| The record proposed | The estate already has | So Boards takes |
|---|---|---|
| `board_members` (owner/editor/commenter/viewer) + a board-wide access column | **Spaces `page_permissions`** (`principal_kind` member/department/agent/…/everyone, `principal_id`, `level` view/comment/…/full, `granted_by`) and DocCloud `folder_access` — the estate's access-row family | **`board_permissions`**, Spaces' shape with `page_id` → `board_id`; Miro's four roles are the levels `view`, `comment`, `edit`, `full`; the board-wide level is the `everyone` row. The semantics of R6 are unchanged — only the shape follows the family |
| `board_snapshots` | **Spaces `page_versions`** (`version_no`, `content_rev`, `reason`, `snapshot jsonb`, `saved_by`) | **`board_versions`**, Spaces' shape with `page_id` → `board_id`; `content_rev` is the board's op sequence at the snapshot |
| `board_templates` | **Spaces' templates are pages with `is_template`**, applied by duplicating | **`boards.is_template`** (+ appended `template_scope`, `template_category`); no separate table |
| `comments`, `comment_mentions` | Spaces `db/011` (UUID, threaded, resolvable, the one rich-text body) | reuse, with `page_id` → `board_id`, `block_id` → `item_id`, and appended `anchor_x`, `anchor_y`, `context_item_ids` (§6.4) |
| favourites, recents | Spaces `page_favorites`, `page_recents` | `board_favorites`, `board_recents` (`page_id` → `board_id`) |
| `attachments`, `notifications*`, `search_index`, `agent_dispatches`, `worker_passes` | Spaces `db/012`, `db/013`, `db/016` (canonical + the UUID loosening) | reuse (§6.7) |
| `boards`, `board_folders`, `board_items`, `board_ops` | nothing close (Projects' `board_columns` is a kanban column of an issue workflow, not a canvas) | **new canonical** |

The name `boards` is free in the estate. Projects calls its kanban "the board" (`board_columns`). Boards owns the table name
`boards` from now on, for a canvas, and records that collision of words in `shared-schema.md`.

## 1. What Boards is, and is not

**Is.** Boards are organised by favourites, recents, folders and one search box. A board is an infinite canvas holding:

- **sticky notes**, in Miro's palette, with text that auto-fits;
- **shapes** — rectangle, rounded rectangle, ellipse, triangle, diamond, parallelogram, hexagon, star, arrow block and
  callout — each holding text;
- **text**;
- **connectors** that stay attached to an item's side or a point on it: straight, elbowed or curved, with arrowheads and a
  caption;
- **frames** whose children move with them, used as areas, slides and the navigator's stops;
- the **pen** and the **highlighter**, with an eraser;
- **images** and **files** — a PDF shows its first page, any other file shows as a card.

Other people's cursors, selections and drags appear as they happen. Comments are pinned to an item or a point: threaded,
resolvable, with @mentions of people, departments and agents. Each person has their own undo. History has named versions
and restore. Boards and frames can be duplicated, and templates come seeded. A frame, a selection or the view exports as
PNG, and a board exports as SVG. There is a text outline of every board for 375 px and for screen readers.

**The OS's agents are on the canvas.** The command bar runs the Boards Expert, and a comment that @mentions an agent is one
turn of that agent. Its edits arrive live, with its avatar where it works.

**Is not (v1).**

- cards and app cards linked to Projects issues, Help Desk tickets or CRM deals — v2 (R7), Extended §11;
- voting, the timer, reactions, "follow me" — v2;
- embeds, mind maps, tables, diagram mode with shape libraries, Kanban frames, private mode — v2 or later;
- talktrack, audio and video;
- offline editing — Miro does not offer it either;
- a public share link — D3;
- a REST API like Miro's — Extended;
- a document editor, which is Spaces' job;
- a work tracker, which is Projects' board.

## 2. Who uses it (the actors, in the OS's words)

| Actor | Who | Reaches Boards through |
|---|---|---|
| **A Member** (`member`, kernel capability `user`) | Anyone the super-admin granted Boards: creates boards, folders and personal templates; works on any board shared with them at the level given | the browser (the launcher's hand-off), the command bar, mentions |
| **A Viewer** (`viewer`) | A person who should see and discuss boards but never draw on them — an external adviser, a client contact with an OS account, a reviewer. Opens what is shared with them; comments where the board's level for them is `comment` or higher; **never edits, never creates** (their level is capped at `comment`) | the browser |
| **A Boards admin** (`admin`, `is_admin`) | Sees and manages every board as `full`: restores, transfers a departed person's boards, publishes business templates, purges the trash, reads usage against the budget, sets the defaults | the browser, `/admin` |
| **An agent** | Any hired agent granted Boards (`member`): the shipped **Boards Expert** and **Facilitator** on install, any other by a super-admin. A member in every sense — sees what its member id may see, edits where given `edit`, is @mentioned | Records MCP (run token) · Actions MCP (relayed) · a chat turn on a mention or from the command bar |
| **The kernel** | Signs people in, delivers the directory, hires and runs the agents, pauses their actions for approval, answers the command bar and the mentions (the chat endpoint), carries texts (K6) and the siblings' reads (K7) | — |
| **The system** | The worker (versions, the outbox, dispatches, the search catch-up, thumbnails, trash and op compaction) and the relay (rooms, presence, fan-out — never data) | — |

Board levels (Miro's roles in the estate's words): **`view`** (viewer), **`comment`** (commenter), **`edit`** (editor),
**`full`** (owner: shares, deletes, transfers, restores versions). The owner is `boards.owner_member_id`; `full` rows are co-owners.

## 3. Who sees what — the rules, in one place

- **A person's level on a board** is the highest of: owner → `full`; their `member` row; a `department` row for any department
  they belong to (the kernel's ids, mirrored); the board's `everyone` row; a Boards admin → `full` on every board. It is
  then **capped by the application role**: a Viewer never above `comment`. No row and no `everyone` → no access, and the
  board does not exist for them (404, never 403, so a title never leaks).
- **`everyone` means every non-external member granted Boards** — people and agents. An external (`members.is_external`)
  reaches a board only through a `member` row naming them (D3).
- **Folders grant nothing.** A folder shows only the boards the viewer can open; a team folder is visible to every Member.
- **Items, comments, attachments, versions and ops inherit the board's wall.** A Viewer's socket may send a cursor; nothing it
  sends is committed (commits are PHP, refused below `edit`, or below `comment` for a comment).
- **The trash**: a deleted board stays 30 days, seen by its owner and the admins; restore returns it with its permissions.
- **Templates**: shipped and business templates are seen by every Member; a personal template by its maker.
- **In SQL, not in PHP or Python**: `bd_board_level(board uuid, member bigint) → text` is PL/pgSQL, one query; every
  `mcp_*` view and every handler asks it, the views with the caller tested once per statement (the kernel's db/160 rule:
  `WHERE (SELECT bd_is_admin()) OR …`), `security_barrier` throughout.
- **The relay asks the same function.** A socket's ticket names member, board and level; every 60 s the relay calls
  `bd_live_check(member, board)` (`SECURITY DEFINER`, granted to the records role) and closes a socket whose member has no
  open session (a kernel sign-out ends `member_sessions`) or no level any more, or pushes the new level.

## 4. Doors for those who are not inside

- **No public link in v1** (D3). Someone outside the business reaches a board as an OS person: a super-admin grants Boards
  to an external, and the board's owner adds them by name. A view-only link with an expiry is Extended.
- **The siblings, through K7** (D14). Boards shares two tools:
  - `boards_index` — boards the consumer's agent may see: title, owner, folder, updated, item count, URL;
  - `board_outline` — one board as text: frames in order, each frame's items as lines (type, text, colour, links), the
    connectors as "A → B", the open comments, capped at 256 kB.

  Spaces can embed it (SP5), Knowledge can ingest it, and Projects can turn a frame into issues. **Boards reads nothing** in
  v1. App cards (v2) read Projects, Help Desk and Sales CRM.
- **Clients' own AI tools** reach a board through the two MCP servers with a person's token (SaaS Plus+, `/tokens`).

## 5. Agents and the application — the OS requirement, in detail

**On a board, an agent works like a colleague who can be undone.** It may create, edit, arrange and comment freely. Deleting
other people's work, changing who sees a board, rolling a board back and publishing for the whole business are a person's
decisions. For every agent:

| Action | Category | Why |
|---|---|---|
| `item_delete_batch`, `board_delete`, `folder_delete`, `comment_delete` (another's), `trash_purge` | `deletion` | Removes someone's work |
| `board_share`, `board_unshare`, `board_access_set` (the `everyone` level), `board_transfer`, `version_restore`, `template_publish` (business), `settings_save` | `other` | Who sees the business's work; a mass change; a rule for everyone |
| `board_create`, `board_update`, `board_duplicate`, `board_lock`, `item_create_batch` (with a layout: a grid, a column, a cluster, inside a frame), `item_update_batch`, `items_arrange`, `connector_create`, `frame_create`, **`ops_undo`** (its own batches from the last hour), `comment_create`, `comment_reply`, `comment_resolve`, `version_save`, `template_use`, `board_favorite`, `export_svg` | — | What a facilitator does in the room; every one is undoable and in the history |

`ops_undo` is how an agent takes back its own mistake. It reverts only the batches its own run committed. Deleting anything
else goes to a person.

**Working.**

**(a) The command bar.** A person's turn on the Boards Expert through the kernel's chat endpoint. For example: *make a retro
board for the Q4 launch from the retrospective template and share it with marketing; add a sticky for each of these ideas in
the Ideas frame; group the Ideas frame by theme; summarise what the team wrote in Went well; what changed on the launch
board since Friday; who has open comments on the roadmap.*

- The expert reads with `board_overview` → `board_search` → `board_read_region`.
- It acts through the registry.
- Its stickies appear on everyone's screen, because its handler commits like anyone's and the trigger notifies.

**(b) A mention.** A comment that @mentions an agent becomes an `agent_dispatches` row. The worker turns it into **one chat
turn** of that agent, as Spaces does, carrying:

- the asker's identity;
- the board, the comment's anchor and **the items selected when the comment was written** (`context_item_ids`), so "group
  these" means these;
- the comment thread as the conversation.

The reply lands as a reply comment. A "Facilitator is working…" placeholder stands while the run is long, and an approval
pause is said in the placeholder. If the agent cannot see the board, the mention asks the person who wrote it to share the
board with the agent first (D11) — **a mention never grants access**.

**(c) No duties in v1.** Boards has no scheduled agent work.

**An agent on the canvas.** The client draws an **agent's cursor** without any new channel:

- every op batch an agent commits carries `source = 'agent'`, the agent's member id and the batch's bounding box
  (`board_ops.summary.bbox`);
- the client animates that agent's avatar to the box and flashes the new items;
- the presence row shows "Facilitator — working" while the dispatch runs.

That is the OS's answer to Miro's Sidekicks, with no model key in Boards.

**Shipped agents** (`maludb-os.json` `agents[]`; **hired on install**, D10):

| Key | Job | Reads | Acts | Pauses on |
|---|---|---|---|---|
| `expert`: Boards Expert | The command bar and the house name. It finds a board or an item; it answers what is on a board, what changed and who said what, and summarises a frame. It makes boards from templates, adds and arranges items, writes comments and saves a named version. It never deletes others' work, shares, restores or publishes alone | every records tool, `records_search`, `board_changes`, `record_history` | `board_create`, `board_duplicate`, `template_use`, `item_create_batch`, `item_update_batch`, `items_arrange`, `connector_create`, `frame_create`, `comment_*`, `version_save`, `ops_undo`, and the paused ones | every `deletion`, every `other` |
| `facilitator`: Facilitator | Called by a mention on a board. It clusters stickies by theme (affinity mapping) into labelled frames or columns, de-duplicates, summarises a frame into a text item or a reply, and turns a pasted list into a grid of stickies. It drafts a diagram (flowchart, org chart, timeline) from a description with shapes and connectors, and lays out a workshop (retro, brainstorm, SWOT, story map) in empty frames. It never shares or deletes others' work | `board_overview`, `board_search`, `board_read_region`, `frame_read`, `comment_thread`, `find_templates` | `item_create_batch`, `item_update_batch`, `items_arrange`, `connector_create`, `frame_create`, `comment_reply`, `ops_undo` | every `deletion`, every `other` |

**Skills** (`skills/`):

- `boards-basics` — what a board, a frame and each item type *are*; the coordinates (the centre; relative to the frame); the
  SVG dialect; **search, then read a region — never the whole board**; the 5,000-item budget; levels and who sees what.
- `laying-out-a-board` — grids, spacing, the sticky size, frames as areas, colour with meaning, never overlapping a
  person's work, `items_arrange` instead of arithmetic.
- `facilitating-a-workshop` — retro, brainstorm, affinity mapping, SWOT, story map, and what to leave to people.

Runbooks (`kind: runbook`): `cluster-stickies-by-theme`, `summarise-a-frame`, `turn-a-list-into-stickies`,
`diagram-from-a-description` and `board-from-a-template`.

## 6. Memory model — what Boards remembers

There is one store. **Record memory** (PostgreSQL 17, `<tenant>_boards`) holds:

- boards, folders and permissions;
- the items;
- the ops and the versions;
- comments and mentions;
- attachments (bytes on disk under the canonical store's rules);
- notifications and the outbox, dispatches, the search index and the kit.

**The tenant's memory** receives the activity episodes, one per committed batch, never per drag frame.

**Ephemeral state is never stored**: cursors, selections, drag previews, presence and the edit soft-lock live in the relay's
memory and on the wire.

### 6.1 Boards, folders, permissions, favourites, recents (`app/features/boards/`, `folders/`, `sharing/`)

- **`boards`** — `id uuid` and these columns:
  - title and description;
  - `owner_member_id`;
  - the template columns: `is_template`, `template_scope` (`personal` | `business` | `shipped`), `template_category`;
  - `background` jsonb (dots, grid or none; a colour);
  - `start_view` jsonb (x, y, zoom — the view a board opens at, Miro's "start view");
  - `is_locked` (read-only for all but `full`);
  - `item_count`, `last_seq`, `last_activity_at` (kept by trigger);
  - `thumbnail_attachment_id` — a small PNG one editor's client renders and posts at most every five minutes, for the cards;
  - `created_by`/`at`, `updated_at`, `deleted_at`/`by` (the 30-day trash).
- **`board_permissions`** — Spaces' `page_permissions` with `board_id`:
  - `principal_kind`: `member`, `department`, `agent` or `everyone`; `principal_id`;
  - `level`: `view`, `comment`, `edit` or `full`;
  - `granted_by`, `created_at`.

  A new board gets an `everyone` row at the business default (`bd_settings.default_everyone_level`, seeded `none` — the board
  is private) or none.
- **`board_folders`** — name, `owner_member_id`, `is_team` (seen by every Member), position.
- **`board_folder_entries`** — `folder_id`, `board_id`, position, added_by. A board may sit in a person's folder and a team
  folder at once. Folders do not nest in v1.
- **`board_favorites`**, **`board_recents`** — Spaces' shapes.

### 6.2 Items (`app/features/items/`, the canvas island)

**`board_items`**, the new canonical table — Miro's item as a row:

| Column(s) | Meaning |
|---|---|
| `id uuid` | **minted by the client** for optimistic creates; the server refuses a duplicate |
| `board_id` | |
| `type` | `sticky_note`, `shape`, `text`, `connector`, `frame`, `drawing`, `image`, `document` (`card` and `app_card` widen it in v2) |
| `parent_id` | the frame it sits in; frames do not nest in v1 (D4) |
| `x`, `y` | **the centre**, relative to the parent frame's top-left when `parent_id` is set — Miro's convention, so a frame's move is one row |
| `width`, `height`, `rotation` | rotation in degrees |
| `z` | a fractional-index key, Spaces' `position` convention, so a reorder is one row |
| `style jsonb` | Miro's names: `fillColor`, `textColor`, `fontSize`, `fontFamily`, `textAlign`, `textAlignVertical`, `borderColor`, `borderWidth`, `borderStyle`, `fillOpacity`; a connector's `strokeColor`, `strokeWidth`, `strokeStyle`, `startStrokeCap`, `endStrokeCap` |
| `data jsonb` | by type: `content` (the estate's rich-text runs, D5), `shape`, a connector's `shape` (straight/elbowed/curved) with `startSnap`, `endSnap`, free-end points and `captions`, a drawing's `points` (simplified) and `tool` (pen/highlighter), an image's or document's `attachment_id` and `title`, a frame's `title` |
| `start_item_id`, `end_item_id` | a connector's ends |
| `locked` | |
| `bbox box` | the **absolute**, rotation-inclusive bounding box, kept by trigger — including the children's when their frame moves — with a GiST index on `(board_id, bbox)`. Region reads and the SVG dialect ask `bbox && box(…)` |
| `plain_text` | generated from `content`, for search |
| `version`, `last_seq` | |
| `created_by`/`at`, `updated_by`/`at`, `deleted_at`/`by` | |
| `agent_run_id` | the last agent run that touched the item |

**Rules the schema keeps.** Deleting a frame deletes its children in the same batch unless `keep_children`. Keeping them
re-parents them to the canvas at their absolute place. Deleting an item deletes its connectors in the same batch. A
connector's ends must be on the same board. A drawing keeps at most 2,000 points after simplification. A board refuses a
create above its hard cap (D7).

### 6.3 Ops, versions and history (`app/features/sync/`, `history/`)

**`board_ops`** (new canonical) — one row per committed batch:

| Column(s) | Meaning |
|---|---|
| `seq` | a global identity, so ordering within a board is `seq` order |
| `board_id`, `member_id` | |
| `source` | `web`, `agent`, `command_bar`, `mcp`, `system`, `restore` |
| `agent_run_id`, `request_id` | |
| `client_id` | the browser tab, so a client ignores its own echo |
| `client_batch_id` | `UNIQUE (board_id, client_batch_id)`: a retried POST answers the first commit's `seq` and does nothing twice |
| `ops jsonb` | the batch as applied: `create` with the full item; `update` with the fields and `base_version`; `delete`; `reorder` |
| `inverse jsonb` | the fields before — for undo, the history and restore |
| `summary jsonb` | counts by verb and type, and the batch's bounding box (the history's "where" and the agent's cursor) |
| `at` | |

An `AFTER INSERT` trigger calls `pg_notify('boards_ops', …)`. NOTIFY is delivered at commit, so any write path — the canvas,
the actions server, a restore — reaches the room with no handler code. The payload is
`{room: "board:<uuid>", seq, member_id, source, client_id}` plus the ops when they fit in 7.5 kB. Otherwise the relay reads
the batch by `seq` through the records role.

**Per-property last-writer-wins.** The handler applies an update's fields in arrival order: a move and a recolour by two
people both survive, and the same field takes the later write. Nothing is refused for being stale. `base_version` is kept in
the op, and when a write replaces **text** another person changed after it, the answer names them. The client then shows
"You replaced Maria's edit — Undo". The presence soft-lock ("Maria is editing") makes that rare.

**Undo.** Undo is per person: a client stack of the inverse batches it committed, sent as new batches. It never undoes
another person's change. An agent's `ops_undo` is the server-side form of the same thing.

**`board_versions`** — Spaces' `page_versions` with `board_id`:

- `version_no`;
- `content_rev` (the `seq` at the snapshot);
- `reason` (`interval` while the board is active, at most hourly; `manual` for a named version; `before_restore`; `restore`;
  `import`; `compaction`);
- `snapshot` (the live items);
- `plain_text`, `saved_by`.

A restore is never destructive. It saves `before_restore`, then commits **one** batch that turns today's items into the
snapshot's.

**Retention** (D6). Ops older than 90 days are compacted: a `compaction` version at the cut, then the rows go. Interval
versions are kept 30 days, a daily one for a year, and named versions forever. The activity log keeps the human history
regardless.

### 6.4 Comments, mentions, attachments, notifications, dispatches (`app/features/comments/`, `files/`, `notifications/`, `agents/`)

- **`comments`** (Spaces `db/011`):
  - `board_id` in Spaces' `page_id` place and `item_id` in its `block_id` place (an anchor on an item, or NULL for a point on
    the canvas);
  - `parent_comment_id` (threads), the rich-text `body` and `plain_text`, `resolved_*`, `edited_at`, `deleted_*`,
    `agent_run_id`;
  - **appended**: `anchor_x`, `anchor_y` (a canvas point, or an offset on the item) and `context_item_ids uuid[]` (the
    selection when the comment was written — what "these" means to an agent).
- **`comment_mentions`** (Spaces, verbatim).
- **`attachments`** (Spaces' UUID variant):
  - `record_type` widened to `item`, `comment`, `board_thumbnail`, `import`;
  - an image keeps `width`, `height` and three `thumbnail_path` sizes for level of detail;
  - a PDF keeps its first page;
  - 25 MB the ceiling (D9).
- **`notifications`, `notification_prefs`, `notification_outbox`** (Spaces `db/012`), with Boards' own kinds: `mention`,
  `reply`, `comment` (on a board I own), `share`, `agent_replied`, `digest`.
- **`agent_dispatches`** (the canonical + `record_uuid`), with kinds `mention` and `ask`; **appended** `board_id`,
  `reply_comment_id`, `pending_comment_id`, `next_attempt_at`.

### 6.5 Settings, roles, search, the worker

| Table(s) | What it holds |
|---|---|
| `bd_settings` (the `app_settings` singleton) | `default_everyone_level`, `item_soft_budget` (4,000), `item_hard_cap` (10,000), `op_retention_days` (90), `max_upload_mb` (25), `live_enabled` |
| `bd_rights`, `bd_roles`, `bd_role_rights`, `mcp_app_roles` | the roles catalogue, Consultant Tracking's shape with the `bd_` prefix |
| `search_index` (Spaces `db/013`) | `entity_kind` widened to `board`, `item`, `comment`; **appended** `board_id`, `item_type` |
| `worker_passes` (Spaces) | the worker's bookkeeping |

The worker runs every minute and does all of these in one pass:

- interval versions;
- the outbox;
- dispatches;
- the search catch-up;
- thumbnails;
- the trash at 30 days;
- op compaction.

### 6.6 Activity memory

`log_activity()` writes `entity.verb` with `"application": "boards"` first. Every row carries `board_id`.

**Board events.** `board.create`, `board.open` (once per page render — never per poll), `board.update`, `board.duplicate`,
`board.share`, `board.unshare`, `board.access_set`, `board.transfer`, `board.lock`, `board.delete`, `board.restore`,
`board.export`.

**Item events: one row per committed batch,** named by its verb:

- `item.create`, `item.update`, `item.move` and `item.delete` for a batch of one kind;
- `item.batch` with counts for a mixed batch;
- `item.text` — text edits coalesced per item per editing session (a commit on blur).

**Other events.** `comment.create`, `comment.reply`, `comment.resolve`, `comment.delete`; `version.save`, `version.restore`;
`template.use`, `template.publish`; `folder.*`; `agent.dispatch`; `ops.undo`.

**Never logged:** cursors, presence and drags.

### 6.7 Tables against the estate — read, reuse, new (the shared-schema rule)

| Table(s) | Decision | Source and what differs |
|---|---|---|
| `members`, `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | **reuse** | the kit's `db/001` (the Data Lake's, the newest, with the mirror fix) |
| `activity_log`, `activity_ingest_state` | **reuse** | `memory.md`; **appends** `board_id uuid` |
| `mcp_access_tokens` | **reuse** | HR `db/003` |
| `bd_rights`, `bd_roles`, `bd_role_rights`, `mcp_app_roles` | **reuse** (shape) | Consultant Tracking `db/004` (`ct_` → `bd_`) |
| `bd_settings` | **reuse** (skeleton) | the `app_settings` singleton |
| `attachments` | **reuse** | Spaces `db/012` (canonical + `record_uuid`, `width`, `height`, `thumbnail_path`); `record_type` widened |
| `notifications`, `notification_prefs`, `notification_outbox` | **reuse** | Spaces `db/012`, member-only; `kind` ours; Spaces' `channel_id`/`message_id` appended columns are Spaces' own and not copied |
| `agent_dispatches` | **reuse** | GL `db/014` canonical + the agreed `record_uuid`; **appends** `board_id`, `reply_comment_id`, `pending_comment_id`, `next_attempt_at` |
| `comments`, `comment_mentions` | **reuse** (the family) | Spaces `db/011`; the record key follows the application (`board_id`, `item_id`); **appends** `anchor_x`, `anchor_y`, `context_item_ids`; the rich-text CHECK through Boards' copy of Spaces' functions (`bd_is_rich_text`, `bd_rich_text_plain`) |
| `board_permissions` | **reuse** (the family) | Spaces `db/007` `page_permissions` (`page_id` → `board_id`); `principal_kind` ⊂ member/department/agent/everyone; `level` ⊂ view/comment/edit/full |
| `board_versions` | **reuse** (the family) | Spaces `page_versions` (`page_id` → `board_id`); `reason` widened with `compaction` |
| `board_favorites`, `board_recents` | **reuse** (the family) | Spaces `page_favorites`, `page_recents` (`page_id` → `board_id`) |
| `search_index`, `worker_passes` | **reuse** | Spaces `db/013`, `db/016`; `entity_kind` widened; appends `board_id`, `item_type` |
| `boards` | **new canonical** | compared: Spaces `pages` (a document with blocks), Projects `board_columns` (a kanban column of a workflow); a canvas with a start view, a lock and an op sequence is new. Templates are `is_template` rows, Spaces' precedent |
| `board_folders`, `board_folder_entries` | **new canonical** | compared: DocCloud `folders` (hold documents and **grant** access, inherited) — Boards' folders hold boards and grant nothing |
| `board_items` | **new canonical** | compared: Spaces `blocks` (a tree in reading order, no geometry); nothing in the estate has a position, a size and a z-order |
| `board_ops` | **new canonical** | compared: `activity_log` (human episodes, one per batch — kept as well) and Spaces' `content_rev` counter; an ordered, replayable batch log with inverses is new — **the estate's first change feed for live clients**, which Spaces will copy for co-editing |
| Projects issues, Help Desk tickets, CRM deals | **read** (v2) | app cards through K7, holding the provider's id and display fields only |

The new canonical tables and the three family reuses go into the plugin's `shared-schema.md` catalogue (§12, a plugin item).

## 7. The question inventory — what Boards exists to answer

**Record questions** (the records MCP; the Phase 1 surface names each tool's arguments):

| # | Question | Tool |
|---|---|---|
| 1 | Which boards can I see — mine, shared with me, in a folder, recently opened, favourites, templates? | `find_boards` (resolver) |
| 2 | What is this board — owner, levels, frames, counts by type, size against the budget, who is working on it? | `board_get`, `board_overview` |
| 3 | What is on the board, area by area? | `board_overview` (frames with titles and boxes; clusters of unframed items; counts) |
| 4 | Where is "pricing" on this board? | `board_search` (text matches with id, type, frame and box) |
| 5 | What is in this area or this frame, exactly? | `board_read_region` (the SVG dialect, a box), `frame_read` |
| 6 | The board as text | `board_outline` (also the K7 share) |
| 7 | What is connected to this item? | `item_connections` |
| 8 | What changed since Friday, or since my last visit — by whom, where; what did an agent do? | `board_changes` (from `board_ops`: batches summarised by member, verb, type and box; `source = agent` filter) |
| 9 | Which comments are open on this board, mention me, or are unanswered? | `find_comments` (resolver), `comment_thread` |
| 10 | Who can open this board, and at what level? | `board_access` |
| 11 | Which versions does this board have? | `board_versions` |
| 12 | Which templates are there? | `find_templates` (resolver) |
| 13 | Which folders? | `find_folders` (resolver) |
| 14 | Which images and files are on this board? | `find_attachments` |
| 15 | My notifications | `my_notifications` |
| 16 | Which boards are biggest, near the budget, untouched for 90 days, owned by someone departed? | `boards_usage` (admin) |
| 17 | Who is this person or department? | `find_members`, `find_departments` (resolvers) |
| 18 | Search everything I can see | `records_search` (the guarded one) |
| — | The kernel's | `app_roles`; shares `boards_index`, `board_outline` |

**Activity questions** (the activity MCP over the tenant's memory):

| # | Question | Tool |
|---|---|---|
| A1 | Who opened or worked on this board this week? | `board_trail` |
| A2 | What did Maria do on boards yesterday? | `member_activity` |
| A3 | When was this board shared with Marketing, and by whom? | `record_history` |
| A4 | Which boards were most active this month? | `most_active_boards` |
| A5 | What have agents done on boards — by agent, by board? | `agent_activity` |
| A6 | Anything else | `activity_search`, `recent_activity` |

**The SVG dialect** (the canvas-core spec writes it out). One element per item:

- `<rect>`, `<ellipse>` or `<path>` for a shape; `<foreignObject data-type="sticky_note">` for text-bearing items;
- `<line>` or `<path data-start data-end>` for a connector; `<polyline>` for a drawing; `<image>` with a title;
- `<g data-frame data-title>` for a frame, its children inside, in frame-relative coordinates.

Every element carries `data-id`, `data-type`, `data-z`, `data-locked`, the rendered box, the text and its colour name. The
reply is capped at 400 items and 64 kB; a larger box answers "narrow the box" with the frame list.

An agent writes back through actions, never in SVG.

## 8. The kernel's part, and the application's doors

- **Sign-on and the mirror**: the contract (`sign-on-and-directory.md`), the kit from the Data Lake. On sign-on and on every
  sync, a member's `notification_prefs` row exists with the defaults. Departments are mirrored for `department` permissions.
- **Roles**: `app_roles` on the records server. The kernel's token is admitted to it and to the two `shares[]` alone (reading
  `X-OS-Consumer` / `X-OS-Consumer-Agent`, K26).
- **Agents**:
  - run tokens on the two read servers (the run-facts gate, fail closed);
  - the Actions MCP from the registry (`deploy/kernel-registry-boards.json`, with the resolvers `find_boards`,
    `find_folders`, `find_templates`, `find_comments`, `find_members`, `find_departments`, and `find_items` within a board);
  - the approval hook before every action with a category;
  - the chat endpoint for the command bar and the dispatches.
- **Texts (K6)**: a mention, for a person who opted in.
- **Mail**: the installer's `mail` step fills `MALUMAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME`. Mail carries notifications
  and the digest.

**The board doors** (same-origin, under the session, or under an action token for agents):

| Door | What it does |
|---|---|
| `GET /boards/{board}` | The canvas page: the nxl shell, the island's `<div>` with the board's id, the level, the last `seq`, a **live ticket**, and the CSRF token |
| `GET /boards/{board}/state` | Every live item and the last `seq`. One load: at the 5,000 budget, about 2 MB raw and about 300 kB compressed |
| `POST /boards/{board}/ops` | The batch. JSON: `client_batch_id`, `client_id`, `ops[]` up to 500. Answers `seq`, the versions, and any text it replaced |
| `GET /boards/{board}/ops?after={seq}` | Catch-up after a reconnect, and the 3-second poll when the socket is down. Answers `{batches, last_seq}` or `resync` when `after` is older than retention |
| `POST /boards/{board}/live-ticket` | A fresh 60-second ticket |
| `POST /boards/{board}/thumbnail` | The cards' preview |
| `POST /boards/{board}/files` | An image or a file for the canvas: answers the attachment and its thumbnails |

**The live door — `wss://boards.<domain>/live`.** The vhost line is
`ProxyPass /live http://127.0.0.1:{{BOARDS_LIVE_PORT}}/ upgrade=websocket`, which needs no module change on Apache 2.4.58.
The relay is `boards-live.service` (`live/relay.py` in `mcp/venv`, bound to `127.0.0.1`, asyncpg + `websockets`).

**Generic by construction:**

- rooms are strings (`board:<uuid>`); the transport knows nothing of boards;
- the ticket is `{member}.{room}.{level}.{exp}.{hmac}` with `BOARDS_LIVE_KEY`, generated by `ROOT_STEPS.sh`;
- the check function and the room prefix are the only Boards-specific parts.

So Spaces can run the same relay for co-editing.

**The wire.**

| Direction | Messages |
|---|---|
| Client → relay | `cursor` at most 20 Hz; `select`; `drag` (live previews of a move or resize, ids and the delta); `editing` (the soft lock); `view`; `ping` |
| Relay → client | `welcome` with the room's peers; `peer` updates; `join`/`leave`; `ops` (a committed batch, or `{seq}` alone meaning "fetch it"); `level` (changed); `closing` with a reason |

**Limits.**

- More than 30 messages a second from one socket are dropped.
- A room holds 100 sockets.
- A Viewer's or commenter's socket may send `cursor`, `select` and `view` only.

**Revocation (contract gap 5).** Every 60 s the relay runs `bd_live_check` for each socket (§3).

**Polling fallback.** If the socket cannot open, or drops, the page polls `ops?after=` every 3 s while visible and says
"Live updates paused". The board stays editable.

The vhost allow-list (public) is `/live`, `/mcp/records`, `/mcp/activity` and `/api/v1/health`. The board doors above live
under the session's cookie on the same origin, not under the public API prefix.

## 9. Screens

The nxl look; no modals; full-page create and edit. The canvas is designed at 1280 px and must work at 375 px for viewing,
pan, pinch-zoom, adding a sticky, moving, commenting and reading the outline. Lists of boards are **cards with
thumbnails** (the owner's preference).

| Screen | URL | What it is |
|---|---|---|
| Home | `/` | Recent, favourites, shared with me, team folders; New board; New from template |
| All boards | `/boards` | cards; filters: owner, folder, shared with me, level, updated; the search box |
| New board | `/boards/new` | title, folder, from a template, the `everyone` level. A one-click "New board" also creates "Untitled board" and opens it |
| **The canvas** | `/boards/{board}` | the island; a top bar (title, avatars of who is here, share, the level badge, the menu); the left toolbar (select, hand, sticky, shape, text, connector, frame, pen, highlighter, eraser, image/file, comment); the bottom bar (zoom, fit, minimap, undo/redo, the frame navigator); **the right rail** (an HTMX panel beside the canvas, never over it on a desktop; a full-width sheet at 375 px): comments, the item's properties, the history, the outline |
| Board settings | `/boards/{board}/settings` | title, description, folder, background, start view (set from the current view), lock, duplicate, delete |
| Share | `/boards/{board}/share` | the `everyone` level; people, departments and agents with their level (the record picker); the owner; transfer (full) |
| History | `/boards/{board}/history` | the change feed (who, what, where — click to fly there), versions, save a named version, restore (full) |
| Outline | `/boards/{board}/outline` | the board as an accessible list: frames → items, connectors, open comments. The 375 px reader and the screen reader's view |
| Comments | `/boards/{board}/comments` | all threads, open or resolved; also the rail's partial |
| Folders | `/folders`, `/folders/new`, `/folders/{folder}`, `/folders/{folder}/edit` | |
| Templates | `/templates`, `/templates/{board}` | gallery by category; preview; Use; Save as template, from a board's menu |
| Trash | `/trash` | my deleted boards (admin: everyone's), restore |
| Search | `/search?q=` | boards, items (opens the board at the item), comments |
| Notifications | `/notifications`, `/settings/notifications` | the inbox and the preferences |
| My trail · Tokens | `/my/trail`, `/tokens` | the kit's |
| Admin | `/admin`, `/admin/usage`, `/admin/settings`, `/admin/agents`, `/admin/dispatches` | usage against the budget, the defaults, the agents and their dispatches (Retry) |

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | This document and the owner's answers. **The realtime spike** (the handoff's step 5; §10.1). The schema `db/001`–`0NN`, proven on a scratch database (`db/proof/phase0_proof.sql`; the list follows the table). The kit, proven without a kernel (`tests/phase0/run.sh`). `maludb-os.json`, `os/{expert,facilitator}.md`, eight skills (five runbooks), `deploy/` with `ROOT_STEPS.sh` (the four ports, `BOARDS_LIVE_KEY`, `mod_headers`, the proxy note). The installer's `plan` clean | the owner's go |
| 1 | `docs/boards-mcp-tool-surface.md`, `docs/boards-action-manifest.md`, `mcp/action_registry.json`. The build specs `canvas-core` (the island's modules `model/`, `sync/`, `render/`, `tools/`, `ui/`; the op protocol; the SVG dialect; the bundle and `bin/check_bundle.php`), `live-relay`, `walls-and-sharing`. The slice specs | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, `members.roles`, `bd_has_right()`, the ingest bridge, the shell with the record picker and the application switcher, the search box, `/api/v1/health`, the vhost with `/live`, the worker's empty pass | hand-off replay refused; an unknown member refused; a revoked grant shuts every door within a minute; 375 and 1280 |
| 3 | The slices, in order: | each slice: screens + handlers + logging + manifest entries + tools, proven on a scratch database; canvas behaviour checked by the owner in a browser (this server has none) |
| 4 | The two MCP servers with the run-facts gate and the SVG reader; `app_roles` and the two `shares[]` admitted to the kernel's token; the registry wrapper; the two agents with their grants; the Actions-MCP contract proved against the kernel's `application_actions.register()` | from the command bar, "add a sticky for each of these five ideas in the Ideas frame" makes five stickies that appear on an open board within a second; an agent's `item_delete_batch` pauses; the Facilitator answers a mention by clustering a selection |
| 5 | The owner's `app_install.php apply` (ports pinned first), DNS and TLS with *Websockets Support* on the proxy host, the hires | **two people on two machines draw on one board and see each other's cursors and edits live; one signs out at the launcher and their socket closes within a minute; the expert adds stickies from the command bar and they appear on both screens; a mention of the Facilitator clusters a selection; the history shows all of it and restores yesterday's version; the outline reads at 375 px** |

**The Phase 0 schema proof** (`db/proof/phase0_proof.sql`) covers:

- every actor of §2 and the wall, including a Viewer capped at `comment`, an external excluded from `everyone`, and a
  department row;
- per-field last-writer-wins: two updates to different fields both survive;
- `client_batch_id` idempotent;
- NOTIFY carrying the room and `seq`, with a large batch sending `seq` alone;
- a frame's move updating its children's `bbox`;
- a region read through the GiST index;
- a deleted item taking its connectors along;
- a version, then a restore in one batch, with `before_restore`;
- compaction;
- a comment anchored to an item and to a point;
- a mention of an agent making a dispatch, and a mention of an agent without access making none;
- `search_index` honouring the wall;
- the reused tables verbatim against their siblings.

**The Phase 3 slices, in order:**

1. **Boards, folders and sharing** — the CRUD exemplar: cards with thumbnails, new, settings, share with the picker,
   favourites, recents, folders, trash, the wall.
2. **The canvas core — the first exemplar.** The island, the state load, stickies, shapes and text with the DOM-overlay
   editor, select, multi-select and marquee, move, resize, rotate, z-order, lock, copy, paste and duplicate, per-person
   undo and redo, the ops door with last-writer-wins and idempotency, **polling only**, the outline page, the bundle check.
3. **Live — the second exemplar.** The relay, tickets, rooms, `LISTEN` and fan-out, cursors, selections, drag previews,
   the soft lock, presence avatars, the agent's cursor, revocation, the fallback banner.
4. **Connectors and frames.** Attached ends with snap, elbow routing, captions, arrowheads; frames with children, the
   navigator, the minimap, fit-to-frame.
5. **Pen, images and files.** Pen, highlighter, eraser, simplification; uploads with three thumbnail sizes and level of
   detail; a PDF's first page; file cards.
6. **Comments, mentions and notifications.** Pins on an item or a point, threads, resolve, mentions, notifications, the
   outbox, the digest, K6.
7. **History, versions and restore.** The change feed with fly-to, interval and named versions, restore, compaction.
8. **Templates, duplicate and export.** Duplicate a board or a frame; the templates gallery with six seeded (retrospective,
   brainstorm, SWOT, user story map, flowchart, weekly planning); PNG of a view, frame or selection; the SVG download.
9. **Agents, search and K7.** Dispatches from mentions, the Facilitator, `items_arrange`, the search index, the two shares.
10. **Home, admin and settings.** Home, usage against the budget, settings, agents, dispatches, tokens, the trash purge.

**Size.** Thirteen specs (three build specs and ten slices), about 35 screens, 60 actions, 24 records tools and 7 activity
tools. **The canvas is the bulk, and nothing in the estate is its exemplar**, so it is not templated work. The honest
estimate:

- Phase 0 with the spike: a day;
- Phases 1–2: a day;
- slices 1–5: four to five days;
- slices 6–10 with Phase 4: two to three days;
- Phase 5 with the owner.

**Who builds what** (D16):

- **The planning model** builds the canvas and live slices (2–5) and slice 1 — a worker replicates a CRUD page well, but not
  a drag interaction it has never seen.
- **Sonnet 5.5** builds slices 6–10 and Phase 4.

### 10.1 The realtime spike (Phase 0, before the schema is final)

The spike proves the estate's one new pattern end to end on this server, with no root. It is built in a scratch directory
outside the repository on ports 9101–9107:

1. a throwaway PHP endpoint under `php -S`, which inserts a row in a scratch database and runs `NOTIFY` in the transaction;
2. the relay sketch (asyncpg `LISTEN` + `websockets`);
3. **a user-level Apache** (its own `-f` config, `mod_proxy_http` with `upgrade=websocket`, a high port), so the vhost line
   is proven without touching `/etc/apache2`;
4. two scripted WebSocket clients in Python.

It measures:

- the time from the commit to the other client's receipt, through Apache and directly;
- cursor fan-out at 20 Hz with ten clients;
- a 9 kB batch sent by `seq`;
- a socket closed within 60 s of its member's sessions ending.

The results go into the decision record's risks (§6) and into `canvas-core`/`live-relay`, and the spike code is deleted. The
owner's two-browser check of cursor feel waits for slice 3 at `boards.<domain>`.

**The owner provides** a scratch database and a login role for it. This session runs no `sudo`; the commands are in the
handoff of this session.

## 11. Extended, later, and what other applications owe

**Extended** (each its own spec, in the likely order):

- **cards and app cards** — Projects issues, Help Desk tickets and CRM deals on the canvas through K7 reads, with live status
  (v2, R7);
- **voting, the timer, reactions, follow-me** and "bring everyone to me" — presence already carries the view;
- **a view-only link** with an expiry;
- **embeds** (oEmbed);
- **Kanban frames**;
- **PDF export** (a page per frame);
- **rich inline text** (bold or a link inside a sticky — the runs are already the format);
- **CRDT text for one long text item** (a Yjs text per item; the item model unchanged);
- **mind maps**;
- **diagram shape libraries**;
- **tables**;
- **private mode**;
- **import a Miro board** (its REST export);
- **a REST API in Miro's shape**;
- **PixiJS as the renderer** if boards outgrow Canvas2D (the `render/` module alone);
- **evals** of the Facilitator's clusters against what people kept.

**Later**: talktrack and video; offline.

## 12. What the kernel, MaluDB, the plugin and the siblings owe this application

| # | Item | State |
|---|---|---|
| **K40** | **Catalog entry `boards`** (kind `ours`, Operations, category `communication` — already admitted —, `feather-pen-tool`); on request, not a default (D15) | owed: kernel `db/180`, after the owner's go |
| K41 | The installer assigns a port to `services[].port_env` (or any `*_PORT` in `env.required`) from the block, as it does `endpoints[].port_env` | optional; `ROOT_STEPS.sh` pins until then |
| K42 | An endpoint kind `realtime` (`agent_reachable: false`) in `ENDPOINT_KINDS`, so the estate's inventory shows the live door | optional; the relay is in `services[]` only until then |
| K26 | K7 names the consumer; `timeout_seconds` | **built** (db/173) |
| K6 | texts to a member | **built** — a mention's text |
| K8 | wake an agent from an application | owed to every sibling; the chat-turn dispatch until then (Spaces' route) |
| plugin | **the realtime pattern** (contract gaps 3–5): the relay as a generic room server; the ticket; the minute check against sessions and levels; the vhost line; NPM's websockets switch in the install runbook; **committed JS bundles** as a convention with a staleness check. And `shared-schema.md` gains `boards`, `board_items`, `board_ops`, `board_folders`, and the three family reuses (`board_permissions`, `board_versions`, the comments family's third shape) | for the owner to raise upstream after the spike (the handoff: Boards does not edit `maludb-os-integration`) |
| SP5 | Spaces embeds a board through `board_outline` and the thumbnail | owed by Spaces, Extended |
| P4 / HD7 / CRM6 | the app-card providers' shares | v2 |
| owner | DNS for `boards.<domain>`, TLS, *Websockets Support* on the NPM proxy host; the scratch database for the spike | Phase 0 (spike) and Phase 5 |

## 13. The decisions (recommendations; the owner's answers go in §15)

R1–R8 (§0.1) are decided and not asked again.

| # | Decision | Recommendation | Alternative |
|---|---|---|---|
| D1 | Name, label, prefix, catalog | **"Boards"**, key `boards`, **DNS label `boards`**, database `<tenant>_boards`, roles `boards_rw` / `_records_ro` / `_activity_ro`, prefix **`bd_`**; area Operations, category `communication`, icon `feather-pen-tool` | label `whiteboard`; category `documents` |
| D2 | Application roles | **Three: Viewer** (level capped at `comment`, never creates), **Member** (`user`: creates, works where shared), **Boards admin** (`full` everywhere, business templates, trash, usage, settings) | two (Member, admin) — an external then can draw on what is shared with them |
| D3 | Sharing | **Spaces' permission rows** (§0.4): people, departments and agents at view/comment/edit/full, plus one `everyone` level; **a new board is private** (`default_everyone_level = none`, an admin setting); externals only by name; folders grant nothing; **no public link in v1** | new boards open to the business at `view` (Miro's team default); a view-only link in v1 |
| D4 | Coordinates and frames | **Miro's convention** — centre, relative to the parent frame — with an absolute `bbox` kept by trigger and indexed; **frames do not nest** in v1 | absolute coordinates everywhere (a frame's move rewrites every child on the wire) |
| D5 | Text | **Item text is plain with whole-item style** (size, alignment, colour, bold), stored as the estate's rich-text runs, so inline styling is an upgrade, not a migration. **Comments have the full rich text** with mentions | inline rich text inside stickies in v1 (a canvas text-layout engine — days of work) |
| D6 | History retention | **Ops 90 days, then compacted**; interval versions 30 days, then one a day for a year; named versions forever | keep every op forever |
| D7 | Limits | **Warn at 4,000 items, refuse creates above 10,000**; a batch at most 500 ops; uploads 25 MB; a drawing at most 2,000 points; a room 100 sockets | Miro's 100,000 cap |
| D8 | Undo | **Per person, own changes only** (a client stack of inverse batches); `ops_undo` for an agent's own batches in the last hour | a board-wide undo (Figma does not, Miro does not) |
| D9 | Images and files | **png, jpg, gif, webp and svg** (an SVG drawn on the canvas as an image and served with `Content-Security-Policy: sandbox`); three thumbnail sizes by GD for level of detail; a PDF's first page by `pdftoppm`; other files as a card; 25 MB | images only in v1 |
| D10 | Agents | **Boards Expert and Facilitator, both hired on install, no duties**; the pause list of §5 | the expert alone |
| D11 | An agent's access | **An agent sees what its member id may see** — `everyone` includes agents. A mention of an agent without access asks the writer to share it first; **a mention never grants**; an agent's edits arrive live with its cursor | agents only by an explicit share, never through `everyone` |
| D12 | Templates | **In v1** (slice 8): templates are boards with `is_template`, Spaces' precedent; six seeded; personal by anyone, business by an admin (paused for agents) | v2, as the research tagged it |
| D13 | Export | **PNG of a view, frame or selection, made by the client; the SVG dialect as a download**; PDF Extended | server-side rendering (a headless browser on the server) |
| D14 | K7 | **Two shares: `boards_index`, `board_outline`**; no reads in v1 | none in v1 |
| D15 | Default, repository | **On request, not a default** install; the repository stays private until the owner says | a fifth default beside HR, Projects, Help Desk and Spaces |
| D16 | Ports and the division | **8198 / 8849 / 8850, live 8851**, scratch 9101–9107. **The planning model builds** K40, Phase 0 with the spike, Phase 1, Phase 2, and slices 1–5 (the canvas is not templated work); **Sonnet 5.5** builds slices 6–10 and Phase 4 | the Spaces division (workers from slice 4) |

**Open questions beyond the table:**

- **(a) The file name of the plan.** The handoff asked for `docs/01-phase0-plan.md` in Help Desk's pre-OS shape. This
  document follows the shape every application since Help Desk has used (`docs/<key>-design.md`, §0–§16) and covers every
  section the handoff named:
  - actors — §2;
  - the memory model — §6;
  - questions — §7;
  - actions — §5 and §9, with the eight-column manifest in Phase 1;
  - roles → rights — §2, §3 and §6.5;
  - slice order — §10;
  - the MCP surface — §7;
  - decisions — §13.

  Recommendation: keep this one file.
- **(b) The plugins.** Installing `htmx-php-builder` 0.9.0 and `maludb-os-integration` from the org marketplaces (the
  handoff's step 1) changes the Claude Code setup every session on this server shares. Recommendation: the owner decides;
  this work reads them from the local clones meanwhile.

## 14. Ports, names and files

- Repository `/srv/apps/boards` (origin `github.com/maludb/maludb-os-boards`, private); record database `<tenant>_boards`
  (`subello_boards`); roles `boards_rw`, `boards_records_ro`, `boards_activity_ro`; the tenant's memory (`certstudy_memory`)
  receives the activity episodes tagged `boards`.
- `boards.<domain>` (`vhost.label = "boards"`).
- Ports, all four in `env.required` and written to `config/.env` by `ROOT_STEPS.sh` before `apply`:
  `APP_INTERNAL_PORT=8198`, `MCP_RECORDS_PORT=8849`, `MCP_ACTIVITY_PORT=8850`, `BOARDS_LIVE_PORT=8851`.
- `deploy/` files are templates (`{{DOMAIN}}`, `{{APP_DIR}}`, `{{APP_KEY}}`, the four ports). The vhost carries
  `ProxyPass /live http://127.0.0.1:{{BOARDS_LIVE_PORT}}/ upgrade=websocket` and the upload limits (`php_admin_value` 32 MB).
- Units:
  - `boards-records-mcp`, `boards-activity-mcp`;
  - **`boards-live`**;
  - `boards-activity-ingest` (+ timer), `boards-directory-sync` (+ timer);
  - `boards-worker` (+ timer, every minute: versions, the outbox, dispatches, the search catch-up, thumbnails, the trash,
    compaction).
- `maludb-os.json`:
  - `catalog_key boards`, `name "Boards"`, `business_area "Operations"`, `category communication`, `icon feather-pen-tool`,
    `criticality medium`, `vhost.label boards`;
  - no `scopes`; `sso`; `directory {reads: true, writes: false}`; `assistant {command_bar: true, agent: expert}`;
  - `agents [expert, facilitator]`, each `hired_on_install: true`;
  - `approvals` (§5);
  - endpoints: the two MCP servers, the UI, health — **not** the live door, until K42;
  - services (the six above); `shares` (two); no `reads`.
- Env: the contract's required keys, plus `BOARDS_LIVE_PORT`, `BOARDS_LIVE_KEY`, `MALUMAIL_API_KEY`, `MAIL_FROM`,
  `MAIL_FROM_NAME`, and `BD_STORAGE_DIR` (default `storage`).
- `client/` holds the TypeScript sources, `package.json` with esbuild pinned, and `package-lock.json`. The bundle is
  `html/assets/boards/boards.<hash>.js` + `manifest.json`, committed. `bin/check_bundle.php` fails a proof when the bundle is
  older than its sources.
- Kernel files: `mcp/registries/boards.json` (made by the installer), db/180 (K40).
- Proof scratch ports 9101–9107; scratch databases `bd_dev`, `bd_proof`, `bd_spike`; fixtures `tests/fixtures/` (synthetic
  boards: a retro of 200 stickies, a 5,000-item board for timing, a flowchart; nothing of a real person).

## 15. The owner's answers

**2026-10-10 — every recommendation of §13 taken** ("I accept all recommendations, so please begin"). These are now rules:

- **D1.** "Boards", key `boards`, label `boards`, prefix `bd_`, area Operations, category `communication`, icon `feather-pen-tool`.
- **D2.** Three roles: Viewer (capped at `comment`, never creates), Member (`user`), Boards admin.
- **D3.** Spaces' permission rows with an `everyone` level; a new board is private (`default_everyone_level = none`);
  externals only by name; folders grant nothing; no public link in v1.
- **D4.** Miro's coordinates (the centre, relative to the frame) with an absolute `bbox` kept by trigger and indexed; frames do
  not nest.
- **D5.** Plain item text with whole-item style, stored as rich-text runs; comments in full rich text.
- **D6.** Ops kept 90 days, then compacted; interval versions 30 days, then daily for a year; named versions forever.
- **D7.** Warn at 4,000 items, refuse creates above 10,000; 500 ops a batch; 25 MB uploads; 2,000 points a drawing; 100 sockets
  a room.
- **D8.** Per-person undo of one's own changes; `ops_undo` for an agent's own batches within the hour.
- **D9.** png, jpg, gif, webp and sandboxed svg; three GD thumbnails; a PDF's first page; other files as cards; 25 MB.
- **D10.** Boards Expert and Facilitator, both hired on install, no duties, the pause list of §5.
- **D11.** An agent sees what its member id may see (`everyone` includes agents); a mention never grants; agents appear live
  with their own cursor.
- **D12.** Templates in v1 as `is_template` boards, six seeded.
- **D13.** PNG export made by the client, plus the SVG download; PDF Extended.
- **D14.** Two K7 shares, `boards_index` and `board_outline`; no reads in v1.
- **D15.** On request, not a default; the repository private.
- **D16.** Ports 8198 / 8849 / 8850, live 8851, scratch 9101–9107; the planning model builds K40, Phase 0 with the spike,
  Phase 1, Phase 2 and slices 1–5; Sonnet 5.5 builds slices 6–10 and Phase 4.

The open questions:

- **(a)** This file stays the plan; there is no `01-phase0-plan.md`.
- **(b)** Installing the plugins stays the owner's to decide; the work reads them from the local clones.

## 16. State

- **2026-10-10 (Mac).** Research and the tech-stack decision accepted (R1–R8).
- **2026-10-10 (dev server).**
  - Cloned to `/srv/apps/boards`.
  - The read-only server survey written (`research/dev-server-survey.md`).
  - The siblings' schemas surveyed (§0.4, §6.7: three of the record's tables follow existing families — `board_permissions`,
    `board_versions`, templates as `is_template` rows).
  - This design written.

- **2026-10-10.**
  - **The owner took every recommendation** (§15).
  - The scratch database `bd_spike` was created by the owner for the spike.

  **NEXT:** the realtime spike → K40 (db/180) → Phase 0's schema, kit, manifest, agents, skills and deploy → the owner's go →
  Phase 1.
