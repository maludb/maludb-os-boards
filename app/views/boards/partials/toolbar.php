<?php
/**
 * The left toolbar (canvas.md; canvas-core.md "375 px and touch" — at 375 px it is the bottom bar with 44 px targets): Select · Hand · Sticky
 * · Shape (Miro's ten) · Text · Connector · Frame (slice 4) · Pen (its menu: three widths, the colours) · Highlighter · Eraser · Image or file (slice 5)
 * for an editor; Select and Hand alone for a viewer or a commenter (the database refuses their writes anyway). The tools of later slices show
 * disabled with their slice; the Comment tool (Shift+C, slice 6) is for anyone who may comment; at 375 px the connector, frame, pen and later tools sit behind "More". The island binds [data-tool]. Data: editable, canComment
 */
$shapes = ['rectangle' => 'Rectangle', 'round_rectangle' => 'Rounded rectangle', 'ellipse' => 'Ellipse', 'triangle' => 'Triangle', 'diamond' => 'Diamond',
           'parallelogram' => 'Parallelogram', 'hexagon' => 'Hexagon', 'star' => 'Star', 'right_arrow' => 'Arrow', 'wedge_round_rectangle_callout' => 'Callout'];
$canComment = $canComment ?? false;
?>
<div class="board-toolbar" id="board-toolbar" role="toolbar" aria-label="Tools">
    <button type="button" class="board-tool active" data-tool="select" id="board-tool-select" title="Select (V)" aria-pressed="true"><i class="feather-mouse-pointer"></i><span class="visually-hidden">Select</span></button>
    <button type="button" class="board-tool" data-tool="hand" id="board-tool-hand" title="Hand — pan (H)" aria-pressed="false"><i class="feather-move"></i><span class="visually-hidden">Hand</span></button>
    <?php if ($canComment): ?>
        <button type="button" class="board-tool" data-tool="comment" id="board-tool-comment" title="Comment (Shift+C)" aria-pressed="false"><i class="feather-message-square"></i><span class="visually-hidden">Comment</span></button>
    <?php endif; ?>
    <?php if ($editable): ?>
        <button type="button" class="board-tool" data-tool="sticky" id="board-tool-sticky" title="Sticky note (N)" aria-pressed="false"><i class="feather-file"></i><span class="visually-hidden">Sticky note</span></button>
        <div class="board-tool-group">
            <button type="button" class="board-tool" data-tool="shape" id="board-tool-shape" title="Shape (S)" aria-pressed="false" aria-haspopup="true"><i class="feather-square"></i><span class="visually-hidden">Shape</span></button>
            <div class="board-shape-menu d-none" id="board-shape-menu" role="menu">
                <?php foreach ($shapes as $k => $label): ?>
                    <button type="button" class="board-shape-pick" data-shape="<?= e($k) ?>" id="board-shape-<?= e($k) ?>" role="menuitem" title="<?= e($label) ?>"><svg viewBox="0 0 40 28" aria-hidden="true"><use href="#shape-icon-<?= e($k) ?>"/></svg><span class="visually-hidden"><?= e($label) ?></span></button>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="button" class="board-tool" data-tool="text" id="board-tool-text" title="Text (T)" aria-pressed="false"><i class="feather-type"></i><span class="visually-hidden">Text</span></button>
        <button type="button" class="board-tool board-tool-more d-lg-none" id="board-tool-more" title="More tools" aria-expanded="false"><i class="feather-more-horizontal"></i><span class="visually-hidden">More</span></button>
        <div class="board-tool-later" id="board-tool-later">
            <button type="button" class="board-tool" data-tool="connector" id="board-tool-connector" title="Connector (C)" aria-pressed="false"><i class="feather-arrow-up-right"></i><span class="visually-hidden">Connector</span></button>
            <button type="button" class="board-tool" data-tool="frame" id="board-tool-frame" title="Frame (F)" aria-pressed="false"><i class="feather-maximize"></i><span class="visually-hidden">Frame</span></button>
            <div class="board-tool-group">
                <button type="button" class="board-tool" data-tool="pen" id="board-tool-pen" title="Pen (P)" aria-pressed="false" aria-haspopup="true"><i class="feather-edit-2"></i><span class="visually-hidden">Pen</span></button>
                <div class="board-pen-menu d-none" id="board-pen-menu" role="menu" aria-label="The pen">
                    <div class="board-pen-row">
                        <?php foreach (['2' => 'Thin', '4' => 'Medium', '8' => 'Thick'] as $wd => $label): ?>
                            <button type="button" class="board-pen-width<?= $wd === '4' ? ' active' : '' ?>" data-pen-width="<?= e((string) $wd) ?>" id="board-pen-width-<?= e((string) $wd) ?>" title="<?= e($label) ?>" role="menuitem"><span style="width:<?= (int) $wd + 2 ?>px;height:<?= (int) $wd + 2 ?>px"></span><span class="visually-hidden"><?= e($label) ?></span></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="board-pen-row">
                        <?php foreach (PEN_COLORS as $hex => $label): ?>
                            <button type="button" class="board-pen-color<?= $hex === '#1a1a1a' ? ' active' : '' ?>" data-pen-color="<?= e($hex) ?>" id="board-pen-color-<?= e(ltrim($hex, '#')) ?>" title="<?= e($label) ?>" role="menuitem"><span style="background:<?= e($hex) ?>"></span><span class="visually-hidden"><?= e($label) ?></span></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <button type="button" class="board-tool" data-tool="highlighter" id="board-tool-highlighter" title="Highlighter" aria-pressed="false" aria-haspopup="true"><i class="feather-pen-tool"></i><span class="visually-hidden">Highlighter</span></button>
            <button type="button" class="board-tool" data-tool="eraser" id="board-tool-eraser" title="Eraser (E)" aria-pressed="false"><i class="feather-delete"></i><span class="visually-hidden">Eraser</span></button>
            <button type="button" class="board-tool" id="board-tool-image" title="Image or file — or drop one on the board"><i class="feather-image"></i><span class="visually-hidden">Image or file</span></button>
        </div>
    <?php endif; ?>
</div>
<svg width="0" height="0" class="position-absolute" aria-hidden="true"><defs>
    <symbol id="shape-icon-rectangle" viewBox="0 0 40 28"><rect x="4" y="4" width="32" height="20" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
    <symbol id="shape-icon-round_rectangle" viewBox="0 0 40 28"><rect x="4" y="4" width="32" height="20" rx="6" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
    <symbol id="shape-icon-ellipse" viewBox="0 0 40 28"><ellipse cx="20" cy="14" rx="16" ry="10" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
    <symbol id="shape-icon-triangle" viewBox="0 0 40 28"><path d="M20 4 L36 24 L4 24 Z" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
    <symbol id="shape-icon-diamond" viewBox="0 0 40 28"><path d="M20 3 L37 14 L20 25 L3 14 Z" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
    <symbol id="shape-icon-parallelogram" viewBox="0 0 40 28"><path d="M10 4 L36 4 L30 24 L4 24 Z" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
    <symbol id="shape-icon-hexagon" viewBox="0 0 40 28"><path d="M10 4 L30 4 L37 14 L30 24 L10 24 L3 14 Z" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
    <symbol id="shape-icon-star" viewBox="0 0 40 28"><path d="M20 2 L23.5 10 L32 10.5 L25.5 16 L27.5 25 L20 20 L12.5 25 L14.5 16 L8 10.5 L16.5 10 Z" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
    <symbol id="shape-icon-right_arrow" viewBox="0 0 40 28"><path d="M4 9 L24 9 L24 3 L37 14 L24 25 L24 19 L4 19 Z" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
    <symbol id="shape-icon-wedge_round_rectangle_callout" viewBox="0 0 40 28"><path d="M8 3 H32 Q36 3 36 7 V15 Q36 19 32 19 H18 L10 26 L12 19 H8 Q4 19 4 15 V7 Q4 3 8 3 Z" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
</defs></svg>
