<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AzureConfigRepository;
use App\Storage\Collection;
use App\Storage\DuplicateException;
use App\Storage\FileStore;

final class ContentGenerator
{
    public const FORMATS = ['General content', 'Social media caption', 'Product description', 'Blog post', 'Email'];
    public const TONES = ['Professional', 'Friendly', 'Creative', 'Persuasive'];

    public function __construct(private FileStore $fs, private ?AzureClient $azure = null) {}

    public function config(): ?array
    {
        return (new AzureConfigRepository($this->fs))->defaultFor('chat');
    }

    public function unavailableReason(): ?string
    {
        $cfg = $this->config();
        if (!$cfg) return 'An administrator needs to configure a default chat deployment under Azure deployments.';
        if (AzureClient::isImageModel($cfg['model_name'])) return 'The default chat deployment must use a text model.';
        if (AzureClient::isSpeechModel($cfg['model_name'])) return 'The default chat deployment must use a text model.';
        if (AzureClient::keyFor($cfg) === null) return 'The Azure API key is missing from the server configuration.';
        return null;
    }

    private function records(int $uid): Collection
    {
        return new Collection($this->fs, 'content/' . $uid . '.json', ['request' => fn($r) => $r['request_id'] ?? null]);
    }

    public function history(int $uid): array
    {
        return array_slice(array_reverse(array_values($this->records($uid)->all())), 0, 20);
    }

    public function generate(int $uid, string $prompt, string $format, string $tone, string $requestId): array
    {
        $prompt = trim($prompt);
        if ($prompt === '' || mb_strlen($prompt) > 6000) throw new GeneratorException('prompt', 'Enter a brief between 1 and 6,000 characters.');
        if (!in_array($format, self::FORMATS, true) || !in_array($tone, self::TONES, true)) throw new GeneratorException('options', 'Choose a listed format and tone.');
        if (!preg_match('/^[a-f0-9-]{36}$/', $requestId)) throw new GeneratorException('request', 'Reload the page and try again.');
        $records = $this->records($uid);
        foreach ($records->all() as $r) {
            if ($r['request_id'] === $requestId) return $r;
        }
        if ($why = $this->unavailableReason()) throw new GeneratorException('configuration', $why, 503);
        if (!(new RateLimiter($this->fs))->hit('ai:user:' . $uid, max(1, \App\Core\Settings::int('security.api_rate_per_minute', 30)), 60)) {
            throw new GeneratorException('rate', 'Too many requests. Please wait a minute.', 429);
        }
        $cfg = $this->config();
        $allowanceKey = 'manual:' . $requestId;
        $allowanceDate = (new DailyAllowance($this->fs))->reserveManual($uid, 'content', $allowanceKey, $cfg);
        try {
            $record = $records->insert(['request_id' => $requestId, 'prompt' => $prompt, 'format' => $format,
                'tone' => $tone, 'status' => 'pending', 'text' => '', 'deployment_name' => $cfg['deployment_name']]);
        } catch (DuplicateException) {
            throw new GeneratorException('pending', 'This request is already being processed. Reload to check history.', 409);
        }
        $allowance = new DailyAllowance($this->fs);
        try {
            $result = ($this->azure ?? new AzureClient($this->fs))->generateContent($cfg, [
                ['role' => 'system', 'content' => "You are a content writer. Write {$format} in a {$tone} tone. Follow the user brief and return only the requested content."],
                ['role' => 'user', 'content' => $prompt],
            ], $uid);
            $record = $records->update($record['id'], ['status' => $result['outcome'], 'text' => $result['text'],
                'truncated' => $result['truncated'], 'usage' => $result['usage'], 'cost' => $result['cost'],
                'usage_log_id' => $result['usage_log_id'],
                'message' => $result['outcome'] === 'success' ? null : ($result['outcome'] === 'unknown'
                    ? 'The request outcome is unknown. Azure may have processed it. Check history before submitting again.'
                    : 'Azure could not generate content. Ask an administrator to check the usage log.')]);
            $allowance->settle($uid, 'content', $allowanceKey, $result['outcome'] === 'success' ? 'success' : ($result['outcome'] === 'failed' ? 'failed' : 'unknown'), $allowanceDate);
        } catch (\Throwable $e) {
            $records->update($record['id'], ['status' => 'unknown', 'message' => 'The request was interrupted. Its outcome is unknown.']);
            $allowance->settle($uid, 'content', $allowanceKey, 'unknown', $allowanceDate);
            throw $e;
        }
        return $record;
    }
}
