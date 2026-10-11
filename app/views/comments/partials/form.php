<?php
/**
 * The composer: a textarea (plain text; "@" opens the people-and-agents picker, which inserts "@Name") and the two mention pickers (a person or an agent,
 * a department). A new thread (`root` empty) carries the anchor the island fills (item, x, y, context[]); a reply carries its root.
 * Data: board, root (the thread's root id or ''), base (the id stem), target (hx-target), swap (hx-swap), button (the label), cancel (bool — a Cancel button for the new thread)
 */
$new = $root === '';
$field = $new ? 'comment-form-field-text' : 'comment-reply-' . $root;
$formId = $new ? 'comment-new-form' : 'comment-reply-form-' . $root;
$action = $new ? '/comments/create.php' : '/comments/reply.php';
?>
<form method="post" action="<?= e($action) ?>" id="<?= e($formId) ?>" class="comment-composer" hx-post="<?= e($action) ?>" hx-target="<?= e($target) ?>" hx-swap="<?= e($swap) ?>"
      hx-on::after-request="if (event.detail.successful && event.detail.elt === this) { this.reset(); }">
    <?= csrf_field() ?>
    <?php if ($new): ?>
        <input type="hidden" name="board" value="<?= e($board) ?>"><input type="hidden" name="item" value=""><input type="hidden" name="x" value=""><input type="hidden" name="y" value="">
    <?php else: ?>
        <input type="hidden" name="comment" value="<?= e($root) ?>">
    <?php endif; ?>
    <textarea name="text" id="<?= e($field) ?>" class="form-control mb-2" rows="3" maxlength="<?= (int) COMMENT_MAX_CHARS ?>" required placeholder="<?= $new ? 'Write a comment — type @ to mention' : 'Reply — type @ to mention' ?>"
              data-mention-box="1" data-mention-picker="<?= e($base) ?>-mention" aria-label="<?= $new ? 'Comment' : 'Reply' ?>"></textarea>
    <div class="row g-2 mb-2">
        <div class="col-12"><?= picker_field(['id' => $base . '-mention', 'name' => 'mention_pick', 'source' => 'member', 'placeholder' => '@ A person or an agent', 'title' => 'Mention', 'extra' => ' data-mention-for="' . e($field) . '"']) ?></div>
        <div class="col-12"><?= picker_field(['id' => $base . '-mention-dept', 'name' => 'mention_pick_department', 'source' => 'department', 'placeholder' => '@ A department', 'title' => 'Mention a department', 'extra' => ' data-mention-for="' . e($field) . '"']) ?></div>
    </div>
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-touch" id="<?= e($formId) ?>-send"><?= e($button) ?></button>
        <?php if (!empty($cancel)): ?><button type="button" class="btn btn-light btn-touch" id="comment-new-cancel">Cancel</button><?php endif; ?>
    </div>
</form>
