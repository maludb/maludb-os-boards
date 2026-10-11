<?php
/**
 * One batch: the avatar and name (an agent wears the agent chip, a restore the restore chip), what changed in words, when, Show (fly to it) and, for an undo, what it undid.
 * Data: b, r (a board_changes row), tz, f, q
 */
$id = (string) $b['board_id'];
$seq = (int) $r['seq'];
$name = (string) ($r['member_name'] ?? 'Someone who has since left');
$agent = ($r['member_kind'] ?? '') === 'agent' || $r['source'] === 'agent';
$chips = ['agent' => ['info', 'agent'], 'command_bar' => ['secondary', 'command bar'], 'restore' => ['warning', 'restore']];
$chip = $chips[(string) $r['source']] ?? ($agent ? $chips['agent'] : null);
$fly = change_fly_to((array) $r['summary']);
$undid = (array) $r['undoes_seq'];
?>
<div class="card record-card mb-2" id="change-row-<?= $seq ?>"><div class="card-body py-2">
    <div class="d-flex gap-3 align-items-start">
        <span class="comment-avatar<?= $agent ? ' is-agent' : '' ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($name) ?: '?', 0, 1))) ?></span>
        <div class="min-w-0 flex-grow-1">
            <div class="d-flex flex-wrap align-items-center gap-1">
                <?php if ($r['member_id'] !== null): ?><a class="fw-semibold text-break" href="<?= e($q(['member' => (int) $r['member_id']])) ?>" title="Only this person's changes"><?= e($name) ?></a><?php else: ?><span class="fw-semibold"><?= e($name) ?></span><?php endif; ?>
                <?php if ($chip !== null): ?><span class="badge bg-soft-<?= e($chip[0]) ?> text-<?= e($chip[0]) ?>"><?= e($chip[1]) ?></span><?php endif; ?>
            </div>
            <div class="fs-13 text-break"><?= e(change_words((array) $r['summary'])) ?><?= $undid !== [] ? ' · undid ' . e(implode(', ', array_map(static fn ($n): string => '#' . $n, $undid))) : '' ?></div>
            <div class="fs-12 text-muted d-flex flex-wrap gap-3 mt-1">
                <span title="<?= e(format_ts($r['at'], $tz)) ?>"><?= e(ago_words($r['at']) ?? '') ?></span>
                <span>#<?= $seq ?></span>
                <?php if ($fly !== null): ?><a class="fw-semibold" id="change-show-<?= $seq ?>" href="/boards/<?= e($id) ?>?x=<?= e((string) $fly['x']) ?>&amp;y=<?= e((string) $fly['y']) ?>&amp;zoom=1"><i class="feather-crosshair me-1"></i>Show</a><?php endif; ?>
            </div>
        </div>
    </div>
</div></div>
