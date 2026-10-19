<?php use App\Services\SpeechGenerator; ?>
<div class="row g-3">
    <div class="col-lg-6"><section class="card">
        <div class="card-header"><h2 class="card-title"><i class="bi bi-soundwave" aria-hidden="true"></i> Create speech</h2>
            <?php if ($config): ?><span class="chip"><?= e($config['deployment_name']) ?></span><?php endif; ?>
        </div>
        <div class="card-body">
            <p class="text-secondary">Paste your text, choose a voice and generate an MP3.</p>
            <?php if ($blocked): ?><div class="alert alert-warning"><?= e($blocked) ?>
                <?php if (\App\Core\Auth::isAdmin()): ?><a href="<?= url('/admin/azure') ?>">Set up Azure deployments</a><?php endif; ?>
            </div><?php endif; ?>
            <form id="speechForm" action="<?= url('/speech/generate') ?>" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="request_id" value="<?= e($requestId) ?>">
                <fieldset <?= $blocked ? 'disabled' : '' ?>>
                    <div class="mb-3"><label for="speechInput" class="form-label">Text to read aloud</label>
                        <textarea id="speechInput" name="input" class="form-control" rows="9" maxlength="4096" required placeholder="Enter your narration, announcement or generated content here."></textarea>
                        <div class="form-text" id="speechCounter">0 / 4,096 characters</div>
                    </div>
                    <div class="mb-3"><label for="speechVoice" class="form-label">Voice</label>
                        <select id="speechVoice" name="voice" class="form-select">
                            <?php foreach (SpeechGenerator::VOICES as $voice): ?><option value="<?= e($voice) ?>"><?= e(ucfirst($voice)) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <button id="speechSubmit" type="submit" class="btn btn-primary">Generate speech</button>
                </fieldset>
            </form>
            <p id="speechStatus" class="mt-3" role="status" aria-live="polite"></p>
        </div>
    </section></div>
    <div class="col-lg-6"><section class="card">
        <div class="card-header"><h2 class="card-title">Your audio</h2></div>
        <div class="card-body">
            <p>This voice is AI-generated.</p>
            <p id="speechEmpty" class="text-secondary">Your audio will appear here after generation.</p>
            <div id="speechResult" hidden>
                <audio id="speechPlayer" class="w-100 mb-3" controls preload="none" aria-label="Generated speech"></audio>
                <a id="speechDownload" class="btn btn-outline-primary" download>Download MP3</a>
            </div>
        </div>
    </section></div>
    <div class="col-12"><section class="card">
        <div class="card-header"><h2 class="card-title">Your recent speech</h2></div>
        <div class="card-body">
            <p class="text-secondary">Your latest 20 requests. Reload to update this list.</p>
            <?php foreach ($history as $item): ?>
                <details class="border rounded p-3 mb-2"><summary><?= e(ucfirst($item['voice'])) ?> · <?= e(\App\Core\View::dateTime($item['created_at'])) ?> · <?= e($item['status']) ?></summary>
                    <p class="mt-3 text-break"><?= e($item['input']) ?></p>
                    <?php if ($item['status'] === 'success'): ?>
                        <audio class="w-100 mb-2" controls preload="none" src="<?= url('/speech/audio/' . (int)$item['id']) ?>" aria-label="Saved AI-generated speech"></audio>
                        <a href="<?= url('/speech/audio/' . (int)$item['id'], ['download' => '1']) ?>" class="btn btn-sm btn-outline-primary" download>Download MP3</a>
                    <?php elseif ($item['message']): ?><p><?= e($item['message']) ?></p><?php endif; ?>
                </details>
            <?php endforeach; ?>
            <?php if (!$history): ?><p>No speech generated yet.</p><?php endif; ?>
        </div>
    </section></div>
</div>
