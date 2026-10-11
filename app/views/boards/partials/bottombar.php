<?php
/** The bottom bar (canvas.md): Undo/Redo of MY changes (an editor's), Prev / Next frame (the navigator, slice 4), zoom −/+, the percentage (back
 *  to 100 %), Fit. Data: editable */
?>
<div class="board-bottombar" id="board-bottombar">
    <?php if ($editable): ?>
        <div class="board-bar-group">
            <button type="button" class="board-tool" id="board-undo" title="Undo (Ctrl+Z)" disabled><i class="feather-corner-up-left"></i><span class="visually-hidden">Undo</span></button>
            <button type="button" class="board-tool" id="board-redo" title="Redo (Ctrl+Shift+Z)" disabled><i class="feather-corner-up-right"></i><span class="visually-hidden">Redo</span></button>
        </div>
    <?php endif; ?>
    <div class="board-bar-group" id="board-frame-nav">
        <button type="button" class="board-tool" id="board-frame-prev" title="The previous frame"><i class="feather-chevron-left"></i><span class="visually-hidden">Previous frame</span></button>
        <button type="button" class="board-tool" id="board-frame-next" title="The next frame"><i class="feather-chevron-right"></i><span class="visually-hidden">Next frame</span></button>
    </div>
    <div class="board-bar-group">
        <button type="button" class="board-tool" id="board-zoom-out" title="Zoom out"><i class="feather-minus"></i><span class="visually-hidden">Zoom out</span></button>
        <button type="button" class="board-tool board-zoom-label" id="board-zoom-label" title="Back to 100 %">100%</button>
        <button type="button" class="board-tool" id="board-zoom-in" title="Zoom in"><i class="feather-plus"></i><span class="visually-hidden">Zoom in</span></button>
        <button type="button" class="board-tool" id="board-zoom-fit" title="Fit the board"><i class="feather-maximize"></i><span class="visually-hidden">Fit</span></button>
    </div>
</div>
