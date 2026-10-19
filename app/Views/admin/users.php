<?php
use App\Controllers\Admin\UsersController;
use App\Core\Auth;
use App\Core\Session;
use App\Core\View;
/** @var array $users @var ?array $editing @var int $minPassword */
?>
<div class="row g-3">
    <div class="col-12 col-xxl-8">
        <section class="card" aria-labelledby="users-title">
            <div class="card-header">
                <h2 class="card-title" id="users-title"><i class="bi bi-people" aria-hidden="true"></i> All users</h2>
                <span class="small text-body-secondary"><?= count($users) ?> total</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th scope="col">User</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Last sign-in</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($users as $u): $locked = UsersController::isLocked($u); ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($u['name']) ?><?= (int)$u['id'] === Auth::id() ? ' <span class="small text-body-secondary">(you)</span>' : '' ?></div>
                                <div class="small text-body-secondary"><?= e($u['email']) ?></div>
                            </td>
                            <td><span class="badge <?= $u['role'] === 'admin' ? 'bg-primary-subtle text-primary-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'User' ?></span></td>
                            <td>
                                <?php if ($u['status'] !== 'active'): ?>
                                    <span class="text-body-secondary"><i class="bi bi-slash-circle" aria-hidden="true"></i> Disabled</span>
                                <?php elseif ($locked): ?>
                                    <span class="text-danger-emphasis"><i class="bi bi-lock" aria-hidden="true"></i> Locked</span>
                                <?php else: ?>
                                    <span class="text-success-emphasis"><i class="bi bi-check-circle" aria-hidden="true"></i> Active</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= e(View::dateTime($u['last_login_at'])) ?></td>
                            <td class="text-end text-nowrap">
                                <?php if ($locked): ?>
                                    <form method="post" action="<?= url('/admin/users/' . (int)$u['id'] . '/unlock') ?>" class="d-inline"><?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger">Unlock</button>
                                    </form>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= url('/admin/users', ['edit' => (int)$u['id']]) ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-12 col-xxl-4">
        <?php if ($editing): $eid = (int)$editing['id']; ?>
            <section class="card mb-3" aria-labelledby="edit-title">
                <div class="card-header">
                    <h2 class="card-title" id="edit-title"><i class="bi bi-pencil" aria-hidden="true"></i> Edit <?= e($editing['name']) ?></h2>
                    <a class="small" href="<?= url('/admin/users') ?>">Close</a>
                </div>
                <div class="card-body">
                    <form method="post" action="<?= url("/admin/users/{$eid}") ?>" data-once>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="e_name">Name</label>
                            <input class="form-control" id="e_name" name="name" value="<?= e($editing['name']) ?>" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input class="form-control" value="<?= e($editing['email']) ?>" disabled>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label" for="e_role">Role</label>
                                <select class="form-select" id="e_role" name="role">
                                    <option value="user" <?= $editing['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                    <option value="admin" <?= $editing['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="e_status">Status</label>
                                <select class="form-select" id="e_status" name="status">
                                    <option value="active" <?= $editing['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="disabled" <?= $editing['status'] === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                                </select>
                            </div>
                        </div>
                        <button class="btn btn-primary"><span class="btn-label">Save</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
                    </form>
                    <hr>
                    <form method="post" action="<?= url("/admin/users/{$eid}/password") ?>" data-once>
                        <?= csrf_field() ?>
                        <label class="form-label fw-semibold" for="e_pw">Reset password</label>
                        <input class="form-control mb-2" type="password" id="e_pw" name="password" minlength="<?= (int)$minPassword ?>" autocomplete="new-password" placeholder="Leave empty to generate one">
                        <div class="form-text mb-2">Signs the user out on every device.</div>
                        <button class="btn btn-outline-danger"><span class="btn-label">Reset password</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <section class="card" aria-labelledby="add-title">
            <div class="card-header"><h2 class="card-title" id="add-title"><i class="bi bi-person-plus" aria-hidden="true"></i> Add a user</h2></div>
            <div class="card-body">
                <form method="post" action="<?= url('/admin/users') ?>" data-once>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="n_name">Name</label>
                        <input class="form-control" id="n_name" name="name" value="<?= e(Session::old('name')) ?>" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="n_email">Email</label>
                        <input class="form-control" type="email" id="n_email" name="email" value="<?= e(Session::old('email')) ?>" required maxlength="190">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="n_role">Role</label>
                        <select class="form-select" id="n_role" name="role">
                            <option value="user">User — content and speech generators</option>
                            <option value="admin" <?= Session::old('role') === 'admin' ? 'selected' : '' ?>>Admin — everything</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="n_pw">Password</label>
                        <input class="form-control" type="password" id="n_pw" name="password" minlength="<?= (int)$minPassword ?>" autocomplete="new-password" placeholder="Leave empty to generate one">
                        <div class="form-text">At least <?= (int)$minPassword ?> characters. A generated password is shown once after saving.</div>
                    </div>
                    <button class="btn btn-primary"><span class="btn-label">Add user</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
                </form>
            </div>
        </section>
    </div>
</div>
