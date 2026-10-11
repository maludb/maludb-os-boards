# Worker brief — Boards slices 6–10 and Phase 4 (Sonnet 5.5)

You build ONE slice of Boards from its build spec. The planning model built Phase 2 and slices 1–5 (slice 1 the CRUD exemplar, slices 2 and 3 the
canvas and live exemplars) and verifies and commits your work. Follow this brief exactly; the spec is the contract.

## Where and what you may touch
- The repository is `/srv/apps/boards` (main branch, NOT installed — a scratch clone; `config/.env` has no DB_PASSWORD). Work in it directly.
- **Never commit, never push, never `git stash`/`reset`/`checkout` away anyone's work.** Leave every change in the working tree; the planning model
  reviews the diff and commits.
- Never touch `/var/www` (the kernel) or another application under `/srv/apps/*` — read them for reference only. Never run `app_install.php`.
- Databases: only the scratch `bd_dev<N>` your proof makes. `sudo -n -u postgres` is allowed for scratch `bd_*` databases and the three `boards_*`
  roles only — nothing else under sudo (no Apache, systemd, `/etc`).
- Never put a secret, a token or a transcript in a file.

## Read before writing any code
1. `CLAUDE.md` and `docs/HANDOFF.md` (the rules and the lessons of slices 1–5).
2. Your slice's spec in `docs/build-specs/` — every section: divergences, who may do what, screens, files ("exactly these"), query functions,
   tools, manifest rows, log events, status vocabulary, out of scope, proof, decisions, open questions.
3. The manifest rows it claims in `docs/boards-action-manifest.md`, the tools in `docs/boards-mcp-tool-surface.md`, and the design sections it
   names in `docs/boards-design.md`; `docs/phase1-consistency.md` for decisions already taken.
4. The schema it names (`db/*.sql`) — read the functions and triggers you call; the database is the referee (the wall, the levels, refusals).
5. The exemplars, binding on shape and idiom:
   - slice 1 (CRUD): `app/features/{boards,folders,sharing}`, `html/boards`, `html/folders`, their views, `tests/phase3/slice1/`;
   - slice 2 (the canvas island): `client/src/` (`docs/build-specs/canvas-core.md` "Built in slice 2" — how an item is drawn, how a tool is
     added), `app/features/canvas`, `app/features/items`, `tests/phase3/slice2/`;
   - slices 4 and 5 for the latest proof idiom (`tests/phase3/slice{4,5}/` — lib.php building on the previous slice's, per-area PHP proofs,
     `browser.mjs`, `run.sh`), and for the worker's passes (`bin/worker.php`, slice 5's `thumbnails`);
   - the kit: `app/handler.php`, `app/http.php`, `app/auth.php`, `app/bootstrap.php`, `app/db.php`, `app/activity.php`, `app/picker.php`,
     `app/mail.php`, `app/views/layout.php`, `app/features/boards/wall.php`;
   - each finished spec's "Built and proven" section (e.g. `docs/build-specs/pen-files.md`) for decisions already taken.
6. The builder plugin from its clone (the installed copy is stale): `~/htmx-php-builder/skills/php-patterns/`, `design-system/` (incl.
   `references/record-picker.md`), `malumail-send/` when you send mail, `mcp-servers/` for Phase 4, `chat-actions/` for the manifest; the contract
   from `~/maludb-os-integration`.

## The kit's idiom (must match)
- A write handler: `bd_handler_begin()` → `bd_board_or_404($pdo, $board, <level>)` (the gate AND the level: the database decides and a hidden board
  is the same 404 as a missing one — there is no separate `bd_require_level()`) → `has_right()` / `require_human()` where the spec says → field
  readers collecting `$errors` → refuse the fields → `bd_guard($pdo, fn)` (a transaction + `log_activity()`; it turns the schema's refusals into
  422/404/403 with the schema's sentence) → `bd_done(message, id, land, 'hxEvent', extra)`. Every state-changing handler is POST + CSRF +
  authorization + `log_activity()`; every log row about a board carries `board_id`.
- A screen: `require_login()`, `log_screen_view()`, `wants_json()` → `respond_screen()` from a whitelist presenter in `present.php` (never raw rows),
  else the page through `view()` and the layout. A slice's first step on a screen is removing its `render_nav_stub()` call.
- `view()` uses `extract(EXTR_SKIP)`: never pass data keys named `template`, `data`, `path` or `viewsRoot`.
- Full pages, no modals (the record picker and `hx-confirm` are the only exemptions); works at 375 px with no sideways scroll; a form chooses a
  record through the record picker, never a `<select>` of rows; lists of named things are cards.
- Words, not codes, on screen; the spec's status vocabulary and element ids exactly.
- A body, a comment's text, a password or a token never goes into an activity-log payload.
- Match the surrounding code's comment density and naming. Constants are global — check for a clash first.
- The canvas: after any change under `client/src/`, `node client/build.mjs`; the rebuilt `html/assets/boards/` is part of your diff and
  `php bin/check_bundle.php` must pass.
- The relay (`live/relay.py`) is a relay: it never writes. Every board write is a PHP handler and `bd_commit_ops()`.

## Migrations
`db/001`–`db/014` are never modified. A schema defect you find gets a NEW numbered migration (the next free number, `db/015_<what>.sql`, additive,
views keep `security_barrier`, grants checked afterwards), applied by `tests/setup_dev.sh` with the rest, recorded under "Found and fixed". Re-run
the schema proof (`tests/phase0/schema.sh`) after it.

## Ambiguity
If the spec is silent on a detail that changes nothing outside the slice (wording, layout, an order), take the reading closest to the exemplars and
the design and record it under "Decisions taken here". If an answer would change the schema's meaning, a right, a level, the manifest, the tool
surface, or contradicts another spec — STOP: end your turn with a report beginning `STOPPED:` and the exact question; you will be resumed with the
answer. Never improvise around a gap.

## Proof (the slice is not done until all of this is green)
- `tests/phase3/slice<N>/run.sh` modelled on slice 5's: a fresh scratch `bd_dev<N>` (env `/tmp/bd-dev<N>.env`, state `/tmp/bd-dev<N>-state`, shots
  `/tmp/bd-shots-s<N>`), the bundle check, `tests/setup_dev.sh`, `tests/phase2/servers.sh` (app :9101, fake kernel :9102, fake MaluDB/MaluMail
  :9103, Apache :9107), the directory sync, a seed, one PHP proof per area plus `json.php` (every screen and handler in JSON mode, the log
  vocabulary), `browser.mjs` (Playwright from `/var/www/web/node_modules`: the slice's screens at 375×740 and 1280×800, no sideways scroll, no
  console error), the registry counts and `--check`; then the previous slice's suite (which runs every earlier one; `BD_SKIP_EARLIER=1` skips it
  while working). Everything the spec's Proof section lists is a check.
- Run it under php -S AND `BD_APP=apache`; both must pass.
- Look at the screenshots (the Read tool shows images) — viewport shots — and fix what looks wrong (overflow, truncation, overlap).
- `php bin/build_action_registry.php` after the manifest rows are built; `--check` green.
- An earlier proof that asserted this slice UNBUILT (a placeholder, a stub — e.g. Phase 2's worker stub count, `tests/phase2/shell.php`) is changed
  to follow whether it is built, never deleted.
- `tests/phase0/run.sh`, `tests/phase0/schema.sh` and `tests/phase1/check_specs.sh` stay green.

## Lessons from slices 1–5 (they cost hours)
- Never run two suites at once (they share ports 9101–9103/9107). Do not pipe `servers.sh start` into another command.
- **Do not add or rename files under `html/` or `client/src/` while a suite is running** — the registry and bundle checks of the running chain
  read the tree and fail. Finish editing, then run.
- The `boards_*` roles are cluster-wide: each `setup_dev.sh` resets their passwords, so only the newest scratch env works.
- Kill a stuck proof's processes by exact pid (`ps -eo pid,cmd`), never `pkill -f`/`pgrep -f` (they match your own shell and kill it).
- After a synthetic CDP pinch, Chromium turns no Playwright `tap()` into a click: wait for a tap's effect, and use `click()` there.
- `php -l` does not catch a missing `require_once`; a 500 in the server log usually is one. The run checks the server log — read it.
- A psql command without the scratch env's password prompts and hangs: always source the env first.
- Other sessions run proofs on this host on other ports (Data Lake 9001–9007, Sales CRM 8801–8807, …) — not yours; leave them alone.
- Slice 3's suite needs `mcp/venv` (gitignored; `python3 -m venv mcp/venv && mcp/venv/bin/pip install -r mcp/requirements.txt` if missing) and
  slice 5's needs `pdftoppm`.

## Docs (in the same working tree)
- The spec gains `## Built and proven (<date>)` in the form of `pen-files.md`'s: what was built, **Proven** with each proof's count, **Found and
  fixed**, **Decisions taken here (within the spec)**.
- `docs/build-specs/canvas-core.md` "Built in slice <N>" if the island changed; `docs/phase1-consistency.md` the next item and State;
  `docs/boards-design.md` §16 a new entry in the form of slice 5's; `README.md`'s State, `docs/HANDOFF.md`'s progress line, and `CLAUDE.md`'s
  proofs list (add your run.sh) and any lasting rule.

## Your final report (your last message)
1. `DONE` or `STOPPED: <question>`.
2. Files added and changed (a list), migrations added and why.
3. Each proof's count under php -S and under Apache; the registry's screens/actions built.
4. Every earlier suite re-run and its result (both servers).
5. Decisions taken; defects found and fixed; anything in the spec NOT done, and why.
6. A draft commit message in the repository's style (`Boards: slice <N> … built and proven (<date>) — …`, one long paragraph like slice 5's).
