<?php
use App\Core\Auth;
/** @var int $status @var string $title @var string $message */
$icons = [403 => 'shield-lock', 404 => 'signpost-split', 419 => 'arrow-clockwise', 429 => 'speedometer'];
?>
<div class="<?= Auth::check() ? 'error-page' : 'card guest-card' ?>">
    <div class="<?= Auth::check() ? '' : 'card-body' ?> text-center py-4">
        <div class="error-icon mb-3"><i class="bi bi-<?= e($icons[$status] ?? 'exclamation-circle') ?>" aria-hidden="true"></i></div>
        <div class="error-code"><?= (int)$status ?></div>
        <h2 class="h4"><?= e($title) ?></h2>
        <p class="text-body-secondary mb-4"><?= e($message) ?></p>
        <a class="btn btn-primary" href="<?= url(Auth::check() ? '/' : '/login') ?>">
            <?= Auth::check() ? 'Back to dashboard' : 'Go to sign in' ?>
        </a>
    </div>
</div>
