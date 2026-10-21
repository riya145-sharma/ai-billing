<?php
use App\Core\Session;
use App\Core\View;
/** @var array $profile @var int $minPassword */

$initials = implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(preg_split('/\s+/', trim((string)$profile['name'])) ?: [], 0, 2)));
$isAdmin = $profile['role'] === 'admin';
$name = Session::old('name', (string)$profile['name']);
?>
<section class="profile-hero mb-4" aria-label="Account summary">
    <span class="profile-avatar" aria-hidden="true"><?= e($initials ?: '?') ?></span>
    <div class="flex-grow-1 min-w-0">
        <h2 class="profile-name"><?= e($profile['name']) ?></h2>
        <div class="profile-email"><i class="bi bi-envelope" aria-hidden="true"></i> <?= e($profile['email']) ?></div>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <span class="st-pill <?= $isAdmin ? 'st-info' : 'st-idle' ?>"><i class="bi bi-<?= $isAdmin ? 'shield-lock' : 'person' ?>" aria-hidden="true"></i><?= $isAdmin ? 'Administrator' : 'User' ?></span>
            <span class="st-pill st-good"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Active account</span>
        </div>
    </div>
    <dl class="profile-facts">
        <div><dt>Member since</dt><dd><?= e(View::dateTime($profile['created_at'] ?? null, 'd M Y')) ?></dd></div>
        <div><dt>Last sign-in</dt><dd><?= e(View::dateTime($profile['last_login_at'] ?? null, 'd M Y, H:i')) ?></dd></div>
        <div><dt>Password changed</dt><dd><?= e(View::relative($profile['password_changed_at'] ?? null)) ?></dd></div>
    </dl>
</section>

<div class="row g-3">
    <div class="col-12 col-xl-5">
        <section class="card h-100" id="details" aria-labelledby="details-title">
            <div class="card-header"><h2 class="card-title" id="details-title"><i class="bi bi-person-vcard" aria-hidden="true"></i> Personal details</h2></div>
            <div class="card-body">
                <form method="post" action="<?= url('/profile') ?>" data-once>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="name">Display name</label>
                        <input class="form-control" id="name" name="name" value="<?= e($name) ?>" maxlength="100" required autocomplete="name">
                        <div class="form-text">Shown in the top bar and to administrators.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="email">Email</label>
                        <div class="input-group">
                            <span class="input-group-text" aria-hidden="true"><i class="bi bi-lock"></i></span>
                            <input class="form-control" id="email" value="<?= e($profile['email']) ?>" readonly aria-describedby="email-help">
                        </div>
                        <div class="form-text" id="email-help">Your email is your sign-in name. Ask an administrator to change it.</div>
                    </div>
                    <button class="btn btn-primary" type="submit"><span class="btn-label">Save details</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
                </form>
            </div>
        </section>
    </div>

    <div class="col-12 col-xl-7">
        <section class="card h-100" id="password" aria-labelledby="password-title">
            <div class="card-header"><h2 class="card-title" id="password-title"><i class="bi bi-key" aria-hidden="true"></i> Change password</h2></div>
            <div class="card-body">
                <form method="post" action="<?= url('/profile/password') ?>" data-once class="form-narrow-lg">
                    <?= csrf_field() ?>
                    <input type="text" class="d-none" name="username" value="<?= e($profile['email']) ?>" autocomplete="username" tabindex="-1" aria-hidden="true">
                    <?php foreach ([
                        ['current_password', 'Current password', 'current-password', null],
                        ['new_password', 'New password', 'new-password', $minPassword],
                        ['confirm_password', 'Confirm new password', 'new-password', $minPassword],
                    ] as [$field, $label, $ac, $min]): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="<?= $field ?>"><?= e($label) ?></label>
                            <div class="input-group">
                                <input class="form-control" type="password" id="<?= $field ?>" name="<?= $field ?>" maxlength="4096" required autocomplete="<?= $ac ?>"<?= $min ? ' minlength="' . (int)$min . '"' : '' ?>>
                                <button class="btn btn-outline-secondary" type="button" data-toggle-password="#<?= $field ?>" aria-label="Show password"><i class="bi bi-eye" aria-hidden="true"></i></button>
                            </div>
                            <?php if ($field === 'new_password'): ?>
                                <div class="pw-meter mt-2" data-pw-meter="#new_password" data-min="<?= (int)$minPassword ?>" data-score="0" aria-live="polite">
                                    <span class="pw-bars" aria-hidden="true"><span></span><span></span><span></span><span></span></span>
                                    <span class="pw-text small"></span>
                                </div>
                                <div class="form-text">At least <?= (int)$minPassword ?> characters. A short phrase of several words is easy to remember and strong.</div>
                            <?php elseif ($field === 'confirm_password'): ?>
                                <div class="form-text pw-match d-none" data-pw-match="#new_password" aria-live="polite"></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="notice notice-info mb-3"><i class="bi bi-shield-check" aria-hidden="true"></i>
                        <div>After the change you stay signed in here, and every other device or browser using your account is signed out.</div></div>
                    <button class="btn btn-primary" type="submit"><span class="btn-label">Change password</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
                </form>
            </div>
        </section>
    </div>
</div>
