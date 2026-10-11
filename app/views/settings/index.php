<?php
/**
 * My settings (screen `settings`): how I am told — email on or off, the morning digest (one email at 07:00 in my time zone) instead of one per event, texts on or off
 * (the kernel keeps the verified phone and the opt-out: a text is sent only when it knows both), the kinds by email and by text. Data: p (prefs_of), notice, tokens (bool)
 */
?>
<?= view('shared/page-header.php', ['id' => 'settings', 'title' => 'My settings', 'crumbs' => [['Home', '/'], ['My settings', null]], 'back' => back_link()]) ?>
<div class="main-content" id="settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/settings/prefs.php" id="settings-form" class="page-header-form" hx-post="/settings/prefs.php" hx-target="#page-content" hx-swap="innerHTML">
        <?= csrf_field() ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Email</h5></div><div class="card-body">
            <input type="hidden" name="email_enabled__sent" value="1"><input type="hidden" name="digest__sent" value="1"><input type="hidden" name="kinds__sent" value="1">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" name="email_enabled" value="1" id="settings-form-field-email_enabled"<?= $p['email_enabled'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="settings-form-field-email_enabled">Tell me by email</label>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" name="digest" value="1" id="settings-form-field-digest"<?= $p['digest'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="settings-form-field-digest">One digest each morning instead of one email per notice</label>
                <div class="form-text">The digest comes at 07:00 in your own time zone and lists what you have not read from the last 24 hours. It sends nothing when there is nothing.</div>
            </div>
            <div class="fw-semibold fs-12 mb-1">By email, tell me when</div>
            <?php foreach (NOTICE_KINDS as $k => $words): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="kinds[]" value="<?= e($k) ?>" id="settings-form-field-kinds-<?= e($k) ?>"<?= in_array($k, $p['kinds'], true) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="settings-form-field-kinds-<?= e($k) ?>"><?= e($words) ?></label>
                </div>
            <?php endforeach; ?>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Text messages</h5></div><div class="card-body">
            <input type="hidden" name="text_enabled__sent" value="1"><input type="hidden" name="text_kinds__sent" value="1">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" name="text_enabled" value="1" id="settings-form-field-text_enabled"<?= $p['text_enabled'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="settings-form-field-text_enabled">Tell me by text</label>
                <div class="form-text">A text goes to your verified phone only — the Business OS keeps the number and your opt-out, and sends nothing if either is missing.</div>
            </div>
            <div class="fw-semibold fs-12 mb-1">By text, tell me when</div>
            <?php foreach (NOTICE_KINDS as $k => $words): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="text_kinds[]" value="<?= e($k) ?>" id="settings-form-field-text_kinds-<?= e($k) ?>"<?= in_array($k, $p['text_kinds'], true) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="settings-form-field-text_kinds-<?= e($k) ?>"><?= e($words) ?></label>
                </div>
            <?php endforeach; ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch" id="settings-form-save-btn">Save</button>
        <?php if ($tokens): ?><a class="btn btn-light btn-touch ms-2" id="settings-tokens-link" href="/settings/tokens/" hx-get="/settings/tokens/" hx-target="#page-content" hx-push-url="/settings/tokens/"><i class="feather-key me-1"></i>My tokens</a><?php endif; ?>
    </form>
</div>
