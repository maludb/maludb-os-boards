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
  `tests/phase3/slice3/run.sh`, `tests/phase3/slice4/run.sh`, `tests/phase3/slice5/run.sh`, `tests/phase3/slice6/run.sh` (each also with `BD_APP=apache`; slice 5 needs `pdftoppm`; slice 3 needs `mcp/venv` — `python3 -m venv mcp/venv && mcp/venv/bin/pip install -r
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
