<?php
declare(strict_types=1);
/** /folders/new and /folders/{id}/edit — screens `folder-add` and `folder-edit`: placeholders until slice 1 builds them (sso-shell.md). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub(isset($_GET['id']) ? 'folder-edit' : 'folder-add', 1);
