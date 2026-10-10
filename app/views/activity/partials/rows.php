<?php /** The trail's rows — the swapped region. Each sentence names the board by a link (the board's own trail: ?board=). Data: rows, page, total, query, tz */
$pageUrl = static fn (int $p): string => '/activity?' . http_build_query($query + ['page_no' => $p]);
$more = $page * ACTIVITY_PAGE < $total;
?>
<div id="activity-results" class="card-body custom-card-action p-0">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="activity-table">
            <thead class="thead-light"><tr>
                <th id="activity-col-when">When</th><th id="activity-col-what">What</th><th id="activity-col-source" class="d-none d-md-table-cell">How</th>
            </tr></thead>
            <tbody id="activity-tbody">
            <?php if ($rows === []): ?>
                <tr><td colspan="3" class="text-center text-muted py-4" id="activity-empty">Nothing yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): $p = $r['parts']; ?>
                <tr id="activity-row-<?= (int) $r['activity_id'] ?>">
                    <td class="text-nowrap fs-12" title="<?= e($r['occurred_at']) ?>"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></td>
                    <td class="text-wrap"><?= e($p['text']) ?><?php if ($p['board'] !== null): ?> <?= hx_link('/activity?board=' . rawurlencode((string) $r['board_id']), '<em>' . e($p['board']) . '</em>', 'activity-board-link') ?><?php endif; ?></td>
                    <td class="d-none d-md-table-cell"><span class="badge bg-soft-<?= ($r['source'] ?? '') === 'agent' ? 'info text-info' : (($r['source'] ?? '') === 'web' ? 'secondary text-dark' : 'warning text-warning') ?>"><?= e($r['source']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($page > 1 || $more): ?>
    <nav class="p-3" id="activity-pagination"><ul class="pagination mb-0">
        <?php if ($page > 1): ?><li class="page-item"><a class="page-link" href="<?= e($pageUrl($page - 1)) ?>" hx-get="<?= e($pageUrl($page - 1)) ?>" hx-target="#activity-results" hx-swap="outerHTML" hx-push-url="<?= e($pageUrl($page - 1)) ?>">Newer</a></li><?php endif; ?>
        <?php if ($more): ?><li class="page-item"><a class="page-link" href="<?= e($pageUrl($page + 1)) ?>" hx-get="<?= e($pageUrl($page + 1)) ?>" hx-target="#activity-results" hx-swap="outerHTML" hx-push-url="<?= e($pageUrl($page + 1)) ?>">Older</a></li><?php endif; ?>
    </ul></nav>
    <?php endif; ?>
</div>
