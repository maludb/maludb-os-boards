<?php
/**
 * A level as its badge — header, cards, lists, every slice (the locked vocabulary, walls-and-sharing.md: full `dark` "Owner" for the owner
 * and "Full" otherwise, edit `success` "Can edit", comment `info` "Can comment", view `secondary` "Can view"). Data: level, owner? (bool)
 */
$b = level_badge((string) ($level ?? ''), !empty($owner));
if ($b !== null): ?><span class="badge <?= e($b[0]) ?> level-badge" data-level="<?= e($level) ?>"><?= e($b[1]) ?></span><?php endif;
