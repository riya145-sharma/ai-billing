<?php
use App\Core\Config;
use App\Core\Settings;
use App\Core\View;
/** @var string $content */
$appName = (string)Config::get('app.name');
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' . $appName : $appName) ?></title>
    <script src="<?= asset('js/theme.js') ?>"></script>
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">
</head>
<body class="guest">
<main class="guest-main">
    <div class="guest-card-wrap">
        <div class="guest-brand">
            <span class="brand-mark" aria-hidden="true"><i class="bi bi-stars"></i></span>
            <div>
                <div class="brand-name"><?= e($appName) ?></div>
                <div class="brand-sub"><?= e(Settings::get('app.company_name', '')) ?></div>
            </div>
        </div>
        <?= View::partial('flash') ?>
        <?= $content ?>
        <p class="guest-foot">
            <i class="bi bi-shield-lock" aria-hidden="true"></i>
            Secure sign-in · times shown in IST
        </p>
    </div>
</main>
<script src="<?= asset('vendor/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
