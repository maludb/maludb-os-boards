<?php /** The first-run state (nothing to see yet): "Make your first board", the two buttons and the shipped templates as cards. Data: templates, may_create */ ?>
<div class="card mb-4" id="home-first-run"><div class="card-body">
    <div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="feather-layout"></i></span>
        <div class="min-w-0"><div class="fw-semibold">Make your first board</div>
            <div class="fs-12 text-muted mb-2">A board is an infinite canvas for stickies, shapes and connectors. Start empty, or from a template below.</div>
            <?php if ($may_create): ?>
                <div class="d-flex flex-wrap gap-2">
                    <?= hx_link('/boards/new', '<i class="feather-plus me-1"></i>New board', 'btn btn-primary btn-touch', 'id="home-new-board"') ?>
                    <?= hx_link('/templates', '<i class="feather-copy me-1"></i>New from template', 'btn btn-light btn-touch', 'id="home-new-from-template"') ?>
                </div>
            <?php else: ?>
                <div class="fs-12 text-muted" id="home-first-run-viewer">Boards shared with you will appear here.</div>
            <?php endif; ?>
        </div></div>
</div></div>
<?php if ($templates !== []): ?>
    <section class="mb-4" id="home-section-templates">
        <h6 class="mb-2"><i class="feather-copy me-2"></i>Start from a template</h6>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2">
            <?php foreach ($templates as $t): ?><div class="col"><?= view('templates/partials/card.php', ['t' => $t, 'back' => '/', 'may_use' => $may_create]) ?></div><?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
