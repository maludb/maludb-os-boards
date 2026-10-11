<?php
declare(strict_types=1);
/**
 * Action `template_publish` (log template.publish — right templates.publish, level view on the source; the kernel pauses an agent's call: other): **board**, **title** (≤ 200), category (as template_save).
 * A BUSINESS template: a copy every Member who is not external sees and may use (bd_board_duplicate, 'business'). record_id = the template; lands on its page.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/write.php';
bd_handler_begin();
$pdo = db();
require_right('templates.publish');
$b = bd_board_or_404($pdo, (string) (req_val('board') ?? ''), 'view');
$id = (string) $b['board_id'];
$errors = [];
$title = bd_text('title', null, 200, 'The title', $errors, true);
if (!req_has('title') || ($title === null && !isset($errors['title']))) {
    $errors['title'] ??= 'Give the template a title.';
}
$category = bd_choice('category', null, array_keys(TEMPLATE_CATEGORIES), 'The category', $errors);
if ($errors !== []) {
    bd_refuse_fields($errors);
}
$new = bd_guard($pdo, static function () use ($pdo, $id, $title, $category): string {
    $pdo->beginTransaction();
    $new = template_publish($pdo, $id, (int) current_member_id(), (string) $title, $category);
    log_activity($pdo, 'template.publish', 'board', $new, ['board_id' => $new, 'after' => ['source_board' => $id, 'title' => $title, 'scope' => 'business',
        'category' => one_value($pdo, 'SELECT template_category FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $new]), 'folder_id' => null]]);
    $pdo->commit();
    return $new;
});
bd_done('Published ' . $title . ' for the business', $new, bd_land('/templates/' . $new, 'published'), 'boardChanged', ['board_id' => $new, 'source_board' => $id]);
