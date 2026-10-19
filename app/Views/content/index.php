<?php use App\Services\ContentGenerator; ?>
<div class="row g-3">
    <div class="col-lg-6">
        <section class="card">
            <div class="card-header"><h2 class="card-title">Create content</h2>
                <?php if ($config): ?><span class="chip"><?= e($config['deployment_name']) ?></span><?php endif; ?>
            </div>
            <div class="card-body">
                <p class="text-secondary">Turn a brief into captions, product descriptions, blog posts or emails.</p>
                <?php if ($blocked): ?><div class="alert alert-warning"><?= e($blocked) ?>
                    <?php if (\App\Core\Auth::isAdmin()): ?><a href="<?= url('/admin/azure') ?>">Azure deployments</a><?php endif; ?>
                </div><?php endif; ?>
                <form id="contentForm" action="<?= url('/content/generate') ?>" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="request_id" value="<?= e($requestId) ?>">
                    <fieldset <?= $blocked ? 'disabled' : '' ?>>
                        <div class="mb-3"><label for="contentPrompt" class="form-label">What would you like to write?</label>
                            <textarea id="contentPrompt" name="prompt" class="form-control" rows="7" maxlength="6000" required placeholder="Describe your topic, audience, key points, language and preferred length."></textarea>
                            <div class="form-text">Up to 6,000 characters. Include facts you want the content to use.</div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-6"><label for="contentFormat" class="form-label">Format</label><select id="contentFormat" name="format" class="form-select">
                                <?php foreach (ContentGenerator::FORMATS as $value): ?><option><?= e($value) ?></option><?php endforeach; ?>
                            </select></div>
                            <div class="col-sm-6"><label for="contentTone" class="form-label">Tone</label><select id="contentTone" name="tone" class="form-select">
                                <?php foreach (ContentGenerator::TONES as $value): ?><option><?= e($value) ?></option><?php endforeach; ?>
                            </select></div>
                        </div>
                        <button class="btn btn-primary" id="contentSubmit" type="submit">Generate content</button>
                    </fieldset>
                </form>
                <p id="contentStatus" class="mt-3" role="status" aria-live="polite"></p>
            </div>
        </section>
    </div>
    <div class="col-lg-6"><section class="card">
        <div class="card-header"><h2 class="card-title">Generated content</h2></div>
        <div class="card-body">
            <label class="visually-hidden" for="contentOutput">Generated content</label>
            <textarea id="contentOutput" class="form-control mb-3" rows="16" readonly placeholder="Your content will appear here."></textarea>
            <button type="button" id="contentCopy" class="btn btn-outline-secondary" disabled>Copy text</button>
            <button type="button" id="contentDownload" class="btn btn-outline-secondary" disabled>Download .txt</button>
            <p id="contentUsage" class="form-text mt-2"></p>
        </div>
    </section></div>
    <div class="col-12"><section class="card">
        <div class="card-header"><h2 class="card-title">Your recent content</h2></div>
        <div class="card-body">
            <p class="text-secondary">Your latest 20 requests. Reload after generating to update this list.</p>
            <?php foreach ($history as $item): ?>
                <details class="border rounded p-3 mb-2"><summary><?= e($item['format']) ?> · <?= e($item['created_at']) ?> · <?= e($item['status']) ?></summary>
                    <p class="mt-3"><?= e($item['prompt']) ?></p>
                    <?php if ($item['text'] !== ''): ?><textarea class="form-control" rows="8" readonly aria-label="Saved content"><?= e($item['text']) ?></textarea><?php endif; ?>
                    <?php if (!empty($item['message'])): ?><p><?= e($item['message']) ?></p><?php endif; ?>
                </details>
            <?php endforeach; ?>
            <?php if (!$history): ?><p>No content generated yet.</p><?php endif; ?>
        </div>
    </section></div>
</div>
