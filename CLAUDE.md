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
