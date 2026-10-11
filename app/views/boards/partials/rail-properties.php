<?php
/**
 * The rail's Item panel — the selection's Properties (canvas.md "The rail partials"): the sticky colour (Miro's palette), a shape's fill, a
 * text's colour, the text size (S M L XL = 14 18 24 36), the alignment, Lock, Bring to front / Send to back, Delete; for ONE item its type,
 * size and "Set as the start view"; a connector's line (straight / elbowed / curved, style, width, colour, arrowheads) and a frame's title, fill and
 * "Fit to contents" (slice 4); a drawing's colour and width, an image's or a file's title, "Open original" and "Download" (slice 5 — the
 * links for anyone who sees the board: the door behind the wall decides). Every control is a button carrying data-board-command: the island turns it into ONE batch (optimistic
 * and undoable like any edit) — never a form post. Refreshed on `boards:select`. Data: board, rows (rail_selection), sum (rail_summary or
 * null), may (I can change items here), mayStart (I can set the start view — board_update, level edit)
 */
$ids = implode(',', array_map(static fn ($r) => (string) $r['item_id'], $rows));
$swatch = static fn (string $cmd, string $key, string $value, string $hex, string $label, string $types, bool $on): string =>
    '<button type="button" class="board-swatch-btn' . ($on ? ' active' : '') . '" data-board-command="' . e($cmd) . '" data-key="' . e($key) . '" data-value="' . e($value)
    . '" data-types="' . e($types) . '" title="' . e($label) . '" aria-pressed="' . ($on ? 'true' : 'false') . '"><span style="background:' . e($hex) . '"></span><span class="visually-hidden">' . e($label) . '</span></button>';
?>
<div id="rail-item" class="rail-panel" data-ids="<?= e($ids) ?>"
     hx-get="/boards/<?= e($board) ?>/rail?panel=item" hx-trigger="boards:select from:body" hx-target="this" hx-swap="outerHTML"
     hx-vals='js:{ids: (document.getElementById("page-content").dataset.selection || "")}'>
<?php if ($rows === []): ?>
    <p class="text-muted fs-13 mb-0 p-3" id="rail-item-empty">Select something on the board to see its properties here.</p>
<?php else: ?>
    <div class="p-3">
        <div class="fw-semibold mb-2" id="rail-item-title"><?= count($rows) === 1 ? e(item_type_words($rows[0])) : count($rows) . ' items selected' ?>
            <?php if ($sum['all_locked']): ?><span class="badge text-bg-warning ms-1" id="rail-item-locked"><i class="feather-lock me-1"></i>Locked</span><?php endif; ?></div>
        <?php if ($may && !$sum['all_locked']): ?>
            <?php if ($sum['sticky_color'] !== null): ?>
                <div class="rail-label">Sticky colour</div>
                <div class="rail-swatches" id="rail-sticky-colors">
                    <?php foreach (STICKY_COLORS as $name => $hex): ?><?= $swatch('style', 'fillColor', $name, $hex, str_replace('_', ' ', $name), 'sticky_note', $sum['sticky_color'] === $name) ?><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($sum['fill'] !== null): ?>
                <div class="rail-label">Fill</div>
                <div class="rail-swatches" id="rail-fill-colors">
                    <?php foreach (ITEM_PALETTE as $hex): ?><?= $swatch('style', 'fillColor', $hex, $hex, $hex, 'shape', strtolower($sum['fill']) === $hex) ?><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($sum['text_color'] !== null): ?>
                <div class="rail-label">Text colour</div>
                <div class="rail-swatches" id="rail-text-colors">
                    <?php foreach (ITEM_PALETTE as $hex): ?><?= $swatch('style', 'textColor', $hex, $hex, $hex, 'text', strtolower($sum['text_color']) === $hex) ?><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($sum['connector'] !== null): $k = $sum['connector']; $btn = static fn (string $cmd, string $key, string $val, string $label, ?string $on, string $idp) =>
                '<button type="button" class="btn btn-outline-secondary btn-touch' . ($on === $val ? ' active' : '') . '" data-board-command="' . e($cmd) . '" data-key="' . e($key)
                . '" data-value="' . e($val) . '" data-types="connector" id="' . e($idp . '-' . $val) . '">' . $label . '</button>'; ?>
                <div class="rail-label">Line</div>
                <div class="btn-group mb-2 flex-wrap" role="group" id="rail-connector-shape">
                    <?= $btn('data', 'shape', 'straight', 'Straight', $k['shape'], 'rail-shape') ?><?= $btn('data', 'shape', 'elbowed', 'Elbowed', $k['shape'], 'rail-shape') ?><?= $btn('data', 'shape', 'curved', 'Curved', $k['shape'], 'rail-shape') ?>
                </div>
                <div class="btn-group mb-2 flex-wrap" role="group" id="rail-connector-style">
                    <?= $btn('style', 'strokeStyle', 'normal', '—', $k['style'], 'rail-stroke') ?><?= $btn('style', 'strokeStyle', 'dashed', '– –', $k['style'], 'rail-stroke') ?><?= $btn('style', 'strokeStyle', 'dotted', '···', $k['style'], 'rail-stroke') ?>
                </div>
                <div class="btn-group mb-2 flex-wrap" role="group" id="rail-connector-width">
                    <?php foreach (['1', '2', '4', '8'] as $wd): ?><?= $btn('style', 'strokeWidth', $wd, $wd, $k['width'], 'rail-width') ?><?php endforeach; ?>
                </div>
                <div class="rail-label">Arrowheads</div>
                <div class="d-flex flex-wrap gap-2 mb-2" id="rail-connector-caps">
                    <div class="btn-group" role="group"><?php foreach (['none' => 'None', 'arrow' => '←', 'filled_triangle' => '◀'] as $cap => $lab): ?><?= $btn('style', 'startStrokeCap', $cap, $lab, $k['start'], 'rail-start') ?><?php endforeach; ?></div>
                    <div class="btn-group" role="group"><?php foreach (['none' => 'None', 'arrow' => '→', 'filled_triangle' => '▶'] as $cap => $lab): ?><?= $btn('style', 'endStrokeCap', $cap, $lab, $k['end'], 'rail-end') ?><?php endforeach; ?></div>
                </div>
                <div class="rail-label">Line colour</div>
                <div class="rail-swatches" id="rail-connector-colors">
                    <?php foreach (ITEM_PALETTE as $hex): ?><?= $swatch('style', 'strokeColor', $hex, $hex, $hex, 'connector', strtolower((string) $k['color']) === $hex) ?><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($sum['frame'] !== null): $fr = $sum['frame']; ?>
                <?php if ($fr['title'] !== null): ?>
                    <div class="rail-label">Title</div>
                    <input type="text" class="form-control mb-2" maxlength="120" value="<?= e($fr['title']) ?>" data-board-input="data" data-key="title" data-types="frame" id="rail-frame-title" aria-label="The frame's title">
                <?php endif; ?>
                <div class="rail-label">Frame fill</div>
                <div class="rail-swatches" id="rail-frame-fills">
                    <?php foreach (BOARD_COLORS as $hex => $label): ?><?= $swatch('style', 'fillColor', $hex, $hex, $label, 'frame', strtolower((string) $fr['fill']) === $hex) ?><?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-light btn-touch mt-2" data-board-command="fit-frame" id="rail-frame-fit"><i class="feather-minimize-2 me-1"></i>Fit to contents</button>
            <?php endif; ?>
            <?php if ($sum['drawing'] !== null): $dw = $sum['drawing']; ?>
                <div class="rail-label">Stroke colour</div>
                <div class="rail-swatches" id="rail-drawing-colors">
                    <?php foreach (PEN_COLORS as $hex => $label): ?><?= $swatch('style', 'strokeColor', $hex, $hex, $label, 'drawing', $dw['color'] === $hex) ?><?php endforeach; ?>
                </div>
                <?php if ($dw['pens']): ?>
                    <div class="rail-label">Stroke width</div>
                    <div class="btn-group mb-2" role="group" id="rail-drawing-width">
                        <?php foreach (['2' => 'Thin', '4' => 'Medium', '8' => 'Thick'] as $wd => $lab): ?>
                            <button type="button" class="btn btn-outline-secondary btn-touch<?= $dw['width'] === (string) $wd ? ' active' : '' ?>" data-board-command="style" data-key="strokeWidth" data-value="<?= e((string) $wd) ?>" data-types="drawing" id="rail-drawing-width-<?= e((string) $wd) ?>"><?= e($lab) ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($sum['file'] !== null): ?>
                <div class="rail-label">Title</div>
                <input type="text" class="form-control mb-2" maxlength="200" value="<?= e($sum['file']['title']) ?>" data-board-input="data" data-key="title" data-types="image,document" id="rail-file-title" aria-label="The file's title">
            <?php endif; ?>
            <?php if ($sum['has_text']): ?>
                <div class="rail-label">Text size</div>
                <div class="btn-group mb-2" role="group" id="rail-text-size">
                    <?php foreach (TEXT_SIZES as $label => $px): ?>
                        <button type="button" class="btn btn-outline-secondary btn-touch<?= $sum['font_size'] === (string) $px ? ' active' : '' ?>" data-board-command="style" data-key="fontSize" data-value="<?= (int) $px ?>" data-types="sticky_note,shape,text" id="rail-size-<?= e($label) ?>"><?= e($label) ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="rail-label">Alignment</div>
                <div class="btn-group mb-2" role="group" id="rail-align">
                    <?php foreach (['left' => 'feather-align-left', 'center' => 'feather-align-center', 'right' => 'feather-align-right'] as $al => $icon): ?>
                        <button type="button" class="btn btn-outline-secondary btn-touch<?= $sum['align'] === $al ? ' active' : '' ?>" data-board-command="style" data-key="textAlign" data-value="<?= e($al) ?>" data-types="sticky_note,shape,text" id="rail-align-<?= e($al) ?>" title="<?= e(ucfirst($al)) ?>"><i class="<?= e($icon) ?>"></i></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <button type="button" class="btn btn-light btn-touch" data-board-command="front" id="rail-front"><i class="feather-chevrons-up me-1"></i>Bring to front</button>
                <button type="button" class="btn btn-light btn-touch" data-board-command="back" id="rail-back"><i class="feather-chevrons-down me-1"></i>Send to back</button>
                <button type="button" class="btn btn-light btn-touch" data-board-command="lock" data-value="1" id="rail-lock"><i class="feather-lock me-1"></i>Lock</button>
                <button type="button" class="btn btn-outline-danger btn-touch" data-board-command="delete" id="rail-delete"><i class="feather-trash-2 me-1"></i>Delete</button>
            </div>
        <?php elseif ($may): ?>
            <button type="button" class="btn btn-light btn-touch mt-1" data-board-command="lock" data-value="0" id="rail-unlock"><i class="feather-unlock me-1"></i>Unlock</button>
        <?php endif; ?>
        <?php if ($sum['file'] !== null && $sum['file']['attachment_id'] > 0): $fl = $sum['file']; ?>
            <div class="d-flex flex-wrap gap-2 mt-3" id="rail-file-links">
                <a class="btn btn-light btn-touch" href="/files/<?= (int) $fl['attachment_id'] ?>" target="_blank" rel="noopener" id="rail-file-open"><i class="feather-external-link me-1"></i>Open original</a>
                <a class="btn btn-light btn-touch" href="/files/<?= (int) $fl['attachment_id'] ?>?download=1" id="rail-file-download"><i class="feather-download me-1"></i>Download</a>
            </div>
            <?php if ($fl['facts'] !== ''): ?><p class="fs-12 text-muted mt-2 mb-0" id="rail-file-facts"><?= e($fl['facts']) ?></p><?php endif; ?>
        <?php endif; ?>
        <?php if (count($rows) === 1): $r = $rows[0]; ?>
            <dl class="rail-facts mt-3 mb-0 fs-12">
                <dt>Size</dt><dd id="rail-item-size"><?= (int) round((float) $r['width']) ?> × <?= (int) round((float) $r['height']) ?></dd>
                <dt>Centre</dt><dd id="rail-item-centre"><?= (int) round(((float) $r['x1'] + (float) $r['x2']) / 2) ?>, <?= (int) round(((float) $r['y1'] + (float) $r['y2']) / 2) ?></dd>
                <?php if ((float) $r['rotation'] != 0.0): ?><dt>Rotation</dt><dd><?= (int) round((float) $r['rotation']) ?>°</dd><?php endif; ?>
            </dl>
            <?php if ($mayStart): ?>
                <button type="button" class="btn btn-link btn-sm p-0 mt-2" data-board-command="start-view" id="rail-start-view"
                        data-x="<?= e((string) round(((float) $r['x1'] + (float) $r['x2']) / 2)) ?>" data-y="<?= e((string) round(((float) $r['y1'] + (float) $r['y2']) / 2)) ?>"><i class="feather-flag me-1"></i>Set as the start view</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>
