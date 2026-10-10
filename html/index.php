<?php
declare(strict_types=1);
/**
 * / — Home (screen `home`, slice 10's; sso-shell.md divergence 1): until slice 10 the placeholder, with one live link — Boards (/boards,
 * itself slice 1's placeholder until slice 1).
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
render_nav_stub('home', 10);
