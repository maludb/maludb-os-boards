<?php
/** Seeds the proofs' SMOKE board (idempotent) and prints its uuid — the browser proof calls this before it opens a board's placeholder. */
require __DIR__ . '/lib.php';
echo seed_board(), "\n";
