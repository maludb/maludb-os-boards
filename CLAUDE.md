# Boards — notes for Claude

A Miro-style whiteboard, an application of the MaluDB Business OS (catalog key `boards`).

- Start every new session by reading [docs/HANDOFF.md](docs/HANDOFF.md) (current state and next steps) and the accepted
  decision record [docs/research/tech-stack-decision.md](docs/research/tech-stack-decision.md).
- Build method: the `htmx-php-builder` plugin (`os-application` first) under the `maludb-os-integration` contract; the
  integration plugin wins where they disagree.
- Exception to the default stack, already decided: the board canvas is a TypeScript + Konva.js island (bundle built with
  esbuild in `client/`, committed under `html/assets/boards/`), and live collaboration goes through a Python WebSocket
  relay that never writes board data. Everything else is PHP + HTMX + Bootstrap 5.3 nxl.
- The user runs all `sudo` commands; ask for them. **One standing exception (the owner, 2026-10-10):** `sudo -n -u postgres` for
  scratch `bd_*` databases and the three `boards_*` roles in the proofs, and for applying the kernel's Boards migration (K40).
  Nothing else — no Apache, systemd, `/etc` or the installer's `apply`.
- The plan is [docs/boards-design.md](docs/boards-design.md) (§0–§16, the estate's shape; the owner's answers go in §15).
  Ports: `APP_INTERNAL_PORT=8198`, `MCP_RECORDS_PORT=8849`, `MCP_ACTIVITY_PORT=8850`, `BOARDS_LIVE_PORT=8851`, scratch
  9101–9107 (8194–8197 are txtSchedules' proof servers). Prefix `bd_`. Kernel item K40 = the catalog row (db/180).
- Tables follow the estate's families (`shared-schema.md`): `board_permissions` is Spaces' `page_permissions` shape,
  `board_versions` is `page_versions`, templates are `boards.is_template` rows, comments are Spaces' family. New canonical:
  `boards`, `board_folders`, `board_folder_entries`, `board_items`, `board_ops`.
- The plugins on this server: Claude Code registers only `htmx-php-builder` 0.4.1; read the skills from the local clones
  `~/htmx-php-builder` and `~/maludb-os-integration` (both 0.9.0).
- Proofs, one after the other (they share ports 9101–9103): `tests/phase0/run.sh` (the kit), `tests/phase0/schema.sh` (the schema),
  `tests/phase1/check_specs.sh` (the specs' claims), `tests/phase2/run.sh` and `BD_APP=apache tests/phase2/run.sh` (the shell, under php -S and
  under a user-level Apache rendering the deploy vhost — no root), `tests/phase3/slice1/run.sh`, `tests/phase3/slice2/run.sh`,
  `tests/phase3/slice3/run.sh`, `tests/phase3/slice4/run.sh`, `tests/phase3/slice5/run.sh`, `tests/phase3/slice6/run.sh`, `tests/phase3/slice7/run.sh`, `tests/phase3/slice8/run.sh`, `tests/phase3/slice9/run.sh`, `tests/phase3/slice10/run.sh`, `tests/phase4/run.sh` (Phase 4, the two MCP servers: scratch `bd_dev40`, the servers on :9104/:9105, the fake kernel stopped for the fail-closed proofs; also with `BD_APP=apache`, then through the vhost's own `/mcp/records` and `/mcp/activity`) (each also with `BD_APP=apache`; slice 5 needs `pdftoppm`; slice 3 needs `mcp/venv` — `python3 -m venv mcp/venv && mcp/venv/bin/pip install -r
  mcp/requirements.txt`, gitignored, the installer makes the real one). A slice's suite re-runs every earlier one (`BD_SKIP_EARLIER=1` skips that
  while working). After a synthetic CDP pinch, Chromium turns no Playwright `tap()` into a click: a proof waits for a tap's effect, and clicks there. Kill a proof's processes by pid
  (`pkill -f` matches the shell that runs it); the boards_* roles are cluster-wide, so only the newest scratch env's password works.
- The relay (`live/relay.py`) is a RELAY: it connects as the records role and never writes; every board write stays a PHP handler and
  `bd_commit_ops()`. Its tickets are single use (with a nonce); `docs/build-specs/live-relay.md` is normative. A scratch database has live
  updates OFF unless `BD_LIVE=1` (`tests/setup_dev.sh`): suites without a relay prove the polling canvas; slice 3's (and later live ones) set it.
- Files (slice 5): a browser reaches a file only through `GET /files/{id}` (`html/files/view.php`, the board's wall); `attachments.storage_path`
  and `thumbnails` are relative to the store; a file leaves the disk only through `unlink_paths()` (`app/features/files/store.php`, inside the
  store only); an SVG is always served sandboxed and, at its plain URL, as an attachment (`?inline=1` is the canvas's `<img>`).
- The canvas is `client/` (TypeScript + Konva; Node is a development tool here only): after any change under `client/src/` run
  `node client/build.mjs` and commit `html/assets/boards/` with it — `php bin/check_bundle.php` fails a stale bundle, and every slice's proof runs
  it. How an item is drawn, how a tool is added and what slice 3 replaces: `docs/build-specs/canvas-core.md` "Built in slice 2".
- Every board page, door and handler starts with `bd_board_or_404($pdo, $board, <level>)` (`app/features/boards/wall.php`): the database
  decides the level and answers 404/403 in its own words through `bd_guard()`. A slice's first step on a screen is removing its
  `render_nav_stub()` call; the Phase 2 suite follows the placeholders still owed.
- Comments and notifications (slice 6): a comment's text is plain text with `@Full Name` mentions turned into runs by `comment_text_to_rich()` (a name two records share stays text); a mention never grants — the
  needs-share prompt posts slice 1's `board_share`; the thread is ONE partial (`app/views/comments/partials/thread.php`) for the comments page and the canvas's rail, answered again by every comment handler
  over HTMX with `HX-Trigger: commentChanged`; pins are a DOM layer read every 15 s (`sync/pins.ts`), never ops; the Comment tool is Shift+C. The worker's `outbox` pass (`app/features/worker/outbox.php`) is
  Spaces' port: email at most once, K6 texts retried with a backoff, the digest at 07:00 in the member's zone. A suite that dies on a parse error shows only in its exit status: `php -l` every test file you touch.
- History and restore (slice 7): a restore is `bd_restore_version()` — the board as it is saved first (`before_restore`), then ONE `bd_commit_ops()` batch (source `restore`) — never a second write path; a version's preview is its outline (`bd_version_outline()` through `outline_html()`, escaped), never a canvas; the handlers ask the right before the board; the worker's `compaction` runs once a night at 03:xx in the business's zone and records the local date it ran (`worker_passes.started_at` is the real clock even under `BD_WORKER_NOW`); a jsonb `?` operator in a PDO statement is read as a parameter — use `jsonb_exists()`.
- Templates and export (slice 8): the six shipped templates are `db/016_seed_templates.sql` (ids `7e…`, never changed, no search rows); every copy — a duplicate, a template's use, a personal or business template — is `bd_board_duplicate()` and nothing else; the board's SVG is `bd_region_svg()` (never a second renderer, a whole board past 400 items is refused 422, never truncated); a PNG is drawn by the island into a canvas of its own (`ui/export.ts`) and the server only logs it (`POST /boards/{board}/export`).
- Agents, search and the siblings (slice 9): a mention of an agent is a `bd_comment_create()` dispatch row, and the worker's `dispatches` pass (`app/features/agents/dispatch.php`) turns each into ONE turn of the kernel's chat endpoint through the kit's `kernel_call()` as the agent, the asker acting — the placeholder, the reply as one comment, the backoff (2, 4, 8, 16 minutes, five calls) and who is told are `bd_dispatch_record()` (db/018), never PHP; pass the clock to the database with its microseconds (a `next_attempt_at` set a moment ago is not due at the same second). `items_arrange` is one `bd_commit_ops()` batch of updates over `bd_layout()`; search is `mcp_search` joined to `mcp_boards` (NOT is_template) and `ts_headline` with control-character markers escaped before they become `<mark>` — Postgres drops the markup a person typed from an excerpt. The fake kernel answers `GET ?run=` from `state.runs` and logs every call to `<state>.chat`.
- Home, admin and settings (slice 10 — the last): the home is `home_sections()` over the walled views (eight cards a section, first run with the six shipped templates); the admin's page shows each section to its own right (`require_any_right()`); the budget bar's colours are `budget_status()` (soft budget amber, within 10 % of the hard cap red); the settings form checks with the CHECKs' numbers and the database stays the referee (23514 shown on the field its constraint names); a purge is `bd_board_purge()` then `unlink_paths()` and nothing else, by the admin's `trash_purge` or the worker's `trash` pass (at `BD_WORKER_NOW`, board deleted longer ago than `trash_days`). No placeholder (`render_nav_stub()`) and no worker stub is left; the Phase 2 suites follow the source.
- The MCP servers (Phase 4): `mcp/records_server.py` (27 tools) and `mcp/activity_server.py` (7), the tools in `mcp/bd_{boards,canvas,comments,shares}.py` over `mcp/bd_common.py`; read-only, every row a view's or a `bd_*` read function's as the caller; the SVG
  dialect and the outlines are `bd_region_svg()` / `bd_board_outline()` verbatim (a tool never renders). The two K7 shares (`boards_index`, `board_outline`) read the database directly **as the consuming application's expert agent named in `X-OS-Consumer-Agent`**
  (K26; an `as_agent` / `member` argument is dropped; no agent named → the index empty, the outline refused); `boards_index` is the kernel's alone (`KERNEL_ONLY`); the only write is `bd_log_mcp_read()` (db/019: `share.read`, no actor, no board id, argument keys only). A UUID given as
  `q` to a resolver is an id lookup (`find_boards` also finds a trashed board by id, so `board_restore` / `trash_purge` resolve); `not_found` is one sentence for a missing record and a hidden one; errors are answers (`not_found`, `not_allowed`, `invalid_argument`, `ambiguous`,
  `too_large`, and `server_error` for the unexpected). `docs/build-specs/mcp-servers.md` is the record. A new tool gets a call in a `tests/phase4` proof (`coverage.php` fails an uncalled one) and a line in the surface.
