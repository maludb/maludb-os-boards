<?php /** My trail (screen `activity`): what I did here, in sentences. Data: filters, boardTitle, resultsHtml */ ?>
<?= view('shared/page-header.php', ['id' => 'activity', 'title' => 'My trail', 'crumbs' => [['Home', '/'], ['My trail', null]], 'back' => back_link()]) ?>
<div class="main-content" id="activity-content">
    <div class="card stretch stretch-full" id="activity-card">
        <div class="card-header flex-wrap gap-2">
            <h5 class="card-title">What you did here</h5>
            <form id="activity-filters" class="d-flex flex-wrap gap-2 align-items-center" method="get" action="/activity" hx-get="/activity" hx-target="#activity-results" hx-swap="outerHTML" hx-trigger="change" hx-push-url="true">
                <select name="since" id="activity-filter-since" class="form-select form-select-sm w-auto btn-touch" aria-label="Since"><option value="">All time</option><?php foreach ([1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $v => $l): ?><option value="<?= $v ?>" <?= $filters['since'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <?php if ($filters['board'] !== null): ?>
                    <input type="hidden" name="board" value="<?= e($filters['board']) ?>">
                    <span class="badge bg-soft-primary text-primary d-inline-flex align-items-center gap-1" id="activity-filter-board">On <?= e($boardTitle ?? 'a board') ?>
                        <a href="/activity" class="text-primary" aria-label="Every board" id="activity-filter-board-clear" hx-get="/activity" hx-target="#page-content" hx-push-url="/activity"><i class="feather-x"></i></a></span>
                <?php endif; ?>
                <noscript><button type="submit" class="btn btn-light btn-sm">Show</button></noscript>
            </form>
        </div>
        <?= $resultsHtml ?>
    </div>
</div>
