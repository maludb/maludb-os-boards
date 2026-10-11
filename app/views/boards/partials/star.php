<?php
/** The favourite star (board_favorite / board_unfavorite): a form that answers itself over HTMX (hx-swap outerHTML); a plain POST lands back. Data: id, on, return */
$action = $on ? '/boards/unfavorite.php' : '/boards/favorite.php';
?>
<form method="post" action="<?= e($action) ?>" id="board-card-<?= e($id) ?>-star" class="board-star flex-shrink-0" hx-post="<?= e($action) ?>" hx-target="this" hx-swap="outerHTML">
    <?= csrf_field() ?><input type="hidden" name="board" value="<?= e($id) ?>"><input type="hidden" name="return_to" value="<?= e($return) ?>">
    <button type="submit" class="btn btn-link btn-touch p-0 <?= $on ? 'text-warning is-on' : 'text-muted' ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"
            aria-label="<?= $on ? 'Remove from favourites' : 'Add to favourites' ?>" title="<?= $on ? 'Favourite' : 'Add to favourites' ?>"><i class="<?= $on ? 'bi bi-star-fill' : 'bi bi-star' ?>"></i></button>
</form>
