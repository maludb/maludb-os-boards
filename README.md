# Boards

A Miro-style infinite whiteboard as an application of the MaluDB Business OS: people and OS agents on the same canvas, every object in the record memory (PostgreSQL 17), every change in the activity memory (MaluDB).

## Status

Research complete; tech-stack decision accepted 2026-10-10. On the dev server the same day:

- the server and the siblings' schemas surveyed;
- the design written ([docs/boards-design.md](docs/boards-design.md)) and every decision taken by the owner;
- the realtime spike measured and passed (2 ms from commit to the other tab);
- **Phase 0 complete**: the kit and the schema (db/001–012, proven by 152 + 86 checks), `maludb-os.json`, the two agents,
  eight skills, and `deploy/` (the installer's plan clean). K40 is in the kernel's catalog.

**Phase 1 is written and approved** (2026-10-10):

- the MCP tool surface;
- the action manifest and its registry;
- three build specs and eleven slice specs;
- `docs/phase1-consistency.md`;
- `tests/phase1/check_specs.sh`, all green.

**Phase 2, the shell, is built and proven** (2026-10-10): sign-on, the menu by rights, the header, the tab bar, the command bar, the
record picker, My tokens, My trail, the board gate, and a placeholder for every later screen naming its slice — `tests/phase2/run.sh`
(258 checks under php -S, 266 under a real Apache; `BD_APP=apache tests/phase2/run.sh`).

**Slice 1, boards, folders and sharing (the CRUD exemplar), is built and proven** (2026-10-10): `tests/phase3/slice1/run.sh`, 188 checks
under php -S and under a real Apache.

Next: slice 2, the canvas.

Proofs (scratch databases `bd_*` only): `tests/phase0/schema.sh` (the schema) and `tests/phase0/run.sh` (the kit and the
schema).

## Documents

- [docs/boards-design.md](docs/boards-design.md) — the Phase 0 design fitted to the Business OS: actors, walls, agents, the memory model and its provenance against the estate, the questions, the doors (the ops door and the live door), screens, slices, what the kernel owes, and the decisions D1–D16.
- [docs/research/dev-server-survey.md](docs/research/dev-server-survey.md) — the dev server read-only: Apache, the front proxy, ports, the installer's port rule, Python and Node.

- [docs/research/tech-stack-decision.md](docs/research/tech-stack-decision.md) — the accepted stack (HTMX shell + TypeScript/Konva canvas island + Python WebSocket relay over server-authoritative PostgreSQL rows), OS contract gaps, and checkpoint questions.
- [docs/research/miro-and-whiteboard-landscape.md](docs/research/miro-and-whiteboard-landscape.md) — Miro's features, architecture and AI/MCP surface; open-source canvas and sync options; what the estate (integration contract, Spaces) already says.
