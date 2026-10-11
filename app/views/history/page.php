<?php
/**
 * The history page (screen `board-history`): three anchored sections — Changes (who, what, where; click to fly there), Versions (named ones bold; Preview, Restore) and, to an editor, Visits;
 * with ?version= the preview of that version's outline above them. Data: b (the gate's mcp_boards row), level, rows, total, page, f (member, source, since), last_visit, versions, preview
 * (null | [version row, outline html]), visits (null | rows), notice, tz
 */
$id = (string) $b['board_id'];
$base = '/boards/' . $id . '/history';
$q = static fn (array $over): string => $base . (($p = array_filter($over, static fn ($v) => $v !== null && $v !== '')) ? '?' . http_build_query($p) : '');
$chip = static function (string $label, string $url, bool $on, string $cid) use ($q): string {
    return '<a class="btn btn-sm ' . ($on ? 'btn-primary' : 'btn-light') . ' btn-touch" id="' . e($cid) . '" href="' . e($url) . '" hx-get="' . e($url) . '" hx-target="#page-content" hx-push-url="' . e($url) . '">' . e($label) . '</a>';
};
$keep = ['member' => $f['member'], 'source' => $f['source'], 'since' => $f['since']];
?>
<?= view('shared/page-header.php', ['id' => 'board-history', 'title' => 'History', 'crumbs' => [['Boards', '/boards'], [(string) $b['title'], '/boards/' . $id], ['History', null]], 'back' => back_link(),
    'action' => '<a class="btn btn-primary btn-touch" href="/boards/' . e($id) . '" id="board-history-canvas"><i class="feather-edit-3 me-1"></i>Open the canvas</a>']) ?>
<div class="main-content" id="board-history-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($preview !== null): ?><?= view('history/partials/preview.php', ['b' => $b, 'level' => $level, 'v' => $preview[0], 'outline' => $preview[1], 'tz' => $tz, 'base' => $base]) ?><?php endif; ?>

    <section class="mb-4" id="history-changes" aria-labelledby="history-changes-title">
        <h6 class="fw-semibold mb-2" id="history-changes-title">Changes <span class="text-muted fs-12 fw-normal">· <?= (int) $total ?></span></h6>
        <form method="get" action="<?= e($base) ?>" class="d-flex flex-wrap gap-2 align-items-center mb-2" id="history-filters">
            <?= $chip('Everyone', $q(['source' => $f['source'], 'since' => $f['since']]), $f['member'] === null, 'history-filter-everyone') ?>
            <?php if ($f['member'] !== null): ?><span class="badge bg-soft-primary text-primary" id="history-filter-member">One person<?= $rows !== [] && $rows[0]['member_name'] !== null ? ': ' . e((string) $rows[0]['member_name']) : '' ?></span><?php endif; ?>
            <?= $chip('Any source', $q(['member' => $f['member'], 'since' => $f['since']]), $f['source'] === null, 'history-filter-source-all') ?>
            <?php foreach (['web' => 'People', 'agent' => 'Agents', 'command_bar' => 'Command bar', 'restore' => 'Restores'] as $s => $label): ?>
                <?= $chip($label, $q(['member' => $f['member'], 'source' => $s, 'since' => $f['since']]), $f['source'] === $s, 'history-filter-source-' . $s) ?>
            <?php endforeach; ?>
            <?php if ($f['member'] !== null): ?><input type="hidden" name="member" value="<?= (int) $f['member'] ?>"><?php endif; ?>
            <?php if ($f['source'] !== null): ?><input type="hidden" name="source" value="<?= e($f['source']) ?>"><?php endif; ?>
            <label class="fs-12 text-muted d-flex align-items-center gap-1" for="history-filter-since">Since
                <input type="date" class="form-control form-control-sm" name="since" id="history-filter-since" value="<?= e((string) $f['since']) ?>"></label>
            <button type="submit" class="btn btn-sm btn-light btn-touch" id="history-filter-apply">Apply</button>
        </form>
        <?php if ($rows === []): ?>
            <div class="card"><div class="card-body text-muted" id="history-changes-empty"><?= array_filter($f, static fn ($v) => $v !== null) === [] ? 'Nothing has changed on this board yet.' : 'No changes match.' ?></div></div>
        <?php else: ?>
            <?= view('history/partials/change-list.php', ['b' => $b, 'rows' => $rows, 'last_visit' => $f['since'] === null ? $last_visit : null, 'tz' => $tz, 'page' => $page, 'total' => $total, 'f' => $f, 'q' => $q, 'keep' => $keep]) ?>
        <?php endif; ?>
    </section>

    <section class="mb-4" id="history-versions" aria-labelledby="history-versions-title">
        <h6 class="fw-semibold mb-2" id="history-versions-title">Versions</h6>
        <?php if (bd_level_at_least($level, 'edit')): ?><?= view('history/partials/save-form.php', ['board' => $id]) ?><?php endif; ?>
        <?php if ($versions === []): ?><div class="card"><div class="card-body text-muted" id="history-versions-empty">No versions yet. The board saves itself while it changes; save a moment of your own above.</div></div><?php endif; ?>
        <?php foreach ($versions as $v): ?><?= view('history/partials/version-row.php', ['board' => $id, 'v' => $v, 'level' => $level, 'tz' => $tz, 'base' => $base, 'shown' => $preview !== null && (int) $preview[0]['version_no'] === (int) $v['version_no']]) ?><?php endforeach; ?>
    </section>

    <?php if ($visits !== null): ?>
    <section class="mb-4" id="history-visits" aria-labelledby="history-visits-title">
        <h6 class="fw-semibold mb-2" id="history-visits-title">Visits <span class="text-muted fs-12 fw-normal">· the last 30 days</span></h6>
        <?= view('history/partials/visits.php', ['visits' => $visits, 'tz' => $tz]) ?>
    </section>
    <?php endif; ?>
</div>
