<?php
use App\Core\Session;
use App\Core\View;
/** @var array $deployments @var array $keys @var ?array $editing @var array $defaults */
$src = $editing ?? $defaults;
$hasOld = Session::old('deployment_name') !== '' || Session::old('endpoint_base') !== '';
$val = fn(string $f) => $hasOld ? Session::old($f) : (string)($src[$f] ?? '');
$isDefault = $hasOld ? Session::old('is_default') !== '' : !empty($src['is_default']);
$action = $editing ? url('/admin/azure/' . (int)$editing['id']) : url('/admin/azure');
?>
<div class="row g-3">
    <div class="col-12 col-xxl-7">
        <?php if (!empty($discovered)): ?>
        <section class="card mb-3 border-primary-subtle" aria-labelledby="disc-title">
            <div class="card-header">
                <h2 class="card-title" id="disc-title"><i class="bi bi-search" aria-hidden="true"></i> Found in Azure</h2>
                <span class="small text-body-secondary">checked <?= e(View::relative(gmdate('Y-m-d\TH:i:s\Z', (int)$discovered['at']))) ?></span>
            </div>
            <?php foreach ($discovered['errors'] as $err): ?>
                <div class="card-body pb-0"><div class="notice notice-danger"><i class="bi bi-x-octagon" aria-hidden="true"></i><span><?= e($err) ?></span></div></div>
            <?php endforeach; ?>
            <?php if (!$discovered['items'] && !$discovered['errors']): ?>
                <div class="card-body">
                    <div class="notice notice-warning">
                        <i class="bi bi-chat-dots" aria-hidden="true"></i>
                        <span>No text or speech deployments were found. Deploy a model in Azure Foundry, then select Find deployments again.</span>
                    </div>
                </div>
            <?php endif; ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($discovered['items'] as $d): ?>
                    <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <span class="fw-semibold"><?= e($d['deployment_name']) ?></span>
                            <span class="chip ms-1"><?= $d['purpose'] === 'speech' ? 'Speech' : 'Text' ?></span>
                            <div class="small text-body-secondary">Model <code><?= e($d['model_name']) ?></code> · <?= e($d['status']) ?></div>
                        </div>
                        <?php if ($d['configured']): ?>
                            <span class="small text-success-emphasis"><i class="bi bi-check-circle" aria-hidden="true"></i> Already added</span>
                        <?php else: ?>
                            <form method="post" action="<?= url('/admin/azure') ?>" data-once>
                                <?= csrf_field() ?>
                                <?php foreach (['purpose', 'endpoint_base', 'api_key_env', 'deployment_name', 'model_name'] as $f): ?>
                                    <input type="hidden" name="<?= $f ?>" value="<?= e($d[$f]) ?>">
                                <?php endforeach; ?>
                                <input type="hidden" name="name" value="<?= e($d['deployment_name']) ?>">
                                <input type="hidden" name="api_style" value="v1">
                                <button class="btn btn-sm btn-primary"><span class="btn-label">Add deployment</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <section class="card" aria-labelledby="dep-title">
            <div class="card-header">
                <h2 class="card-title" id="dep-title"><i class="bi bi-cloud" aria-hidden="true"></i> Deployments</h2>
                <form method="post" action="<?= url('/admin/azure/discover') ?>" data-once><?= csrf_field() ?>
                    <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1" aria-hidden="true"></i><span class="btn-label">Find deployments in Azure</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
                </form>
            </div>
            <?php if ($deployments): ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($deployments as $d): $id = (int)$d['id']; ?>
                    <li class="list-group-item py-3<?= empty($d['is_active']) ? ' opacity-50' : '' ?>">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div>
                                <div class="fw-semibold">
                                    <?= e($d['deployment_name']) ?>
                                    <span class="chip ms-1"><?= $d['purpose'] === 'speech' ? 'Speech' : 'Text' ?></span>
                                    <?php if (!empty($d['is_default'])): ?><span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">Default</span><?php endif; ?>
                                    <?php if (empty($d['is_active'])): ?><span class="badge text-bg-secondary">Disabled</span><?php endif; ?>
                                </div>
                                <div class="small text-body-secondary">
                                    Model <code><?= e($d['model_name']) ?></code><?= $d['model_version'] ? ' · ' . e($d['model_version']) : '' ?>
                                    <?= $d['deployment_type'] ? ' · ' . e($d['deployment_type']) : '' ?>
                                    · API <?= e($d['api_style']) ?>
                                </div>
                                <div class="small text-body-secondary text-break"><?= e($d['endpoint_base']) ?></div>
                                <div class="small mt-1 d-flex flex-wrap gap-3">
                                    <?php if ($keys[$id]): ?>
                                        <span class="text-success-emphasis"><i class="bi bi-key" aria-hidden="true"></i> Key <code><?= e($d['api_key_env']) ?></code> set</span>
                                    <?php else: ?>
                                        <span class="text-danger-emphasis"><i class="bi bi-key" aria-hidden="true"></i> <code><?= e($d['api_key_env']) ?></code> missing from .env</span>
                                    <?php endif; ?>
                                    <?php if (($d['verification_status'] ?? '') === 'ok'): ?>
                                        <span class="text-success-emphasis"><i class="bi bi-check-circle" aria-hidden="true"></i> Connected <?= e(View::relative($d['last_verified_at'])) ?></span>
                                    <?php elseif (($d['verification_status'] ?? '') === 'failed'): ?>
                                        <span class="text-danger-emphasis" title="<?= e($d['last_verify_error']) ?>"><i class="bi bi-x-circle" aria-hidden="true"></i> Last test failed</span>
                                    <?php else: ?>
                                        <span class="text-body-secondary"><i class="bi bi-question-circle" aria-hidden="true"></i> Not tested</span>
                                    <?php endif; ?>
                                    <?php if (!empty($d['retirement_date'])): ?>
                                        <span class="text-warning-emphasis"><i class="bi bi-calendar-x" aria-hidden="true"></i> Retires <?= e($d['retirement_date']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-1 align-items-start">
                                <form method="post" action="<?= url("/admin/azure/{$id}/verify") ?>" data-once><?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-primary"><span class="btn-label">Test connection</span><span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span></button>
                                </form>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= url('/admin/azure', ['edit' => $id]) ?>">Edit</a>
                                <?php if (empty($d['is_default']) && !empty($d['is_active'])): ?>
                                    <form method="post" action="<?= url("/admin/azure/{$id}/default") ?>"><?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-secondary">Make default</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= url("/admin/azure/{$id}/toggle") ?>"><?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-<?= empty($d['is_active']) ? 'success' : 'danger' ?>"><?= empty($d['is_active']) ? 'Enable' : 'Disable' ?></button>
                                </form>
                            </div>
                        </div>
                        <?php if (($d['verification_status'] ?? '') === 'failed' && $d['last_verify_error']): ?>
                            <div class="small text-danger-emphasis mt-2"><?= e($d['last_verify_error']) ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
                <div class="card-body"><div class="empty-state compact"><i class="bi bi-cloud" aria-hidden="true"></i><p>No deployments yet.</p></div></div>
            <?php endif; ?>
        </section>

        <section class="card mt-3">
            <div class="card-body small text-body-secondary">
                <h3 class="h6 text-body"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Where to find these values</h3>
                <ol class="mb-0 ps-3">
                    <li>Azure Foundry → your project → <strong>Deployments</strong>. Copy the deployment name of your text model, for example <code>gpt-4.1-mini</code>.</li>
                    <li>Endpoint for the v1 API: <code>https://&lt;resource&gt;.openai.azure.com/openai/v1</code>.</li>
                    <li>Put the API key in the server's <code>.env</code> using <code>AZURE_OPENAI_API_KEY</code>, and enter only the variable name below.</li>
                    <li>The content generator uses the active default chat deployment.</li>
                    <li>For text-to-speech, deploy a TTS model such as <code>gpt-4o-mini-tts</code> or <code>tts-1</code> in a supported region. Add it with purpose <strong>Text to speech</strong> and make it the default for speech. Use its resource's endpoint and key.</li>
                </ol>
            </div>
        </section>
    </div>

    <div class="col-12 col-xxl-5">
        <section class="card" aria-labelledby="form-title">
            <div class="card-header">
                <h2 class="card-title" id="form-title"><i class="bi bi-<?= $editing ? 'pencil' : 'plus-circle' ?>" aria-hidden="true"></i> <?= $editing ? 'Edit ' . e($editing['deployment_name']) : 'Add a deployment' ?></h2>
                <?php if ($editing): ?><a href="<?= url('/admin/azure') ?>" class="small">Cancel</a><?php endif; ?>
            </div>
            <div class="card-body">
                <form method="post" action="<?= $action ?>" data-once>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="purpose">Purpose</label>
                            <select class="form-select" id="purpose" name="purpose">
                                <option value="chat" <?= $val('purpose') === 'chat' ? 'selected' : '' ?>>Chat / text</option>
                                <option value="speech" <?= $val('purpose') === 'speech' ? 'selected' : '' ?>>Text to speech</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="name">Display name</label>
                            <input class="form-control" id="name" name="name" value="<?= e($val('name')) ?>" placeholder="gpt-4.1-mini (GlobalStandard)" required maxlength="100">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="deployment_name">Deployment name</label>
                            <input class="form-control" id="deployment_name" name="deployment_name" value="<?= e($val('deployment_name')) ?>" placeholder="gpt-4.1-mini" required maxlength="100" pattern="[A-Za-z0-9._\-]+">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="model_name">Model</label>
                            <input class="form-control" id="model_name" name="model_name" value="<?= e($val('model_name')) ?>" list="modelList" required maxlength="100">
                            <datalist id="modelList"><option value="gpt-4.1-mini"><option value="gpt-4o-mini-tts"><option value="tts-1"><option value="tts-1-hd"><option value="tts"><option value="tts-hd"></datalist>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="endpoint_base">Endpoint</label>
                            <input class="form-control" id="endpoint_base" name="endpoint_base" value="<?= e($val('endpoint_base')) ?>" placeholder="https://your-resource.openai.azure.com/openai/v1" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="api_key_env">API key variable in .env</label>
                            <input class="form-control font-monospace" id="api_key_env" name="api_key_env" value="<?= e($val('api_key_env')) ?>" required pattern="[A-Z][A-Z0-9_]{2,63}">
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label" for="api_style">API style</label>
                            <select class="form-select" id="api_style" name="api_style">
                                <option value="v1" <?= $val('api_style') !== 'legacy' ? 'selected' : '' ?>>v1</option>
                                <option value="legacy" <?= $val('api_style') === 'legacy' ? 'selected' : '' ?>>Legacy</option>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label" for="api_version">api-version</label>
                            <input class="form-control" id="api_version" name="api_version" value="<?= e($val('api_version')) ?>" placeholder="legacy only">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label" for="model_version">Model version</label>
                            <input class="form-control" id="model_version" name="model_version" value="<?= e($val('model_version')) ?>" placeholder="2025-04-15">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label" for="deployment_type">Deployment type</label>
                            <input class="form-control" id="deployment_type" name="deployment_type" value="<?= e($val('deployment_type')) ?>" placeholder="GlobalStandard">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label" for="retirement_date">Retirement date</label>
                            <input class="form-control" type="date" id="retirement_date" name="retirement_date" value="<?= e($val('retirement_date')) ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label" for="tpm_limit">TPM limit</label>
                            <input class="form-control" type="number" min="1" id="tpm_limit" name="tpm_limit" value="<?= e($val('tpm_limit')) ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label" for="rpm_limit">RPM / IPM limit</label>
                            <input class="form-control" type="number" min="1" id="rpm_limit" name="rpm_limit" value="<?= e($val('rpm_limit')) ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label" for="lifecycle_status">Lifecycle</label>
                            <input class="form-control" id="lifecycle_status" name="lifecycle_status" value="<?= e($val('lifecycle_status')) ?>" placeholder="GA / Preview / Legacy">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1" <?= $isDefault ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_default">Use as the default for this purpose</label>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-4">
                        <span class="btn-label"><?= $editing ? 'Save changes' : 'Add deployment' ?></span>
                        <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
                    </button>
                </form>
            </div>
        </section>
    </div>
</div>
