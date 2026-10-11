<?php
/**
 * The list's filters: chips All · Mine · Shared with me · Favourites (plain links, each keeping the folder and the words), the search field
 * (title or owner — a plain GET), the folder (the `folder` picker; choosing one reloads the cards). Data: filters, folder (row or null)
 */
$url = static function (array $change) use ($filters): string {
    $p = array_filter(array_merge($filters, $change), static fn ($v) => $v !== null && $v !== '' && $v !== false);
    foreach (['shared', 'favorites'] as $k) { if (isset($p[$k])) { $p[$k] = 1; } }
    return '/boards' . ($p === [] ? '' : '?' . http_build_query($p));
};
$chips = [
    'all'       => ['All', !isset($filters['owner']) && empty($filters['shared']) && empty($filters['favorites']), ['owner' => null, 'shared' => null, 'favorites' => null]],
    'mine'      => ['Mine', ($filters['owner'] ?? null) === 'me', ['owner' => 'me', 'shared' => null, 'favorites' => null]],
    'shared'    => ['Shared with me', !empty($filters['shared']), ['owner' => null, 'shared' => true, 'favorites' => null]],
    'favorites' => ['Favourites', !empty($filters['favorites']), ['owner' => null, 'shared' => null, 'favorites' => true]],
];
?>
<div class="mb-3" id="boards-filters">
    <div class="d-flex flex-wrap gap-1 mb-2" role="group" aria-label="Show">
        <?php foreach ($chips as $key => [$label, $on, $change]): ?>
            <?= hx_link($url($change + ['page' => null]), e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="boards-filter-' . $key . '"' . ($on ? ' aria-current="true"' : '')) ?>
        <?php endforeach; ?>
    </div>
    <div class="row g-2">
        <div class="col-12 col-md-6">
            <form method="get" action="/boards" id="boards-filter-q-form" role="search" hx-get="/boards" hx-target="#page-content" hx-push-url="true">
                <?php foreach (array_diff_key($filters, ['q' => 1]) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v === true ? '1' : (string) $v) ?>"><?php endforeach; ?>
                <div class="input-group">
                    <input type="search" name="q" id="boards-filter-q" class="form-control btn-touch" value="<?= e($filters['q'] ?? '') ?>" maxlength="120" placeholder="A title, or its owner" aria-label="Search boards">
                    <button type="submit" class="btn btn-light-brand btn-touch" id="boards-filter-q-btn" aria-label="Search"><i class="feather-search"></i></button>
                </div>
            </form>
        </div>
        <div class="col-12 col-md-6">
            <form method="get" action="/boards" id="boards-filter-folder-form" hx-get="/boards" hx-target="#page-content" hx-push-url="true" hx-trigger="change">
                <?php foreach (array_diff_key($filters, ['folder' => 1]) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v === true ? '1' : (string) $v) ?>"><?php endforeach; ?>
                <?= picker_field(['id' => 'boards-filter-folder', 'name' => 'folder', 'source' => 'folder', 'value' => $folder['folder_id'] ?? null, 'label' => $folder['name'] ?? null,
                    'placeholder' => 'Any folder', 'title' => 'Folders', 'search' => 'Search your folders…']) ?>
                <noscript><button type="submit" class="btn btn-light btn-sm mt-1">Show</button></noscript>
            </form>
        </div>
    </div>
</div>
