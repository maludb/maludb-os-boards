<?php
declare(strict_types=1);
/**
 * Action `template_save` (log template.save — right boards.create, level view on the source): **board**, **title** (≤ 200), category (retrospective, brainstorm, strategy, planning, diagram, workshop or other;
 * else the board's own, else other). A PERSONAL template: a copy of the board only its maker sees and may use (bd_board_duplicate). record_id = the template; lands on its page.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/templates/write.php';
bd_handler_begin();
$pdo = db();
require_right('boards.create');
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
    $new = template_save($pdo, $id, (int) current_member_id(), (string) $title, $category);
    log_activity($pdo, 'template.save', 'board', $new, ['board_id' => $new, 'after' => ['source_board' => $id, 'title' => $title, 'scope' => 'personal',
        'category' => one_value($pdo, 'SELECT template_category FROM boards WHERE id = CAST(:b AS uuid)', ['b' => $new]), 'folder_id' => null]]);
    $pdo->commit();
    return $new;
});
bd_done('Saved ' . $title . ' as a template of yours', $new, bd_land('/templates/' . $new, 'saved'), 'boardChanged', ['board_id' => $new, 'source_board' => $id]);
