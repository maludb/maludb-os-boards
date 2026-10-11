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
- **The canvas item**: `image` for an image, `document` for any other file; `data` = `{attachment_id, title, mime_type, byte_size, preview}`
  (the build's decision 3: what the card shows and what the canvas asks the door for, never a second request); placed centred at the drop point
  (or the viewport's centre) at its intrinsic size scaled to fit 800 × 800 — a PDF with its first page too — (a document card 320 × 120).
- **Level of detail** (canvas-core): the island requests `?size=` 256 when the item is ≤ 256 px on screen, 1024 when ≤ 1024, else 2048; never the
  original on the canvas.
- **The file door** (`GET /files/{id}?size=`): the wall (`view` on `board_id`); `Content-Type` = the stored type; `X-Content-Type-Options: nosniff`;
  `Cache-Control: private, max-age=3600`; images inline; **an SVG always with `Content-Security-Policy: sandbox; default-src 'none'; style-src
  'unsafe-inline'; img-src data:`** and `Content-Disposition: attachment` — inline only at `?inline=1`, the canvas's `<img>`, and there too an
  attachment when `Sec-Fetch-Dest: document` (the build's decision 1: two URLs); documents always `attachment` with the original filename. Logged
  `file.open` only for an original opened or downloaded, never for a thumbnail or `?inline=1`.

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

## Built and proven (2026-10-11)
The pen, the highlighter and the eraser; images and files on the board — built by the planning model on slice 2's patterns. **Drawing**: the Pen
(P; its menu beside the toolbar — thin / medium / thick and eight colours) and the Highlighter (24 wide, 40 %, multiplied — its own colour from
the same menu) with the mouse, a pen or one finger, previewed as Konva's tension line, simplified on release (Ramer–Douglas–Peucker at 1.5 /
zoom, doubled until ≤ `drawing_max_points`), committed as ONE create (points relative to the top-left to 0.1; in a frame when its centre is),
drawn by `render/items/drawing.ts` with the same tension curve (scaled when the Transformer resized it; a plain polyline below the low zoom),
hit on its line, not its box; the Eraser (E) deleting every stroke its path touches in ONE batch. **Files**: `POST /files/upload` (finfo decides;
the original under `attachments/{yyyy}/{mm}/{id}-{sha12}.{ext}`; GD thumbnails 256 / 1024 / 2048, never upscaled, alpha kept, a JPEG turned
upright by its EXIF orientation; a PDF's first page by `pdftoppm` in 20 s; an SVG sized by its viewBox), the island's picker, drop and paste
placing an `image` or a `document` at the drop point; `render/items/image.ts` asking the door for the size on screen and drawing the best size
already loaded meanwhile; `render/items/document.ts` a PDF's first page with its name, any other file a card (badge, name, size and type);
`GET /files/{id}` behind the board's wall; Properties for a drawing (colour, width — the box grows with it) and a file (title, Open original,
Download, its size and type); the worker's `thumbnails` pass (sizes for an attachment never thumbnailed, orphans after 24 hours, items deleted
past op_retention_days with their files — `unlink_paths()` removes only what resolves inside the store).

**Proven** by `tests/phase3/slice5/run.sh` — **77 checks under php -S, 51 under a real Apache** (its 32 MB body limit), then every earlier
suite (slice 4's and within it 3's, 2's, 1's and Phase 2's): the upload door 21 — the 3000 × 2000 JPEG's three thumbnails 2048 × 1365 / 1024 ×
683 / 256 × 171 and its original byte for byte under its path, one `file.upload` row and never a path; the GIF's first frame with 1024 and 2048
sharing its own 480 × 320; the WebP's and a PNG's alpha; the PDF's first page at three sizes; the DOCX a card; the SVG 400 × 300 from its
viewBox; an executable named .png refused by finfo with nothing left in the store; a JPEG named .png kept as holiday.jpg; a CSV by its name among
text types; 26 MB refused with the setting's sentence, 33 MB 413; a viewer 403, a stranger 404, an agent 403 "This is for people", no CSRF token
refused. The door 16 — the headers, the ETag and 304, sizes and their 404, documents as attachments with their names, `?download=1`; the SVG an
attachment at its plain URL however fetched, inline under the sandbox CSP only at `?inline=1` unless opened as a page; `file.open` five times
for five opens, never a thumbnail or an `<img>`; a stranger's 404 the same as no file; a path climbing out of the store 404; `find_attachments`
(no orphan, no path, by kind, nothing for a stranger); a trashed board 404. The worker 8 — nothing yet now; in 25 hours the never-committed
upload gone with its four files and one `file.purge` row, the committed one kept; the image deleted 91 days ago purged with its four files while a
path outside the store is left on disk and said so; an attachment never thumbnailed made once and never tried again. The browser 30 / 4 — a 500-
point stroke one batch simplified to ~30 points; at `drawing_max_points` 10 the next ≤ 10 and a forged 11-point stroke 422; the highlighter's
pixels over a sticky read back from the canvas as multiplied at 40 % (163, 193, 160); the eraser across two strokes in one batch and Ctrl+Z; a
drawing's colour and width; the picker's photograph 800 × 533 at the view's centre; the size asked at 100 % / 25 % / 300 % = 1024 / 256 / 2048;
two at once side by side 24 apart; the PDF's page and the DOCX card; an executable refused in a toast; a file's Properties, its title renamed and
its download; the SVG drawn from `?inline=1`, downloaded when opened, its script and onload blocked in a frame (the browser said so); a drop and a
paste centred where they landed; at 375 one finger drawing, the pen menu on the screen, no sideways scroll; no console error but the two refusals
the proof provokes.

**Decisions taken in the build:**
1. **An SVG has two URLs** (a divergence from "The file door" above, corrected there): `/files/{id}` is always a sandboxed attachment, and the
   canvas's `<img>` asks `?inline=1`. With one URL, the inline copy the canvas cached answered a person who opened the file — found by the proof
   (Chromium did not honour `Vary: Sec-Fetch-Dest`, which the door sends as well).
2. **An image past 25 megapixels is never decoded** (the vhost's 256 MB would not hold it): it is stored, has no preview, and lands as a file card.
3. **The item's `data` carries what the card shows** — `{attachment_id, title, mime_type, byte_size, preview}` — so the canvas never asks the door
   about a file; a renamed title keeps its badge (the badge comes from `mime_type`).
4. **A PDF with its first page lands like an image** (its page scaled into 800 × 800), the card's 320 × 120 being for files without a preview.
5. **`thumbnails` = `{"none": why}`** when there is no preview, so the worker never tries a card again; the upload makes its sizes at once and the
   worker's pass catches only an attachment never thumbnailed.
6. **The purge of items deleted past op_retention_days is this slice's**, in the `thumbnails` pass (`purge_deleted_items()`); history-versions.md's
   `compaction_pass` keeps `bd_compact_ops()` only — corrected there, consistency 17. Slice 10's trash purge uses `unlink_paths()` (home-admin.md).
7. **Changing a drawing's width in Properties grows its box by the difference and moves its points by half of it**, so the stroke stays inside.
8. **Found by the proof:** `files/write.php` needed the layout engine for `uuid_v4()` (every upload answered 500); the canvas's status notices
   ("Live updates paused", the soft budget) took presses meant for the board — they let them through now (`pointer-events: none`); a click without a
   drag on one of several selected items now selects that one alone (it kept the whole selection — Miro's and Figma's behaviour); the pen menu
   beside the toolbar is a menu, so a stroke starts beside it. Phase 2's shell proof counted seven worker stubs; it now counts the stubs
   `bin/worker.php` still marks (six — `thumbnails` is real).

