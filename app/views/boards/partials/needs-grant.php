<?php /** The warning under a principal who cannot open Boards yet (no Boards grant — the super-admin's to give in the OS). Data: name, rowId */ ?>
<div class="alert alert-warning py-1 px-2 mt-2 mb-0 fs-12" id="<?= e($rowId) ?>-needs-grant"><i class="feather-alert-triangle me-1"></i><?= e($name) ?> cannot open Boards yet — ask a super-admin to grant it in the OS.</div>
