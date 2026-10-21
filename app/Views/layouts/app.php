<?php
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Settings;
use App\Core\Url;
use App\Core\View;
/** @var string $content */
$appName = (string)Config::get('app.name');
$user = Auth::user();
$initials = implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(preg_split('/\s+/', trim((string)$user['name'])) ?: [], 0, 2)));
$scripts = $scripts ?? [];
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(\App\Core\Csrf::token()) ?>">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' . $appName : $appName) ?></title>
    <script src="<?= asset('js/theme.js') ?>"></script>
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <link rel="icon" href="<?= asset('img/favicon.svg') ?>" type="image/svg+xml">
</head>
<body class="app"
      data-session-seconds="<?= (int)Auth::secondsLeft() ?>"
      data-session-extend-url="<?= url('/session/extend') ?>"
      data-login-url="<?= url('/login') ?>">
<a class="visually-hidden-focusable skip-link" href="#main">Skip to content</a>

<div class="app-shell">
    <aside class="sidebar offcanvas-lg offcanvas-start" id="sidebar" tabindex="-1" aria-label="Main navigation">
        <div class="sidebar-head">
            <a class="sidebar-brand" href="<?= url('/') ?>">
                <span class="brand-mark" aria-hidden="true"><i class="bi bi-stars"></i></span>
                <span>
                    <span class="brand-name"><?= e($appName) ?></span>
                    <span class="brand-sub"><?= e(Settings::get('app.company_name', '')) ?></span>
                </span>
            </a>
            <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close menu"></button>
        </div>

        <nav class="sidebar-nav">
            <?php foreach (require APP_ROOT . '/app/navigation.php' as $group):
                $items = array_filter($group['items'], fn($i) => in_array($user['role'], $i['roles'], true));
                if (!$items) { continue; } ?>
                <?php if ($group['heading']): ?>
                    <div class="nav-heading"><?= e($group['heading']) ?></div>
                <?php endif; ?>
                <ul class="nav flex-column">
                    <?php foreach ($items as $item): $active = is_active($item['path']); ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $active ? ' active' : '' ?>" href="<?= url($item['path']) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                                <i class="bi bi-<?= e($item['icon']) ?>" aria-hidden="true"></i>
                                <span><?= e($item['label']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-foot">
            <span class="env-dot env-<?= e(Config::get('app.env')) ?>" aria-hidden="true"></span>
            <?= e(ucfirst((string)Config::get('app.env'))) ?> · v<?= e(Config::get('app.version')) ?>
        </div>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open menu">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
            <div class="topbar-title">
                <h1 class="h5 mb-0"><?= e($title ?? '') ?></h1>
            </div>

            <div class="topbar-actions">
                <span class="clock-chip d-none d-md-inline-flex" title="Business time (IST)">
                    <i class="bi bi-clock" aria-hidden="true"></i>
                    <span data-ist-clock><?= e(Clock::now()->format('H:i')) ?></span> IST
                </span>
                <button type="button" class="btn btn-icon" data-theme-toggle aria-label="Switch colour theme" title="Switch light / dark">
                    <i class="bi bi-moon-stars theme-icon-dark" aria-hidden="true"></i>
                    <i class="bi bi-sun theme-icon-light" aria-hidden="true"></i>
                </button>

                <div class="dropdown">
                    <button class="user-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar" aria-hidden="true"><?= e($initials ?: '?') ?></span>
                        <span class="user-meta d-none d-sm-flex">
                            <span class="user-name"><?= e($user['name']) ?></span>
                            <span class="user-role"><?= e($user['role'] === 'admin' ? 'Administrator' : 'User') ?></span>
                        </span>
                        <i class="bi bi-chevron-down small" aria-hidden="true"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li class="dropdown-header">
                            <div class="fw-semibold text-body"><?= e($user['name']) ?></div>
                            <div class="small text-body-secondary"><?= e($user['email']) ?></div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= url('/profile') ?>"><i class="bi bi-person-circle me-2" aria-hidden="true"></i>My profile</a></li>
                        <li><a class="dropdown-item" href="<?= url('/profile') ?>#password"><i class="bi bi-key me-2" aria-hidden="true"></i>Change password</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="<?= url('/logout') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <main id="main" class="app-content" tabindex="-1">
            <?= View::partial('flash') ?>
            <?= $content ?>
        </main>
    </div>
</div>

<!-- Session timeout warning -->
<div class="modal fade" id="sessionModal" tabindex="-1" aria-labelledby="sessionModalTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center p-4">
                <div class="session-icon mb-3"><i class="bi bi-hourglass-split" aria-hidden="true"></i></div>
                <h2 class="h6" id="sessionModalTitle">Still there?</h2>
                <p class="text-body-secondary small mb-3">You’ll be signed out in <strong data-session-countdown>5:00</strong> for security.</p>
                <button type="button" class="btn btn-primary w-100" data-session-extend>Stay signed in</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset('vendor/bootstrap.bundle.min.js') ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= asset($s) ?>"></script>
<?php endforeach; ?>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
