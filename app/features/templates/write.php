<?php
declare(strict_types=1);

/**
 * Templates — the writes (slice 8): a board duplicated, a template used, a board saved as a personal template or published for the business. Each is the
 * database's ONE function, bd_board_duplicate (db/011): the right to create, the view on the source, the publish right, the copy's new ids and their mapping, the
 * copy being private to its maker — all its. PHP only files the copy in a folder when one was asked for.
 */

/** A copy of a board in the caller's name (a title given, else "{title} (copy)"), filed in $folder when given. The new board's id. */
function board_duplicate(PDO $pdo, string $board, int $by, ?string $title, ?int $folder): string
{
    return duplicate_into($pdo, $board, $by, $title, null, null, $folder);
}

/** A board made from a template: the template's title when none is given, filed in $folder when given. The new board's id. */
function template_use(PDO $pdo, string $template, int $by, ?string $title, ?int $folder): string
{
    return duplicate_into($pdo, $template, $by, $title, null, null, $folder);
}

/** The board saved as the caller's personal template (the category when given, else its own, else "other"). The template's id. */
function template_save(PDO $pdo, string $board, int $by, string $title, ?string $category): string
{
    return duplicate_into($pdo, $board, $by, $title, 'personal', $category, null);
}

/** The board published as a template every Member sees (templates.publish — the database refuses anyone else in its words). The template's id. */
function template_publish(PDO $pdo, string $board, int $by, string $title, ?string $category): string
{
    return duplicate_into($pdo, $board, $by, $title, 'business', $category, null);
}

function duplicate_into(PDO $pdo, string $source, int $by, ?string $title, ?string $scope, ?string $category, ?int $folder): string
{
    $st = $pdo->prepare('SELECT bd_board_duplicate(CAST(:b AS uuid), :m, CAST(:t AS text), CAST(:s AS text), CAST(:c AS text))');
    $st->execute(['b' => $source, 'm' => $by, 't' => $title, 's' => $scope, 'c' => $category]);
    $new = (string) $st->fetchColumn();
    if ($folder !== null) {
        $pdo->prepare('SELECT bd_folder_add(:m, :f, CAST(:b AS uuid))')->execute(['m' => $by, 'f' => $folder, 'b' => $new]);
    }
    return $new;
}
