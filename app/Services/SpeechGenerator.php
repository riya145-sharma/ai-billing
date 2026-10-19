<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AzureConfigRepository;
use App\Storage\Collection;
use App\Storage\DuplicateException;
use App\Storage\FileStore;

final class SpeechGenerator
{
    public const VOICES = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
    public const MAX_INPUT = 4096;

    public function __construct(private FileStore $fs, private ?AzureClient $azure = null) {}

    public function config(): ?array
    {
        return (new AzureConfigRepository($this->fs))->defaultFor('speech');
    }

    public function unavailableReason(): ?string
    {
        $cfg = $this->config();
        if (!$cfg) return 'An administrator needs to add an Azure text-to-speech deployment and set it as the default for speech.';
        if (!AzureClient::isSpeechModel($cfg['model_name'])) return 'The speech deployment needs a TTS model such as gpt-4o-mini-tts or tts-1. A chat model cannot generate audio.';
        if (AzureClient::keyFor($cfg) === null) return 'The speech deployment API key is missing from the server configuration.';
        return null;
    }

    private function records(int $uid): Collection
    {
        if ($uid < 1) throw new \InvalidArgumentException('A signed-in user is required.');
        return new Collection($this->fs, 'speech/' . $uid . '.json', ['request' => fn($r) => $r['request_id'] ?? null]);
    }

    public function history(int $uid): array
    {
        return array_slice(array_reverse(array_values($this->records($uid)->all())), 0, 20);
    }

    public function audioPath(int $uid, int $id): ?string
    {
        $r = $this->records($uid)->find($id);
        if (!$r || $r['status'] !== 'success' || !preg_match('/^[a-f0-9-]{36}$/', $r['request_id'])) return null;
        $path = $this->fs->path('speech/audio/' . $uid . '/' . $r['request_id'] . '.mp3');
        return is_file($path) ? $path : null;
    }

    public function generate(int $uid, string $input, string $voice, string $requestId): array
    {
        $input = trim($input);
        if ($input === '' || mb_strlen($input) > self::MAX_INPUT) throw new GeneratorException('input', 'Enter between 1 and 4,096 characters.');
        if (!in_array($voice, self::VOICES, true)) throw new GeneratorException('voice', 'Choose a listed voice.');
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
        $allowance = new DailyAllowance($this->fs);
        $allowanceDate = $allowance->reserveManual($uid, 'speech', $allowanceKey, $cfg);
        try {
            $record = $records->insert(['request_id' => $requestId, 'input' => $input, 'voice' => $voice,
                'status' => 'pending', 'deployment_name' => $cfg['deployment_name'], 'message' => null]);
        } catch (DuplicateException) {
            throw new GeneratorException('pending', 'This request is already processing. Reload to check history.', 409);
        }
        try {
            $res = ($this->azure ?? new AzureClient($this->fs))->generateSpeech($cfg, $input, $voice, $uid);
            if ($res['outcome'] === 'success') {
                $file = $this->fs->path('speech/audio/' . $uid . '/' . $requestId . '.mp3');
                $dir = dirname($file);
                if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new \RuntimeException('Cannot create audio storage.');
                $temporary = $file . '.part';
                if (file_put_contents($temporary, $res['audio'], LOCK_EX) !== strlen($res['audio']) || !rename($temporary, $file)) {
                    throw new \RuntimeException('Cannot save generated audio.');
                }
            }
            $message = null;
            if ($res['outcome'] !== 'success') {
                $message = $res['outcome'] === 'unknown'
                    ? 'Azure may have processed this request, but no audio was received. It will not be retried automatically.'
                    : 'Speech generation failed. Ask an administrator to check the speech deployment and server usage log.';
            }
            $saved = $records->update($record['id'], ['status' => $res['outcome'], 'message' => $message, 'usage_log_id' => $res['usage_log_id']]);
            $allowance->settle($uid, 'speech', $allowanceKey, $res['outcome'] === 'success' ? 'success' : ($res['outcome'] === 'failed' ? 'failed' : 'unknown'), $allowanceDate);
            if ((int)$res['http_status'] === 429) {
                (new DeploymentThrottle($this->fs))->cooldown($cfg, $res['retry_after'] ?? 60);
            }
            return $saved;
        } catch (\Throwable $e) {
            $records->update($record['id'], ['status' => 'unknown', 'message' => 'The request was interrupted. Check history before submitting again.']);
            $allowance->settle($uid, 'speech', $allowanceKey, 'unknown', $allowanceDate);
            throw $e;
        }
    }
}
