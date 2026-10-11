<?php /** Who opened the board in the last 30 days: name, opens, edits, last opened. Data: visits, tz */ ?>
<?php if ($visits === []): ?><div class="card"><div class="card-body text-muted" id="history-visits-empty">Nobody has opened this board in the last 30 days.</div></div><?php endif; ?>
<?php foreach ($visits as $t): ?>
<div class="card record-card mb-2" id="visit-row-<?= (int) $t['member_id'] ?>"><div class="card-body py-2">
    <div class="d-flex flex-wrap justify-content-between gap-2">
        <div class="min-w-0"><span class="fw-semibold text-break"><?= e((string) $t['name']) ?></span><?php if ($t['kind'] === 'agent'): ?> <span class="badge bg-soft-info text-info">agent</span><?php endif; ?></div>
        <div class="fs-12 text-muted"><?= (int) $t['opens'] ?> open<?= (int) $t['opens'] === 1 ? '' : 's' ?> · <?= (int) $t['edits'] ?> edit<?= (int) $t['edits'] === 1 ? '' : 's' ?> · last <span title="<?= e(format_ts($t['last_at'], $tz)) ?>"><?= e(ago_words($t['last_at']) ?? '') ?></span></div>
    </div>
</div></div>
<?php endforeach; ?>
