<?php
/** A category's tile — the gallery's picture until a template has a preview of its own: a coloured block with the category's feather icon. Data: category, id? (the card's id for the element) */
$c = isset(TEMPLATE_ICONS[$category ?? '']) ? $category : 'other';
?>
<span class="board-card-thumb template-tile rounded flex-shrink-0 bg-soft-<?= e(TEMPLATE_TILES[$c]) ?> text-<?= e(TEMPLATE_TILES[$c]) ?>" aria-hidden="true"<?= isset($id) ? ' id="' . e($id) . '"' : '' ?>><i class="feather-<?= e(TEMPLATE_ICONS[$c]) ?>"></i></span>
