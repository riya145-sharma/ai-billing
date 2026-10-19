<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Logger;
use App\Core\Settings;
use App\Repositories\AuditLog;
use App\Repositories\AzureConfigRepository;
use App\Repositories\ImageGenerationRepository;
use App\Storage\FileStore;

/**
 * One image generation, end to end, in the order the quota engine requires:
 *
 *   checks → QuotaService::reserve → generation record (pending) → Azure
 *   → success: save file, commit slot with the ACTUAL image count
 *   → failed:  release slot (user can retry in the same window)
 *   → unknown: mark reconciling (cron / next page load decides)
 *
 * The generation record is written before Azure is called, so the reconciler
 * can always tell "never sent" from "sent, outcome unknown".
 */
final class ImageGenerator
{
    private QuotaService $quota;
    private ImageGenerationRepository $images;
    private AzureConfigRepository $configs;
    private AzureClient $azure;
    private AuditLog $audit;

    public function __construct(private FileStore $fs, ?AzureClient $azure = null, private ?ImageStore $store = null)
    {
        $this->quota = new QuotaService($fs);
        $this->images = new ImageGenerationRepository($fs);
        $this->configs = new AzureConfigRepository($fs);
        $this->azure = $azure ?? new AzureClient($fs);
        $this->audit = new AuditLog($fs);
    }

    /** The image deployment in use, or null when none is configured. */
    public function config(): ?array
    {
        return $this->configs->defaultFor('image');
    }

    /** Is generation possible at all right now? Returns a user-facing reason, or null. */
    public function unavailableReason(): ?string
    {
        $cfg = $this->config();
        if ($cfg === null) {
            return 'No image model has been set up yet. An administrator needs to add one under Azure deployments.';
        }
        if (AzureClient::keyFor($cfg) === null) {
            return "The Azure API key ({$cfg['api_key_env']}) is missing from the server’s .env file.";
        }
        return null;
    }

    /**
     * @return array{generation: array, replayed: bool}
     * @throws QuotaException|GeneratorException
     */
    public function generate(array $user, string $prompt, string $size, string $quality, string $idempotencyKey): array
    {
        $uid = (int)$user['id'];
        $prompt = trim(str_replace("\r\n", "\n", $prompt));
        $max = max(100, Settings::int('image.max_prompt_chars', 4000));
        if ($prompt === '') {
            throw new GeneratorException('prompt_required', 'Describe the image you want.');
        }
        if (mb_strlen($prompt) > $max) {
            throw new GeneratorException('prompt_too_long', "The description is too long (maximum {$max} characters).");
        }
        if (($why = $this->unavailableReason()) !== null) {
            throw new GeneratorException('not_configured', $why, 503);
        }
        $cfg = $this->config();
        $profile = ImageModels::profile((string)$cfg['model_name']);
        if (!isset($profile['sizes'][$size])) {
            throw new GeneratorException('bad_size', 'Choose one of the listed image sizes.');
        }
        if (!isset($profile['qualities'][$quality])) {
            throw new GeneratorException('bad_quality', 'Choose one of the listed quality options.');
        }

        // Replayed submit (double click, refresh, retry after a dropped connection)?
        $existing = $this->quota->reservationByKey($uid, $idempotencyKey);
        if ($existing !== null) {
            $gen = $this->generationFor($existing);
            if ($gen !== null) {
                return ['generation' => $gen, 'replayed' => true];
            }
        }

        if (($block = (new BudgetService($this->fs))->blockReason($uid, true)) !== null) {
            throw new GeneratorException('budget_exceeded', $block, 402);
        }

        $limiter = new RateLimiter($this->fs);
        if (!$limiter->hit('ai:user:' . $uid, max(1, Settings::int('security.api_rate_per_minute', 30)), 60)) {
            throw new GeneratorException('rate_limited', 'Too many requests. Please wait a minute and try again.', 429);
        }

        $reserved = $this->quota->reserve($uid, $idempotencyKey);   // QuotaException → caller shows the spec message
        $rsv = $reserved['reservation'];
        if ($reserved['replayed']) {
            $gen = $this->generationFor($rsv);
            if ($gen !== null) {
                return ['generation' => $gen, 'replayed' => true];
            }
            throw new GeneratorException('in_progress', 'This image is still being generated. Please wait.', 409);
        }

        try {
            $gen = $this->images->create([
                'user_id'         => $uid,
                'reservation_id'  => $rsv['id'],
                'slot_code'       => $rsv['slot_code'],
                'azure_config_id' => $cfg['id'],
                'model_name'      => $cfg['model_name'],
                'prompt'          => $prompt,
                'size'            => $size,
                'quality'         => $quality,
            ]);
            $this->quota->attachGeneration($rsv['id'], $gen['id']);
        } catch (\Throwable $e) {
            // Nothing was sent to Azure: give the slot straight back.
            $this->quota->release($rsv['id'], 'local error before sending');
            throw $e;
        }

        try {
            $r = $this->azure->generateImage($cfg, $prompt, $size, $quality, $uid);
        } catch (\Throwable $e) {
            Logger::error('image generation crashed: ' . $e->getMessage());
            $this->images->update($gen['id'], ['status' => 'unknown', 'error_message' => 'Internal error while calling Azure', 'completed_at' => Clock::nowIso()]);
            $this->quota->markReconciling($rsv['id'], 'exception: ' . $e::class);
            throw new GeneratorException('unknown', self::unknownMessage(), 502);
        }

        $base = ['usage_log_id' => $r['usage_log_id'], 'completed_at' => Clock::nowIso()];

        if ($r['outcome'] === 'success') {
            $count = count($r['images']);
            $saved = [];
            $saveError = null;
            try {
                $saved = $this->store()->save($gen['id'], ImageStore::decodeBase64($r['images'][0]));
            } catch (\Throwable $e) {
                $saveError = $e->getMessage();
                Logger::error("image {$gen['id']} could not be saved: {$saveError}");
            }
            // Azure produced (and billed) the image, so it counts even if saving failed.
            $gen = $this->images->update($gen['id'], $base + $saved + [
                'status'         => 'succeeded',
                'image_count'    => $count,
                'revised_prompt' => $r['revised_prompt'],
                'cost_usd'       => $r['cost']['cost_usd'],
                'usd_inr_rate'   => $r['cost']['usd_inr_rate'],
                'cost_inr'       => $r['cost']['cost_inr'],
                'error_message'  => $saveError !== null ? 'Generated, but the file could not be saved: ' . $saveError : null,
            ]);
            $this->quota->commit($rsv['id'], $count, $gen['id']);
            $this->audit->record('image.generated', $uid, 'image', $gen['id'], ['slot' => $rsv['slot_code'], 'images' => $count]);
            try {
                (new BudgetService($this->fs))->afterSpend($uid, true);
            } catch (\Throwable $e) {
                Logger::error('budget alert check failed: ' . $e->getMessage());
            }
            if ($saveError !== null) {
                throw new GeneratorException('save_failed', 'The image was generated but could not be saved on the server. This slot has been used; please tell an administrator.', 500);
            }
            return ['generation' => $gen, 'replayed' => false];
        }

        if ($r['outcome'] === 'failed') {
            $this->images->update($gen['id'], $base + ['status' => 'failed', 'error_message' => $r['error_message']]);
            $this->quota->release($rsv['id'], (string)$r['error_code']);
            $this->audit->record('image.failed', $uid, 'image', $gen['id'], ['code' => $r['error_code'], 'http' => $r['http_status']]);
            throw new GeneratorException('azure_failed', self::failureMessage($r), $r['http_status'] === 429 ? 429 : 502, [
                'detail' => $r['error_message'],
            ]);
        }

        $this->images->update($gen['id'], $base + ['status' => 'unknown', 'error_message' => $r['error_message']]);
        $this->quota->markReconciling($rsv['id'], (string)$r['error_code']);
        $this->audit->record('image.unknown', $uid, 'image', $gen['id'], ['code' => $r['error_code']]);
        throw new GeneratorException('unknown', self::unknownMessage(), 504);
    }

    /** The generation record belonging to a reservation, if any. */
    public function generationFor(array $reservation): ?array
    {
        if (!empty($reservation['generation_id'])) {
            return $this->images->get($reservation['generation_id']);
        }
        foreach ($this->images->forDay(\App\Core\Ids::dateOf($reservation['id'], 'rsv'), (int)$reservation['user_id']) as $g) {
            if (($g['reservation_id'] ?? null) === $reservation['id']) {
                return $g;
            }
        }
        return null;
    }

    private function store(): ImageStore
    {
        return $this->store ??= new ImageStore($this->fs);
    }

    private static function failureMessage(array $r): string
    {
        $code = strtolower((string)$r['error_code']);
        $status = (int)$r['http_status'];
        return match (true) {
            str_contains($code, 'content') || str_contains($code, 'moderation') || str_contains($code, 'safety') || str_contains($code, 'responsibleai')
                => 'Azure’s content safety filter blocked this description. Please rephrase it and try again — your slot has not been used.',
            $status === 429
                => 'Azure is busy right now (rate limit). Please try again in a minute — your slot has not been used.',
            $status === 401 || $status === 403 || $code === 'missing_key'
                => 'Azure rejected the API key. An administrator needs to check the Azure configuration. Your slot has not been used.',
            $status === 404
                => 'The image deployment was not found in Azure. An administrator needs to check the deployment name. Your slot has not been used.',
            $status === 400
                => 'Azure could not process this request. Try a different description, size or quality — your slot has not been used.',
            default
                => 'Image generation failed. Please try again — your slot has not been used.',
        };
    }

    private static function unknownMessage(): string
    {
        return 'We lost contact with Azure before the image came back, so we can’t tell yet whether it was created. '
            . 'New generations are paused for a few minutes while this is checked; if no image was made, the slot is given back automatically.';
    }
}
