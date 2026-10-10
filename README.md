# Boards

A Miro-style infinite whiteboard as an application of the MaluDB Business OS: people and OS agents on the same canvas, every object in the record memory (PostgreSQL 17), every change in the activity memory (MaluDB).

## Status

Research complete; tech-stack decision accepted 2026-10-10. On the dev server the same day: the server surveyed, the siblings' schemas surveyed, and the Phase 0 design written — [docs/boards-design.md](docs/boards-design.md), awaiting the owner's decisions D1–D16. Next: the owner's answers, then the realtime spike. No application code yet.

## Documents

- [docs/boards-design.md](docs/boards-design.md) — the Phase 0 design fitted to the Business OS: actors, walls, agents, the memory model and its provenance against the estate, the questions, the doors (the ops door and the live door), screens, slices, what the kernel owes, and the decisions D1–D16.
- [docs/research/dev-server-survey.md](docs/research/dev-server-survey.md) — the dev server read-only: Apache, the front proxy, ports, the installer's port rule, Python and Node.

- [docs/research/tech-stack-decision.md](docs/research/tech-stack-decision.md) — the accepted stack (HTMX shell + TypeScript/Konva canvas island + Python WebSocket relay over server-authoritative PostgreSQL rows), OS contract gaps, and checkpoint questions.
- [docs/research/miro-and-whiteboard-landscape.md](docs/research/miro-and-whiteboard-landscape.md) — Miro's features, architecture and AI/MCP surface; open-source canvas and sync options; what the estate (integration contract, Spaces) already says.
