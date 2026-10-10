# Boards — notes for Claude

A Miro-style whiteboard, an application of the MaluDB Business OS (catalog key `boards`).

- Start every new session by reading [docs/HANDOFF.md](docs/HANDOFF.md) (current state and next steps) and the accepted
  decision record [docs/research/tech-stack-decision.md](docs/research/tech-stack-decision.md).
- Build method: the `htmx-php-builder` plugin (`os-application` first) under the `maludb-os-integration` contract; the
  integration plugin wins where they disagree.
- Exception to the default stack, already decided: the board canvas is a TypeScript + Konva.js island (bundle built with
  esbuild in `client/`, committed under `html/assets/boards/`), and live collaboration goes through a Python WebSocket
  relay that never writes board data. Everything else is PHP + HTMX + Bootstrap 5.3 nxl.
- The user runs all `sudo` commands; ask for them.
- The plan is [docs/boards-design.md](docs/boards-design.md) (§0–§16, the estate's shape; the owner's answers go in §15).
  Ports: `APP_INTERNAL_PORT=8198`, `MCP_RECORDS_PORT=8849`, `MCP_ACTIVITY_PORT=8850`, `BOARDS_LIVE_PORT=8851`, scratch
  9101–9107 (8194–8197 are txtSchedules' proof servers). Prefix `bd_`. Kernel item K40 = the catalog row (db/180).
- Tables follow the estate's families (`shared-schema.md`): `board_permissions` is Spaces' `page_permissions` shape,
  `board_versions` is `page_versions`, templates are `boards.is_template` rows, comments are Spaces' family. New canonical:
  `boards`, `board_folders`, `board_folder_entries`, `board_items`, `board_ops`.
- The plugins on this server: Claude Code registers only `htmx-php-builder` 0.4.1; read the skills from the local clones
  `~/htmx-php-builder` and `~/maludb-os-integration` (both 0.9.0).
