# Phase 4 — the two MCP servers (spec and record)

2026-10-12 · built and proven by the worker model on the planning model's tool surface (`docs/boards-mcp-tool-surface.md`, approved 2026-10-10). **Nothing here is installed:** the proofs run on a scratch
database (`bd_dev40`), ports 9104 / 9105 and the fakes; Phase 5 (the owner's `apply`) installs the units. One migration, `db/019_mcp_read_log.sql`.

## What was built

| Piece | Where |
|---|---|
| Records server — **27 tools** (the surface's table rows, every one built) | `mcp/records_server.py` (`app_roles`), tool modules `mcp/bd_boards.py` (9: `find_boards`, `board_get`, `board_access`, `find_folders`, `trash_list`, `find_templates`, `boards_usage`, `find_members`, `find_departments`), `bd_canvas.py` (10: `board_overview`, `board_search`, `board_read_region`, `frame_read`, `item_get`, `item_connections`, `find_attachments`, `board_changes`, `board_versions`, `version_outline`), `bd_comments.py` (5: `find_comments`, `comment_thread`, `my_notifications`, `agent_dispatches`, `records_search`), `bd_shares.py` (2: `boards_index`, `board_outline`) |
| Activity server — **7 tools** | `mcp/activity_server.py` (`board_trail`, `member_activity`, `record_history`, `most_active_boards`, `agent_activity`, `recent_activity`, `activity_search`) |
| The door (the kit's `server_common.py` / `db.py`, widened) | `server_common.py`: `KERNEL_TOOLS = app_roles + the two shares`, `KERNEL_ONLY = {boards_index}`, the consumer headers (K26), validation failures answered as `invalid_argument`; `db.py`: the consumer context variables, `APP_URL`, a statement timeout and the `as_nobody` switch for the one log write |
| The shared pieces | `mcp/bd_common.py`: the input base, the refusals (`answers`), `Where`, the board / member / folder / department resolvers over the views, the list envelope the kernel's resolver reads |
| The migration | `db/019_mcp_read_log.sql` (below) |
| Units, venv, root's lines | `deploy/boards-records-mcp.service`, `boards-activity-mcp.service` (Phase 0's, unchanged: they already named `records_server.py` and `activity_server.py`), `mcp/venv` (gitignored; the installer makes the real one) |
| Registry wrapper | `deploy/kernel-registry-boards.json` (Phase 1's, unchanged): five resolve entities, every one proved against the live server with the kernel's own resolver |
| Proof | `tests/phase4/run.sh` (`gate`, `walls`, `tools`, `shares`, `activity`, `registry`, `coverage`; `world.php` builds the world) |

The servers are **read-only**: every writing tool is an action of the kernel's Actions MCP, built from `mcp/action_registry.json`. No tool posts a handler; the kernel's tool factory does, with the approval hook in front, and the
proof drives it with the kernel's own `application_actions.py`. The one write the records server makes is the trail of a share read.

## The decisions (none overturns the surface)

1. **Every row is a view's or a read function's, as the caller** (`app.member_id` set transaction-locally by `db.fetch_scoped`). The tools never render SVG or Markdown: the answer of `board_read_region` / `frame_read` is
   `bd_region_svg()` byte for byte, the outlines are `bd_board_outline()` / `bd_version_outline()` verbatim. A tool resolves a **board by id or title** (`board_row`): an exact title first, then a fragment; two matches → `ambiguous`
   with up to six candidates; a title that matches a board and a template means the board.
2. **`not_found` is one sentence** ("No board you can read matches that.") for a missing record and one the wall hides — the proof asks 14 board-taking tools (records and activity) for the private board by id and by title as a
   stranger, an external, a Member with other boards and an agent shared on other boards, and for a UUID that is nowhere, and the answers are equal word for word.
3. **The two K7 shares read the database directly** (both are SQL; no door like Spaces'). The kernel's token reaches `app_roles`, `boards_index` and `board_outline` and nothing else. **Who asks** is the header
   `X-OS-Consumer-Agent` (K26) — the consuming application's expert *agent*, which must be an active, admitted agent member (`mcp_member_kind() = 'agent'`); the share runs **as that member** (`db.fetch_scoped(member_id=…)`), so the agent sees
   what it could open itself (D11). No agent named → `boards_index` answers `{rows: [], note}` and `board_outline` is refused (`not_allowed`); a person, a missing member or an unadmitted agent named → refused. An `as_agent`, `member`,
   `consumer_agent_id` or `scope_id` argument is dropped by the gate (the kernel strips it too). `boards_index` is **the kernel's alone** (`KERNEL_ONLY`: a person finds boards with `find_boards`); `board_outline` is also a person's and an
   agent's own tool, which then answers as themselves with no share envelope. A kernel answer carries `schema` (`os.boards-index/1`, `os.boards-outline/1`), `application`, `as_member`.
4. **The trail of a share read** (`bd_log_mcp_read`, db/019, SECURITY DEFINER, granted to the records role): `share.read`, source `application`, no actor, **no board id on the row** (a board's trail is for the people who can open it; a sibling's
   read is the admin's business), `after = {direction: in, application: <consumer>, tool, keys: <argument KEYS given>, rows, agent_member_id, ms, error?}` and `kernel_request_id` from `X-Request-Id`. A refused read is logged by its code. Never a title,
   a word searched or an answer. The function refuses any other action and any call that has a member; the records role still cannot read or write `activity_log` or any base table (proved).
5. **The resolvers** (`find_boards`, `find_templates`, `find_folders`, `find_members`, `find_departments`) answer `{ok, count, rows, truncated}` — the kernel reads `rows` — with the id and label fields the registry names, the **exact name
   first** when called with `q` alone. **A UUID given as `q` is an id lookup** (the kernel's resolver treats only digit strings as ids): `find_boards` and `find_templates` by board id, and **`find_boards` also finds a board in the trash by its id**
   (from `trash_list`) so `board_restore` and `trash_purge` can name it through the kernel; digits are ids for members, folders and departments. `me` is the asker.
6. **`records_search` takes words, never SQL** (`websearch_to_tsquery('simple', unaccent(q))` over `mcp_search`, boards the caller can open, templates left out); `db.run_search` (the kit's raw-SELECT door) is not used by either server.
7. **Errors are answers** with the five codes (`not_found`, `not_allowed`, `invalid_argument`, `ambiguous`, `too_large`) — and one addition for the unexpected: `server_error` (`retry: true`, no detail) so that a database fault is an answer, not an exception.
   A validation failure of the model (`limit` 0, an unknown field, a bad date) is `invalid_argument` naming the `fields`, never echoing what was sent. The gate's own refusals stay ToolErrors (the contract's).
8. **The caps.** A region or a frame: at most 400 items (`limit`) and 64 kB of SVG — over the bytes, `too_large` with "narrow the box"; truncated at `limit`, `truncated: true` and a note. An outline (live or a version's): at most 256 kB, else
   `too_large`. `board_search` ≤ 50, lists ≤ 100, `offset` ≤ 10,000. `item_get` gives a long text whole (the dialect cuts at 500) and counts a drawing's points instead of listing them; no tool returns a storage path, a thumbnail path or a token hash.
9. **The activity server reads the local log** (`mcp_activity_log`), not the tenant's MaluDB: the log is the wall's and the memory has none; the same rows are shipped there by `activity_ingest.py` (Spaces and Shareholder Intelligence made the same
   decision). An event that concerns **no board** (a folder, a token, a sign-on, a directory sync, a sibling's share read) is shown only to its actor and to a Boards admin — the view admits it to every insider, the tools narrow it.
   `member_activity` is boards-only; `record_history` answers board, comment (by the id in the payload) and folder (the folder's own wall; a deleted or hidden folder only to what one did oneself).
10. **The agents' grants** (`maludb-os.json`): every tool granted exists on the server it is granted on; every granted action is a built action of the registry; neither agent is granted a share, `boards_usage`, `agent_dispatches` or (the
    Facilitator) `board_access`; the Facilitator is granted no action that shares, transfers, publishes, restores or administers. **Finding for the owner:** the brief and the design say the Facilitator deletes nothing, but `maludb-os.json`
    grants it `item_delete_batch` — an action that pauses for a **deletion** approval (the kernel's hook asks before the handler), so it deletes only after a super-admin agrees. The proof states exactly that; removing the grant is one line.

## Defects found and fixed here

- **`bd_outline_of()` wrote ` — on ""`** after an open comment pinned to a *point* of the canvas (no item: the LEFT JOIN gave an empty label, not NULL) — in the live outline, a version's and the share. Fixed in db/019 (the anchor is named only when
  there is one).
- **`mcp_activity_log` had no `before`**, but the surface's `record_history` answers `{at, member_name, action, before, after}`: appended last in db/019 (additive), the wall unchanged. The activity role also reads `mcp_folders` now (a folder's history
  applies the folder's own wall).
- **A trashed board could not be named through the kernel**: `board_restore` and `trash_purge` take a `board`, the kernel resolves any non-digit value through `find_boards`, and the trash is not in `mcp_boards`. `find_boards` now answers a trashed
  board named by its UUID to its owner and to a Boards admin.
- **A title that matched a board and a template was ambiguous** ("SMOKE p4 Roadm" → the board and its template): a board wins.
- Proof-side: the world's first version shared boards with the schema function, which writes no trail; it now shares through the handler, so the trail has what a real one has.

## What the kernel owes (found in Phase 4; nothing here waits on it)

- **A repeated parameter is split on commas** (`mcp/application_actions.py`, `fields_out[pname + "[]"] = [v.strip() for v in str(value).split(",")]`): `item_create_batch.texts` — an agent's sticky text cannot contain a comma
  ("one, two" becomes two stickies; proved, and the handler makes the two it is sent). The ids lists (`items`, `around`, `context`) are unaffected. Reported, not changed.
- **A resolve entity's params are resolved registry-wide**: no Boards param named like an entity means anything else (checked over all 45 actions), so nothing is renamed here — unlike Shareholder Intelligence's `source`.
- **K55 tool names:** against the 13 applications installed on this host, 19 of Boards' 45 actions share a bare name with another application's and would be served as `boards_<action>` (`comment_delete`, `comment_edit`,
  `comment_resolve`, `dispatch_retry`, `file_upload`, `folder_create`, `folder_delete`, `folder_update`, `notification_read`, `notifications_read_all`, `prefs_save`, `settings_save`, `template_publish`, `template_save`, `token_mint`,
  `token_revoke`, `trash_purge`, `version_restore`, `version_save`). The agents' grants in `maludb-os.json` name the bare actions; the hire script maps them through the installed registry's tool names (K55) — to be seen at Phase 5's hire.
  The proof composes the registry the way the installer will (the kernel's own `assign_registry_tool_names()`), registers all 45 and drives them under those names.
- A K7 consumer that reads `boards_index` / `board_outline` needs the owner's approved connection (`bin/app_connection.php`) — Phase 5.

## Proof (the counts)

`tests/phase4/run.sh` on a fresh scratch database: **466 checks under php -S, 474 under a real Apache** (`BD_APP=apache`; then also through the vhost's own `/mcp/records` and `/mcp/activity` lines, the consumer
headers crossing the proxy).

| Proof (php -S / Apache) | What it holds |
|---|---|
| `world.php` | the world, through the handlers: the Roadmap (frames, stickies, a shape, text, a connector, a pen drawing, an uploaded image, a deleted sticky, a version, changes by web / command bar / agent, five comment threads, a dispatch), a private board, department and everyone boards, templates, folders, a trashed board, a board whose owner left, 450- and 3,200-item boards |
| `gate` (79 / 85) | tokens refused / admitted; a person's tools (26 records + 7 activity); an agent exactly its run facts' tools; fail closed (invalid, no endpoint here, unknown run, **the fake kernel stopped**); first-contact admission; an eval run reads and writes nothing; the kernel's token reaches three tools; `app_roles`; every field described, `extra="forbid"`, `invalid_argument`; the agents' grants exist |
| `walls` (98 / 98) | 14 tools × 4 askers: a stranger's `not_found` is a missing id's; levels (owner, admin, editor, Viewer capped at comment, external, agents); an external sees only themself; the asker's own (notifications, trash, dispatches); the admin's usage; comments (mentions, unanswered, open); the resolvers' shape; no text of the private board by any door; the activity wall; no path of the store |
| `tools` (106 / 106) | every records tool against the world and the database: `find_boards` filters, `board_get`, the overview, search, **the SVG dialect parsed as XML** (frames first, frame-relative children, absolute `data-cx`, connectors, 500-character text, no deleted item, no drawing points, 400-item truncation, 64 kB `too_large`), `frame_read`, items and connections, files, changes, versions, threads, the outline and its 256 kB cap |
| `shares` (42 / 44) | `boards_index` / `board_outline` as the kernel's token with the consumer headers; row for row the views'; no agent, a person, a missing member, an unadmitted agent; `as_agent` ignored; the `share.read` rows; `bd_log_mcp_read` and the records role's walls |
| `activity` (42 / 42) | the seven activity tools against the log, the wall, the payload rule |
| `registry` (91 / 91) | the wrapper and the registry in the kernel's own loader (45 tools, K55 names), five resolve entities through the kernel's resolver, 24 whole actions through the kernel's tool factory with the hook and handler stubbed, and 11 of the posts replayed against the real handlers as an agent |
| `coverage` (8 / 8) | every one of the 34 tools called; every question Q1–Q18 and A1–A6 has a tool that exists and was called |

`db/019` is a file outside `mcp/`, `tests/phase4/`, `deploy/` and `docs/`, so the full chain was re-run: `tests/phase0/run.sh`, `tests/phase0/schema.sh`, `tests/phase1/check_specs.sh` and `tests/phase3/slice10/run.sh` (slices 9 … 1 and Phase 2 within it) under php -S and under Apache — results in the commit message's report.
