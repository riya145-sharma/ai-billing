<?php
use App\Core\Session;
?>
<div class="card guest-card">
    <div class="card-body">
        <h1 class="h4 mb-1">Sign in</h1>
        <p class="text-body-secondary mb-4">Use your work email and password.</p>

        <form method="post" action="<?= url('/login') ?>" novalidate data-once>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="email" class="form-label">Email address</label>
                <div class="input-group">
                    <span class="input-group-text" aria-hidden="true"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control" id="email" name="email" maxlength="190"
                           value="<?= e(Session::old('email')) ?>" autocomplete="username" required
                           <?= Session::old('email') === '' ? 'autofocus' : '' ?>>
                </div>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text" aria-hidden="true"><i class="bi bi-key"></i></span>
                    <input type="password" class="form-control" id="password" name="password" maxlength="4096"
                           autocomplete="current-password" required <?= Session::old('email') !== '' ? 'autofocus' : '' ?>>
                    <button class="btn btn-outline-secondary" type="button" data-toggle-password="#password" aria-label="Show password">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="form-text caps-warning d-none" data-caps-warning><i class="bi bi-capslock-fill" aria-hidden="true"></i> Caps Lock is on</div>
            </div>
            <button type="submit" class="btn btn-primary w-100 btn-lg">
                <span class="btn-label">Sign in</span>
                <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            </button>
        </form>
    </div>
    <div class="card-footer small text-body-secondary">
        Forgot your password? Ask an administrator to reset it. Once signed in, you can change it under My profile.
    </div>
</div>
