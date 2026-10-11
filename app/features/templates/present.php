<?php
declare(strict_types=1);

/** The JSON shapes of templates (slice 8) — whitelists, never a raw row; a template's URL with every shape. */

function present_template_row(array $t): array
{
    $scope = (string) $t['template_scope'];
    return ['board_id' => (string) $t['board_id'], 'title' => $t['title'], 'description' => $t['description'],
            'scope' => $scope, 'scope_words' => TEMPLATE_SCOPES[$scope][0] ?? $scope,
            'category' => $t['template_category'], 'category_words' => TEMPLATE_CATEGORIES[$t['template_category']] ?? $t['template_category'],
            'item_count' => (int) $t['item_count'], 'by' => $scope === 'shipped' ? 'Shipped with Boards' : $t['owner_name'],
            'thumbnail_url' => !empty($t['thumbnail_id']) ? '/boards/' . $t['board_id'] . '/thumbnail?v=' . (int) $t['thumbnail_id'] : null,
            'url' => '/templates/' . $t['board_id']];
}

function present_template(array $t, string $outline): array
{
    return present_template_row($t) + ['my_level' => $t['my_level'], 'outline' => $outline, 'open_url' => '/boards/' . $t['board_id'], 'created_at' => json_ts($t['created_at'])];
}
