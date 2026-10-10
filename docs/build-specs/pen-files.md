# Build spec: pen, images and files (slice 5)

What exists at the end: the **pen** and the **highlighter** (pointer and touch, smoothed, simplified so a stroke never passes
`drawing_max_points`), the **eraser** (touching a stroke removes it); **images** — uploaded from the toolbar, dropped onto the canvas or pasted
— shown at the size their pixels deserve (three thumbnails, 256 / 1024 / 2048 on the longest side, chosen by the item's size on screen);
**files** — a PDF shows its first page, any other file shows as a card with its name, size and type — opened or downloaded through one door
behind the board's wall; an SVG drawn as an image and served **sandboxed**. Built by the planning model (design D16), **replicating slice 2's
patterns exactly** (a tool, a renderer, each change one batch through `bd_commit_ops()`); the architecture is canvas-core ("Rendering and
performance" — level of detail —, "The op protocol", "The doors (PHP)").

Schema (never modified): `board_items` (`drawing`, `image`, `document`), `bd_commit_ops()` (the drawing's point limit) (db/007); `attachments`
(record_type `item`, `record_uuid`, `width`, `height`, `thumbnail_path`, `thumbnails`, `board_id`) (db/008); `bd_purge_deleted_items()` (answers the
files to unlink) (db/011); `mcp_attachments` (db/012); `bd_settings.max_upload_mb`, `drawing_max_points` (db/005). **The database is the referee**:
a drawing's point limit, the board's wall on every file (through `attachments.board_id`).

## Divergences from the design
1. **The upload mints the item's id.** The manifest's `file_upload` takes `board` and `file`; an `attachments` row needs a `record_uuid` and the
   item does not exist yet — so the door mints a fresh uuid, stores the attachment against it and answers it as `item_id`; the island then commits
   the `create` op with exactly that id (`data.attachment_id` = the attachment). An upload whose item was never committed is an **orphan**,
   removed by the worker's `thumbnails` pass after 24 hours (the row and its files).
2. **The file door takes the size as a query parameter**: `GET /files/{id}?size=256|1024|2048` (none = the original) → `html/files/view.php` — the
   estate's `/{x}/{id}` rewrite — instead of the manifest convention's `/files/{id}/{size}`, which the vhost and the dev router would send to a
   file named for the size.

## Who may do what
| Action / door | Level / rule |
|---|---|
| `file_upload` | `edit` on the board; people only (an agent never uploads) |
| `GET /files/{id}` | `view` on the attachment's board (below → 404, the same nothing as no file); a `board_thumbnail` likewise |
| drawing, erasing, placing and resizing images and files | `edit` — through slice 2's `ops_commit` |
| `find_attachments` | `view` |

## Screens
None new. `board-view` gains the Pen (three widths 2 / 4 / 8, the palette), Highlighter (24, 40 % opacity), Eraser and Image / File tools; drop
and paste on the canvas; the image and file renderers; the Properties panel's drawing section (colour, width) and image section (title, "Open
original", "Download").

## The data and the rules
- **Drawing** (`type = drawing`): `data.points` = `[x0, y0, x1, y1, …]` relative to the item's top-left, rounded to 0.1; `data.tool` = pen |
  highlighter; `width`, `height` = the points' bounds (+ half the stroke); `style` = `{strokeColor, strokeWidth}`. **Simplification** on pen-up:
  Ramer–Douglas–Peucker at `1.5 / zoom` units, the tolerance doubled until the stroke has ≤ `drawing_max_points` points. Strokes are drawn with
  Konva's `tension 0.4`; the highlighter with `globalCompositeOperation = 'multiply'`.
- **Eraser**: a stroke-eraser — every drawing the eraser's path touches (hit-tested on the rendered line) is deleted in ONE batch on pointer-up;
  partial erasing is out of scope.
- **Upload** (`POST /files/upload.php`, multipart: `board`, `file`): ≤ `max_upload_mb` (25 MB — 413 past Apache's 32 MB, 422 with the sentence past
  the setting); the type by `finfo` (never the name): images png, jpeg, gif, webp, svg+xml; documents pdf, the Office formats (docx, xlsx, pptx), odt,
  ods, odp, txt, csv, md, zip — anything else refused "Boards keeps images and documents, not {type}". Stored as
  `BD_STORAGE_DIR/attachments/{yyyy}/{mm}/{attachment id}-{sha256 first 12}.{ext}`; `sha256`, `byte_size`, `mime_type`, `filename` (the client's,
  cleaned to 200 characters) recorded.
- **Thumbnails** (GD): raster images → 256, 1024 and 2048 on the longest side (never upscaled; a GIF's first frame; PNG out for PNG and GIF, JPEG
  q 82 for JPEG, WebP q 80 for WebP) under `BD_STORAGE_DIR/thumbnails/`, recorded in `attachments.thumbnails` and the 256 one in `thumbnail_path`;
  `width`, `height` = the original's. **A PDF**: `pdftoppm -png -f 1 -l 1 -scale-to 2048` (a 20-second limit) → that page's three sizes; a PDF
  that fails to render is a file card. **SVG**: no raster thumbnail (the island draws the SVG itself via an `<img>`); width and height from its
  `viewBox` or 800 × 600. Other documents: none.
- **The canvas item**: `image` for an image, `document` for any other file; `data` = `{attachment_id, title}`; placed centred at the drop point
  (or the viewport's centre) at its intrinsic size scaled to fit 800 × 800 (a document card 320 × 120).
- **Level of detail** (canvas-core): the island requests `?size=` 256 when the item is ≤ 256 px on screen, 1024 when ≤ 1024, else 2048; never the
  original on the canvas.
- **The file door** (`GET /files/{id}?size=`): the wall (`view` on `board_id`); `Content-Type` = the stored type; `X-Content-Type-Options: nosniff`;
  `Cache-Control: private, max-age=3600`; images inline; **an SVG always with `Content-Security-Policy: sandbox; default-src 'none'; style-src
  'unsafe-inline'; img-src data:`** and `Content-Disposition: attachment` when `Sec-Fetch-Dest: document` (opened directly); documents always
  `attachment` with the original filename. Logged `file.open` only for an original's download, never for a thumbnail.

## Files (exactly these)
- `client/` — the sources canvas-core assigns to slice 5 (the pen, highlighter and eraser tools, simplification, the drawing, image and document
  renderers, the upload / drop / paste path, level of detail), the bundle rebuilt
- `html/files/{upload,view}.php`; `app/features/files/{queries,write,thumbs,store}.php`; `bin/worker.php`'s `thumbnails` pass made real (orphans
  after 24 h) and its purge step unlinking what `bd_purge_deleted_items()` answers (the trash pass of slice 10 unlinks what `bd_board_purge()` answers)
- `tests/phase3/slice5/{run.sh,lib.php,upload.php,door.php,worker.php,browser.mjs}`, `tests/fixtures/{photo-3000x2000.jpg,anim.gif,shape.webp,evil.svg,two-pages.pdf,notes.docx}`

## Query functions (signatures fixed)
- `attachment_for_door(PDO $pdo, int $id): ?array` (the row if the caller's level on its board is ≥ view; never the path to the caller) ·
  `find_attachments(PDO $pdo, string $boardId, ?string $kind, int $limit): array`
- writes: `store_upload(PDO $pdo, string $boardId, int $by, array $file): array{attachment_id, item_id, mime_type, width, height, thumbnails}` ·
  `make_thumbnails(string $path, string $mime): array{width, height, thumbnails}` · `purge_orphans(PDO $pdo, DateTimeImmutable $now): int` ·
  `unlink_paths(array $paths): int` (only under `BD_STORAGE_DIR`)

## MCP tools this slice makes real
`find_attachments`.

## Manifest rows claimed
Screens (0):
Actions (1): `file_upload`

## Activity log events
`file.upload` (the attachment's id, the item id, the type, the size — never the path), `file.open` (an original downloaded), `file.purge`
(the worker: the count of orphans removed); drawing and erasing log through slice 2's door (`item.create`, `item.delete`). Every row carries
`board_id`.

## Out of scope for this slice
Partial erasing; shape recognition from a stroke; image cropping and filters; previews of Office documents (a card); embeds (v2); an agent
uploading (people only); virus scanning (the owner's mail gateway's job, not this application's — recorded, not built).

## Proof (`tests/phase3/slice5/run.sh`: scratch database `bd_dev5` with a scratch store; php -S on 9101 and the fakes; the user-level Apache on 9107 (the 32 MB body limit and the vhost's PHP limits); Playwright; the registry `--check`)
1. A 500-point pen stroke (Playwright mouse moves) → one `drawing` with ≤ `drawing_max_points` points; with `drawing_max_points` set to 10 the stroke
   is simplified to ≤ 10; a forged 11-point stroke is refused by the database; the highlighter's opacity and blend.
2. The eraser across two strokes → both deleted in one batch; undo brings them back.
3. Upload the 3000 × 2000 JPEG → three thumbnails 256 / 1024 / 2048 wide, `width`/`height` the original's; the item placed 800 wide; zooming out
   switches the requested size (`window.__boards.imageSizes()`); GIF and WebP likewise; PNG keeps its alpha.
4. The two-page PDF → a first-page image at the three sizes, a `document` item; the DOCX → a file card; a `.exe` renamed `.png` refused by `finfo`.
5. The SVG with a `<script>` and `onload`: drawn on the canvas as an image; opened directly the door answers `attachment` with the sandbox CSP and
   Playwright sees no dialog and no console line from the script.
6. A 26 MB file refused with the sentence (the setting) and a 33 MB one 413 (Apache); an agent's `file_upload` refused (`people`).
7. The wall: Bea (no access) GETs the file and a thumbnail → 404; a viewer → 200; after the board is trashed → 404.
8. The worker: an upload never committed is purged after 24 h (`BD_WORKER_NOW`) with its files; an image item deleted 91 days ago is purged by
   `bd_purge_deleted_items()` and its four files unlinked; nothing outside `BD_STORAGE_DIR` is ever unlinked.
9. Drop and paste an image onto the canvas (Playwright's clipboard and drop) → uploaded and placed at the drop point.
10. 375: the pen with touch, the toolbar's overflow; no console errors; `bin/check_bundle.php`; registry `--check`.

## Decisions taken here
1. The upload mints the item's id and answers it (divergence 1); orphans go after 24 hours.
2. The size is a query parameter on `/files/{id}` (divergence 2).
3. The accepted types are the list above, decided by `finfo`; Office documents are cards.
4. The eraser removes whole strokes only.
5. Thumbnail formats follow the original (PNG for PNG and GIF, JPEG q 82, WebP q 80); never upscaled.
6. An image lands at its intrinsic size scaled into 800 × 800; a document card is 320 × 120.
7. A PDF's first page is rendered with a 20-second limit; failure falls back to a card.

## Open questions
None.
