# Miro and the whiteboard landscape — research

Date: 2026-10-10
Purpose: the evidence base for the Boards tech-stack decision ([tech-stack-decision.md](tech-stack-decision.md)).
Tags: **[S]** sourced (link given), **[I]** inference / general knowledge not verified in this pass. Miro's engineering
blog (Medium) refused direct fetches; Miro engineering claims come from search excerpts of those posts.

---

## 1. What Miro is, feature by feature

Priority is ours, for a MaluDB Business OS application: **MVP** (credible v1), **v2** (soon after), **Later**.

### 1.1 Canvas

| Feature | Priority | Notes |
|---|---|---|
| Infinite canvas, zoom/pan, minimap | MVP | |
| Sticky notes (colours, auto-fit text) | MVP | REST type `sticky_note` |
| Shapes with text (rect, ellipse, triangle, diamond, …) | MVP | Miro's diagram mode has 3,000+ shapes [S: [2025 recap](https://miro.com/blog/2025-recap/)]; ship ~10 |
| Text | MVP | `text` |
| Connectors that stay attached | MVP | Ends attach to an item id with `snapTo` top/left/bottom/right or a relative position [S: [connector](https://developers.miro.com/docs/websdk-reference-connector)] |
| Frames (containers; children positioned relative to the frame) | MVP | `parent_top_left` coordinates; children move with the frame [S: [frame](https://developers.miro.com/docs/websdk-reference-frame)] |
| Freehand pen / highlighter | MVP | Miro warns pen strokes are among the heaviest objects [S: [performance help](https://help.miro.com/hc/en-us/articles/360013588560-Board-performance-and-loading-issues)] |
| Images and files | MVP | `image`, `document` |
| Select, multi-select, group, lock, z-order, align, copy/paste, undo/redo | MVP | |
| Cards (title, description, assignee, due date) | v2 | `card`; `app_card` is the integration-backed variant — our hook for Projects issues, Help Desk tickets |
| Templates | v2 | |
| Embeds (oEmbed / iframe) | v2 | |
| Kanban | v2 | frames + cards, or a table view |
| Mind maps | Later | still experimental in Miro's own API |
| Tables, diagram mode, code blocks, docs, slides, prototypes | Later | tables capped at 250 structured widgets/board [S: performance help] |

### 1.2 Collaboration

| Feature | Priority |
|---|---|
| Real-time multi-user editing | MVP |
| Live cursors, presence (avatars, who's here) | MVP |
| Comments pinned to the canvas or an object; threads; resolve | MVP |
| @mentions with notification | v2 |
| Follow a person; "bring everyone to me" | v2 (cheap once presence carries the viewport) |
| Timer, voting, emoji reactions | v2 |
| Private mode (hide others' stickies until reveal) | Later |
| Talktrack (async video), audio/video, AI "catch-up" summaries | Later |

### 1.3 Organisation and governance

Teams, Spaces (boards grouped by project), board roles (owner, editor, commenter, viewer) and share links
[S: [board model](https://developers.miro.com/reference/board-model) — `sharingPolicy` / `permissionsPolicy`], version
history. **MVP:** boards, roles, sharing. **v2:** folders/spaces, snapshots and restore. **Later:** admin console, audit
export, data residency. In the OS the kernel owns people and departments; Boards only mirrors them.

### 1.4 AI (2025–2026)

- **AI canvas** (Canvas 25, Oct 2025): AI reads the selection (stickies, diagrams, screenshots, votes) and generates
  stickies, docs, diagrams, tables and prototypes [S: [2025 recap](https://miro.com/blog/2025-recap/)].
- **Sidekicks** (persona agents on the canvas: built-in and custom, with instructions and knowledge) and **Flows**
  (multi-step visual AI workflows), upgraded at Canvas 26, May 2026 [S: [newsroom](https://miro.com/newsroom/miro-takes-aim-at-the-gap-between-ai-potential-and-organizational-reality)].
- **Miro MCP server** at `mcp.miro.com`, OAuth 2.1: read and summarise boards, create/update frames, stickies, shapes,
  text, cards, docs, tables; diagrams; read and resolve comments — a curated subset, not the full REST API
  [S: [MCP FAQ](https://developers.miro.com/docs/miro-mcp-server-frequently-asked-questions)].

**How Miro's MCP presents a board to an agent** (read from the connector's own tool specification in this session):
the board is serialised as an **SVG dialect** — each widget one element (`<rect>`, `<line data-start data-end>` for
connectors, `<g data-frame>` for frames, `<foreignObject data-type=…>` for tables/docs), with stable ids
(`data-miro-id`), server-measured `data-rendered-bounds`, read-only and locked markers, and deletes only by an explicit
`data-deleted="true"`. Full-board reads are **disabled**; an agent must `canvas_search` (overview / areas / matches)
and then read a bounding box. This is directly reusable for our MCP surface: agents reason well over spatial markup,
and search-then-read-a-region keeps large boards out of the context window.

The OS already does the AI half better than Miro's add-on model: the kernel runs the application's expert with the
application's tools, and every agent is a member who can be on a board. "Sidekicks" are, for us, **OS agents with a
Boards skill** — no model key in the application.

### 1.5 Developer platform

- **REST API v2.** Board → items. Item types `sticky_note`, `shape`, `text`, `card`, `app_card`, `frame`,
  `connector`, `image`, `document`, `embed` (+ tags, groups). Every item: `position {x, y, origin, relativeTo}` (x/y is
  the **centre**), `geometry {width, height, rotation}`, `parent`, `style`, `data`, created/modified by/at
  [S: [boards](https://developers.miro.com/docs/boards)]. Proven and simple — **copy this item shape** for our table.
- **Web SDK**: in-board apps in iframes, with selection and item-update events.
- **Webhooks**: Miro **removed** its experimental board webhooks effective 2025-12-05 as not reliable or scalable
  [S: [changelog](https://developers.miro.com/changelog/removed-experimental-webhooks-support)]. Lesson: the change feed
  must be designed in from day one (for us: `activity_log` + a per-board op sequence).

---

## 2. How Miro is built

**Rendering.** Boards are drawn on `<canvas>`: everything but the UI chrome is a continuously re-rendered image. Rich
text is laid out by a custom engine and drawn with `CanvasRenderingContext2D`; double-click hides the canvas text and
overlays a DOM editor, then redraws on blur
[S: [code block widget](https://medium.com/miro-engineering/why-we-developed-a-code-block-widget-in-miro-f6c5ec23085c),
[code editor on canvas](https://medium.com/miro-engineering/how-we-integrated-a-code-editor-on-the-miro-canvas-a41e0eff7f21)].
Current job posts name a Canvas Rendering team on "WebGL and 2D renderers"
[S: [Staff Web Graphics Engineer](https://portfoliojobs.tcv.com/companies/miro/jobs/37303878-staff-web-graphics-engineer)].

**Real-time.** A Java backend with a **stateful board session**: when a board opens, its runtime state lives on **one
server**; Hazelcast records which server owns which board while anyone is connected; commands arrive over WebSockets
and run on dedicated threads (≈100 servers, 50,000+ WebSocket commands/s in 2020)
[S: [concurrency annotations](https://medium.com/miro-engineering/annotations-for-concurrency-in-java-our-approach-to-coloring-threads-6895651428ae),
[profiling in production](https://medium.com/miro-engineering/profiling-in-production-to-detect-server-bottlenecks-321919d2da51)].
No Miro source says OT or CRDT. One authoritative owner ordering commands means **server-authoritative ordering with
last-writer-wins per object/property** [I] — the model Figma documents openly
[S: [Figma multiplayer](https://www.figma.com/blog/how-figmas-multiplayer-technology-works/)].

**Large boards.** Hard cap 100,000 objects; performance degrades from ~1,000; Miro recommends staying under 5,000; pen
strokes, documents and many simultaneous collaborators are the heavy cases [S: performance help]. Viewport culling and
level-of-detail are implied but undocumented [I].

**Offline.** Not supported; offline editing has been a top community request since ~2020
[S: [offline idea](https://community.miro.com/ideas/offline-mode-925)].

**Takeaway.** Miro is *not* CRDT/offline-first. A server-authoritative board with per-property last-writer-wins is the
proven design, and it maps one-to-one onto PostgreSQL rows.

---

## 3. Open-source building blocks

| Project | Licence | Server / persistence | Fit for Boards |
|---|---|---|---|
| **tldraw SDK** | Proprietary tldraw licence | `@tldraw/sync-core` on Node/Bun or Cloudflare; in-memory or SQLite; server-authoritative room per document [S: [sync docs](https://tldraw.dev/docs/sync)] | Best whiteboard UX available. **A production licence key is required; without one the editor stops rendering after 5 seconds**; commercial licence is annual, priced by sales [S: [licence key](https://tldraw.dev/sdk-features/license-key)]. React + Node; no Postgres adapter. A *buy* option, not a build block |
| **Excalidraw** | MIT | `excalidraw-room` (Node Socket.IO relay), end-to-end encrypted, Firebase by default | Good embeddable React component, sketch aesthetic. E2E encryption defeats server-side search, MCP and the activity memory; its look fights the nxl design system |
| **Konva.js** | MIT | client only | Canvas2D scene graph: layers, hit-testing, drag, `Transformer` (resize/rotate), caching. The editing UX is ours to build. Canvas2D is what Miro itself used |
| **PixiJS** | MIT | client only | WebGL/WebGPU; fastest at very large scale; text editing and transforms entirely ours |
| **Fabric.js** | MIT | client only | Canvas2D object model with built-in text editing; less suited to an infinite canvas with many objects [I] |
| **Yjs** | MIT | `y-websocket-server` (dev-grade), **Hocuspocus** (Node; DB extension), **y/hub** (AGPL or paid) [S: [yhub](https://github.com/yjs/yhub)] | The de-facto CRDT. Brings a Node sidecar and an opaque binary document that SQL, MCP and the activity log cannot see without a projection |
| **Automerge / Loro** | MIT | Node/Rust sync servers, young persistence stories | Same trade-off as Yjs, smaller ecosystems |
| **Liveblocks** | client Apache-2.0; server AGPL-3.0 | open server is for local dev [S: [blog](https://liveblocks.io/blog/open-sourcing-the-liveblocks-sync-engine-and-dev-server)] | Hosted SaaS — fails data sovereignty |
| **AFFiNE / BlockSuite** | MIT (CE) | Node/NestJS, PostgreSQL + Redis [I] | Whole product, not a component |
| **WBO** | AGPL-3.0 | Node; SVG files on disk | Copyleft; minimal |
| **Ourboard** | custom licence | single Node process, WebSocket, PostgreSQL [S: [repo](https://github.com/raimohanska/ourboard)] | "A poor man's Miro" — the closest architectural reference for a Postgres-backed board server |
| **draw.io** | Apache-2.0 | static | Could embed as a diagram editor later; not a board engine |

---

## 4. Real-time with a PHP backend

| Route | What it is | Verdict for Boards |
|---|---|---|
| **A. PHP + HTTP polling** | WordPress 7.0 core co-editing: Yjs with a PHP polling provider (~1 s), chosen "for universal hosting compatibility" [S: [WP dev news, Mar 2026](https://developer.wordpress.org/news/2026/03/whats-new-for-developers-march-2026/)] | Proves PHP can carry collaboration; 1 Hz cursors feel poor; ~1 request/s/user. Good **fallback** mode |
| **B. PHP writes + SSE fan-out (Mercure)** | Clients POST to PHP; PHP publishes to a hub; clients receive via SSE [S: [Mercure](https://dunglas.dev/2019/07/mercure-real-time-apis-for-serverless-and-beyond)] | Right shape (server-authoritative), but Mercure is a Go binary the installer cannot install, and cursors-by-POST are wasteful |
| **C. Long-running PHP WebSockets** | OpenSwoole / ReactPHP; Ratchet effectively unmaintained since 2021 | Possible, but an unusual PHP runtime model nobody in the estate runs |
| **D. Node sidecar (Hocuspocus/Yjs)** | PHP owns auth and data; Node owns the CRDT room | Smoothest path to CRDT, but adds a second runtime the installer has no step for |
| **E. Python relay + PHP writes** *(ours)* | PHP handlers write rows and `NOTIFY`; a small Python asyncio WebSocket service `LISTEN`s and fans out, and relays cursors/presence | Same runtime and installer step as the MCP servers; one write path for people and agents. **Chosen** — see the decision record |

PHP Yjs ports exist if CRDT text is ever needed: [davidhemphill/yjs-php](https://github.com/davidhemphill/yjs-php) (MIT,
PHP 8.4, byte-compatible update/merge/state-vector) and [Automattic/yjs-php](https://github.com/Automattic/yjs-php)
(GPL-3.0, experimental).

---

## 5. What the estate already says

- **The plugin's own upgrade triggers** (`memory-first-planning/build/tech-stack.md`): "Real-time collaboration or live
  updates between users → WebSockets / server-sent events"; "Client-side interactivity beyond what HTMX partials can
  express → a JavaScript framework *for the affected screens*". A board is both triggers at once.
- **Same file:** a second runtime for agents/MCP is allowed, but "don't ship both runtimes in one application" —
  Python is already in every OS application (MCP servers, ingest), so a Python relay adds no runtime; Node would.
- **`maludb-os-integration` v0.9.0** has no websocket endpoint kind, no `mod_proxy_wstunnel` example, no npm/JS build
  step; the installer knows PHP (composer) and Python (venv) only. Every write must be a PHP handler the kernel's actions
  server can POST to — "no write path an agent cannot reach".
- **Spaces** (the closest sibling, Notion + Slack) deliberately left real-time co-editing out of v1 (stale save refused
  and reloaded; channels poll every 3 s with `hx-trigger="every 3s [document.visibilityState=='visible']"`), and names
  the future route as "a CRDT per block — Yjs or a PHP port — and SSE or a small WebSocket sidecar"
  (`maludb-os-spaces/docs/spaces-design.md`). **Boards would be the first estate application with a live socket**; the
  pattern it sets is the one Spaces will reuse.
- **Canonical tables Boards reuses rather than invents** (`shared-schema.md`): the kernel mirror (`members`,
  `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state`), `activity_log`,
  `activity_ingest_state`, `mcp_access_tokens`, Spaces' UUID-keyed `attachments` (with `width`, `height`,
  `thumbnail_path`), Spaces' threaded resolvable `comments`, `notifications`/`notification_prefs`/`notification_outbox`,
  `search_index`, `app_settings`. Projects issues, Help Desk tickets and CRM deals are **read** through the kernel (K7)
  for `app_card` items — never copied, never written.
