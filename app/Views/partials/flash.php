<?php
use App\Core\Session;

$icons = ['success' => 'check-circle-fill', 'danger' => 'x-octagon-fill', 'warning' => 'exclamation-triangle-fill', 'info' => 'info-circle-fill'];
foreach (Session::pullFlash() as $f):
    $type = isset($icons[$f['type']]) ? $f['type'] : 'info'; ?>
    <div class="alert alert-<?= e($type) ?> alert-dismissible fade show d-flex gap-2 align-items-start" role="<?= $type === 'danger' ? 'alert' : 'status' ?>">
        <i class="bi bi-<?= e($icons[$type]) ?> flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div><?= e($f['message']) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>
