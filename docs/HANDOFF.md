# Handoff — Boards, research → Phase 0 on the dev server

Written 2026-10-10 at the end of the research session (Claude Code on the user's Mac). The next session runs on the
**Ubuntu 24.04 dev server** after the user clones this repository there. Read this file, then the two research
documents, before doing anything else.

## Progress on the dev server (2026-10-10, the first session there)

- Step 1 (install the plugins): **not done** — it changes the Claude Code setup every session on this server shares; the
  owner's call. The skills were read from the local clones `~/htmx-php-builder` and `~/maludb-os-integration` (0.9.0).
- Step 2 (survey): **done** — [research/dev-server-survey.md](research/dev-server-survey.md). Contract gap 2 answered: the
  installer assigns only `APP_INTERNAL_PORT` and `endpoints[].port_env`; `BOARDS_LIVE_PORT` is pinned by `ROOT_STEPS.sh`.
- Step 3 (shared schema): **done** — [boards-design.md](boards-design.md) §0.4 and §6.7. Three of the record's tables follow
  existing families (`board_permissions`, `board_versions`, templates as `boards.is_template`).
- Step 4 (the plan): **done** as [boards-design.md](boards-design.md) — the estate's `<key>-design.md` shape rather than
  `01-phase0-plan.md` (open question (a) there). Awaiting the owner's answers to D1–D16.
- Step 5 (the realtime spike): **done** — decision record §6.1. Every check passed; the code was deleted.
- After the handoff: the owner took every recommendation (design §15); K40 was applied (kernel db/180); **Phase 0 is
  complete** (design §16). Phase 1 was written and **approved by the owner as written** (2026-10-10); step 1 stays
  undone at the owner's word (the plugins are read from the clones). **Phase 2, the shell, is built and proven** (`tests/phase2/run.sh`).
  **Slice 1 (boards, folders and sharing — the CRUD exemplar) is built and proven** (`tests/phase3/slice1/run.sh`). **Slice 2 (the canvas — the
  first exemplar) is built and proven** (`tests/phase3/slice2/run.sh`, 2026-10-11). **Slice 3 (live — the second exemplar, the relay) is built
  and proven** (`tests/phase3/slice3/run.sh`, 2026-10-11). Next: slices 4 and 5.

## Where things stand

- **Done:** research and the tech-stack decision. The user accepted every recommendation on 2026-10-10.
  - [research/tech-stack-decision.md](research/tech-stack-decision.md) is the **accepted** decision record: the
    architecture diagram, the stack table, the data and sync model, the reused tables, the five OS-contract gaps, the
    risks and the checkpoint answers. **Do not re-litigate it.**
  - [research/miro-and-whiteboard-landscape.md](research/miro-and-whiteboard-landscape.md) is the evidence: Miro's
    features with MVP/v2/Later tags, Miro's architecture, the open-source options, the PHP realtime routes, and what the
    estate already says.
- **Not done:** no code, no `maludb-os.json`, no Phase 0 plan. Nothing has been surveyed on the dev server yet. The
  research session never had access to it.
- **Decisions in one line each** (detail in the decision record):
  - The canvas is a TypeScript + Konva.js island with a committed esbuild bundle. Every other screen and panel is PHP +
    HTMX + Bootstrap 5.3 nxl.
  - Board objects are rows in `board_items`, shaped like Miro's REST items. Sync is server-authoritative with
    per-property last-writer-wins. Ops are sequenced in `board_ops`. There is no CRDT in v1.
  - All writes go through PHP handlers that are in the action manifest, so the kernel's agents use the same path.
    Handlers `NOTIFY` after commit.
  - A Python asyncio WebSocket relay `LISTEN`s and fans out committed ops. It also relays cursors, presence and drag
    previews, which are never persisted. It never writes board data. If it is down, clients fall back to polling
    `ops?after=` every 3 s.
  - Live cursors and live edits are in v1.
  - The catalog key is `boards`, scope kind `none`, with access per board through `board_members` plus department
    grants.
  - The v1 objects are sticky, shape, text, connector, frame, pen, image/file and comment pins. Cards linked to Projects
    issues are v2.
  - Records MCP reads a board as search → region read in an SVG dialect, modelled on Miro's own MCP. There are no
    full-board reads.

## How the user works now

- The user drives Claude Code **on the server over SSH**. Commands run there directly; there is no Mac-side proxy.
- **The user runs every `sudo` command.** That includes the kernel installer (`sudo php /var/www/bin/app_install.php
  plan|apply …`), Apache module changes, and systemd installs. Ask for them; never assume passwordless sudo.
- **This session has no browser.** Canvas behaviour (drawing, dragging, two people's cursors) is checked by the user in
  their own browser at `https://boards.<dev-domain>`. When something needs eyes, say exactly what to open and what
  should happen. A headless Playwright check on the server is an option later; it is not set up.
- **Node is a development tool only.** Install Node 22 on the dev server if it is missing (user runs sudo) to build
  `client/` with esbuild. The installed application never runs npm or Node: the bundle is committed under
  `html/assets/boards/`.

## Next steps, in order

1. **Install the two plugins on the server** if they are not there:
   ```bash
   claude plugin marketplace add maludb/maludb-os-htmx-php-guidelines
   claude plugin install htmx-php-builder@maludb-os-htmx-php-guidelines
   claude plugin marketplace add maludb/maludb-os-integration
   claude plugin install maludb-os-integration@maludb-os
   ```
   Where the two plugins disagree, `maludb-os-integration` (v0.9.0 at the time of writing) is the kernel's word.
2. **Read-only survey of the dev server.** Record the results in `docs/research/dev-server-survey.md`:
   - Apache version, which must be ≥ 2.4.47 for `ProxyPass … upgrade=websocket`, and whether `proxy_wstunnel` /
     `proxy_http` are enabled.
   - PostgreSQL 17 and the MaluDB extension version, and how the tenant MaluDB API is reached.
   - Kernel version and commit under `/var/www`. Read `bin/app_install.php` to answer contract gap 2: does the installer
     assign an arbitrary `*_PORT` key listed in `env.required`, or only the known ones? `BOARDS_LIVE_PORT` depends on
     the answer.
   - The installed apps under `/srv/apps/*` and the port blocks they hold.
   - Whatever is in front of Apache, and whether it passes `Upgrade` headers (contract gap 3).
   - Whether Node 22 is installed.
3. **Shared-schema survey** (`shared-schema.md` in the integration plugin):
   - Read the exact DDL of the tables we reuse from the siblings on disk (`/srv/apps/*/db/*.sql`), or from GitHub if a
     sibling is not installed.
   - The Spaces tables in `maludb-os-spaces`: `db/011_comments.sql` (`comments`, `comment_mentions`),
     `db/012_notifications_files_agents.sql` (`attachments` UUID variant, `notifications*`), `db/013_search.sql`.
   - The kernel contract tables, which HR is the reference for.
   - Classify every table as `read`, `reuse` or `new`.
4. **Phase 0 plan → `docs/01-phase0-plan.md`.** Follow `htmx-php-builder:new-app` Phase 0 and `os-application`
   "Decide first".
   - Sections: actors (people by board role, OS agents, system), memory model, the question inventory (record and
     activity), the 8-column action inventory, roles → rights, slice order, MCP tool surface, and checkpoint decisions.
   - Model the structure on Help Desk's plan, in `maludb/maludb-os-helpdesk` (`docs/01-phase0-plan.md` in that repo).
   - Stop for the user's checkpoint before Phase 1.
5. **Realtime spike, before the Phase 1 schema is final.** This proves the one new pattern in the estate.
   - The chain to build: a throwaway PHP endpoint that inserts a row and runs `NOTIFY`, a Python `websockets` +
     `psycopg` relay behind Apache `upgrade=websocket`, and two browser tabs.
   - Measure the time from commit to the other tab seeing it, and the cursor feel at 20 Hz.
   - Then check that a kernel sign-out (`member_sessions.ended_at`) closes the socket.
   - Write the result into the decision record's risks section, and delete the spike code.

## Things to watch

- These rules are non-negotiable (`os-application` skill):
  - no login form;
  - no model key or model call in the app;
  - no connection to the kernel's database;
  - no migration that depends on a sibling being installed;
  - ports, hosts and keys come from `config/.env`;
  - fail closed on agent grants.
- The relay is a **relay**. If a design step tempts you to let it write `board_items`, stop. That would create a write
  path the kernel's actions server cannot reach, and it would bypass `log_activity()`.
- Log one activity entry per committed op batch, never per drag frame. Coalesce text edits.
- Boards is the **first estate application with a live socket**. Spaces (`docs/spaces-design.md`, "Extended")
  expects to reuse the pattern, so keep the relay generic: rooms keyed by a string, no board semantics in the transport.
- The five contract gaps (decision record §5) are for the user to raise upstream. Do not edit `maludb-os-integration`
  from this repository.

## Skills for the next session

- `htmx-php-builder:os-application`: always, first.
- `htmx-php-builder:memory-first-planning` and `htmx-php-builder:new-app`: Phase 0 plan and the build order.
- `maludb-os-integration:os-integration`: the contract. Its references are `registration.md`, `shared-schema.md`,
  `mcp-and-api.md`, `sign-on-and-directory.md`, `memory.md`, `roles-and-rights.md`, `agents.md` and
  `testing-without-a-kernel.md`.
- `maludb-os-integration:os-install`: when the first `plan` run of the installer is due (Phase 2 shell).
- Later phases: `htmx-php-builder:design-system`, `php-patterns`, `new-screen`, `mcp-servers` and `chat-actions`.
