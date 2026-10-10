# Boards

A Miro-style infinite whiteboard as an application of the MaluDB Business OS: people and OS agents on the same canvas, every object in the record memory (PostgreSQL 17), every change in the activity memory (MaluDB).

## Status

Research complete; tech-stack decision accepted 2026-10-10. Next: Phase 0 plan on the Ubuntu dev server — start with [docs/HANDOFF.md](docs/HANDOFF.md). No application code yet.

## Documents

- [docs/research/tech-stack-decision.md](docs/research/tech-stack-decision.md) — the accepted stack (HTMX shell + TypeScript/Konva canvas island + Python WebSocket relay over server-authoritative PostgreSQL rows), OS contract gaps, and checkpoint questions.
- [docs/research/miro-and-whiteboard-landscape.md](docs/research/miro-and-whiteboard-landscape.md) — Miro's features, architecture and AI/MCP surface; open-source canvas and sync options; what the estate (integration contract, Spaces) already says.
