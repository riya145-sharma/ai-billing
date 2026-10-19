<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Env;
use App\Core\Logger;
use App\Core\Settings;
use App\Repositories\AzureConfigRepository;
use App\Repositories\UsageLogRepository;
use App\Storage\FileStore;

/**
 * Calls to Azure OpenAI. Every call is logged in the usage log BEFORE it is
 * sent and completed afterwards, so a crash mid-request still leaves a trace.
 *
 * Safety
 *  - keys come only from the environment (.env), never from storage
 *  - keys are only sent to Azure hosts (AzureConfigRepository::hostAllowed),
 *    over https, with certificate checks on and redirects off
 *
 * Outcomes ('outcome' in the result)
 *  - success : HTTP 2xx with a parseable body
 *  - failed  : Azure definitely did not do the work (connect failure, 4xx, 5xx)
 *  - unknown : the request was sent but no answer came back (timeout, dropped
 *              connection) — Azure may have produced (and billed) the result
 *
 * The HTTP transport is injectable so tests never touch the network.
 */
final class AzureClient
{
    /** @var callable(string, string, array, ?string, int, int): array */
    private $transport;
    private UsageLogRepository $usage;

    public function __construct(private FileStore $fs, ?callable $transport = null)
    {
        $this->transport = $transport ?? [self::class, 'curlTransport'];
        $this->usage = new UsageLogRepository($fs);
    }

    public static function keyFor(array $config): ?string
    {
        $v = Env::get((string)($config['api_key_env'] ?? 'AZURE_OPENAI_API_KEY'));
        return ($v === null || trim($v) === '' || str_contains($v, '***')) ? null : trim($v);
    }

    /** URL for an operation, for both API styles. */
    public static function url(array $config, string $operation): string
    {
        $base = rtrim((string)$config['endpoint_base'], '/');
        if (($config['api_style'] ?? 'v1') === 'v1') {
            return $base . '/' . ltrim($operation, '/');
        }
        $base = preg_replace('#/openai(/v1)?$#', '', $base);
        return $base . '/openai/deployments/' . rawurlencode((string)$config['deployment_name']) . '/' . ltrim($operation, '/')
            . '?api-version=' . rawurlencode((string)$config['api_version']);
    }

    /**
     * Generate one image. Returns:
     *   outcome, usage_log_id, http_status, images (list of base64 strings), revised_prompt,
     *   usage (token counts or nulls), error_code, error_message, latency_ms
     */
    public function generateImage(array $config, string $prompt, string $size, string $quality, ?int $userId): array
    {
        $url = self::url($config, 'images/generations');
        $log = $this->usage->start([
            'request_type'    => 'image_generation',
            'user_id'         => $userId,
            'azure_config_id' => $config['id'] ?? null,
            'deployment_name' => $config['deployment_name'],
            'model_name'      => $config['model_name'],
            'endpoint_path'   => $url,
        ]);

        $res = $this->send($config, 'POST', $url, ImageModels::requestBody($config, $prompt, $size, $quality), $log['client_request_id']);
        $out = ['usage_log_id' => $log['id']] + $res + ['images' => [], 'revised_prompt' => null, 'usage' => self::emptyUsage()];

        if ($res['outcome'] === 'success') {
            $data = $res['json']['data'] ?? null;
            foreach (is_array($data) ? $data : [] as $item) {
                if (is_array($item) && is_string($item['b64_json'] ?? null) && $item['b64_json'] !== '') {
                    $out['images'][] = $item['b64_json'];
                    $out['revised_prompt'] ??= is_string($item['revised_prompt'] ?? null) ? $item['revised_prompt'] : null;
                }
            }
            $out['usage'] = self::parseUsage($res['json']['usage'] ?? null);
            if ($out['images'] === []) {
                $out['outcome'] = 'failed';
                $out['error_code'] = 'no_image_data';
                $out['error_message'] = 'Azure answered but returned no image data.';
            }
        }

        $cost = $out['outcome'] === 'success'
            ? (new CostCalculator($this->fs))->forImage($config, $out['usage'], count($out['images']), $size, $quality, $log['created_at'])
            : ['cost_status' => 'not_billable', 'cost_usd' => null, 'cost_inr' => null, 'usd_inr_rate' => null, 'pricing_id' => null];
        $out['cost'] = $cost;

        $this->usage->complete($log['id'], [
            'status'              => match ($out['outcome']) { 'success' => 'success', 'failed' => 'failed', default => 'unknown' },
            'http_status'         => $res['http_status'] ?: null,
            'azure_request_id'    => $res['azure_request_id'],
            'input_tokens'        => $out['usage']['input_tokens'],
            'output_tokens'       => $out['usage']['output_tokens'],
            'total_tokens'        => $out['usage']['total_tokens'],
            'image_input_tokens'  => $out['usage']['image_input_tokens'],
            'image_output_tokens' => $out['usage']['image_output_tokens'],
            'image_count'         => count($out['images']),
            'latency_ms'          => $res['latency_ms'],
            'error_code'          => $out['error_code'],
            'error_message'       => $out['error_message'],
        ] + $cost);
        unset($out['json']);
        return $out;
    }

    public function generateContent(array $config, array $messages, int $userId): array
    {
        $url = self::url($config, 'chat/completions');
        $log = $this->usage->start([
            'request_type' => 'chat', 'user_id' => $userId,
            'azure_config_id' => $config['id'], 'deployment_name' => $config['deployment_name'],
            'model_name' => $config['model_name'], 'endpoint_path' => $url,
        ]);
        $res = $this->send($config, 'POST', $url, [
            'model' => $config['deployment_name'], 'messages' => $messages, 'max_completion_tokens' => 2000,
        ], $log['client_request_id']);
        $text = $res['json']['choices'][0]['message']['content'] ?? null;
        $finish = $res['json']['choices'][0]['finish_reason'] ?? null;
        if ($res['outcome'] === 'success' && (!is_string($text) || trim($text) === '')) {
            $res['outcome'] = 'failed';
            $res['error_code'] = 'no_content';
            $res['error_message'] = 'Azure returned no text. Try revising your prompt.';
        }
        $u = $res['json']['usage'] ?? [];
        $tokens = [
            'input_tokens' => $u['prompt_tokens'] ?? null,
            'cached_input_tokens' => $u['prompt_tokens_details']['cached_tokens'] ?? null,
            'output_tokens' => $u['completion_tokens'] ?? null,
            'total_tokens' => $u['total_tokens'] ?? null,
        ];
        $cost = $res['outcome'] === 'success'
            ? (new CostCalculator($this->fs))->forText($config, $tokens, $log['created_at'])
            : ['cost_status' => 'not_billable', 'cost_usd' => null, 'cost_inr' => null, 'usd_inr_rate' => null, 'pricing_id' => null];
        $this->usage->complete($log['id'], [
            'status' => $res['outcome'], 'http_status' => $res['http_status'] ?: null,
            'azure_request_id' => $res['azure_request_id'], 'latency_ms' => $res['latency_ms'],
            'error_code' => $res['error_code'], 'error_message' => $res['error_message'],
        ] + $tokens + $cost);
        unset($res['json']);
        return $res + ['text' => is_string($text) ? $text : '', 'truncated' => $finish === 'length',
            'usage' => $tokens, 'cost' => $cost, 'usage_log_id' => $log['id']];
    }

    public static function isSpeechModel(string $model): bool
    {
        return (bool)preg_match('/^(tts(?:-1)?(?:-hd)?|gpt-4o-mini-tts(?:-\d{4}-\d{2}-\d{2})?)$/i', $model);
    }

    public function generateSpeech(array $config, string $input, string $voice, int $userId): array
    {
        $url = self::url($config, 'audio/speech');
        $log = $this->usage->start([
            'request_type' => 'speech', 'user_id' => $userId, 'azure_config_id' => $config['id'],
            'deployment_name' => $config['deployment_name'], 'model_name' => $config['model_name'], 'endpoint_path' => $url,
        ]);
        $res = $this->send($config, 'POST', $url, [
            'model' => $config['deployment_name'], 'input' => $input, 'voice' => $voice, 'response_format' => 'mp3',
        ], $log['client_request_id'], true);
        $this->usage->complete($log['id'], [
            'status' => $res['outcome'], 'http_status' => $res['http_status'] ?: null,
            'azure_request_id' => $res['azure_request_id'], 'latency_ms' => $res['latency_ms'],
            'error_code' => $res['error_code'], 'error_message' => $res['error_message'],
            // Binary speech responses do not report token usage; never estimate it.
            'cost_status' => $res['outcome'] === 'failed' ? 'not_billable' : 'no_usage',
        ]);
        unset($res['json']);
        return $res + ['audio' => '', 'usage_log_id' => $log['id']];
    }

    /**
     * Cheap connection check that costs nothing: list models with the key.
     * Proves the endpoint is reachable and the key is accepted.
     */
    public function verify(array $config): array
    {
        $url = ($config['api_style'] ?? 'v1') === 'v1'
            ? rtrim((string)$config['endpoint_base'], '/') . '/models'
            : preg_replace('#/openai(/v1)?$#', '', rtrim((string)$config['endpoint_base'], '/')) . '/openai/models?api-version=' . rawurlencode((string)$config['api_version']);
        $res = $this->send($config, 'GET', $url, null, null);
        return ['ok' => $res['outcome'] === 'success', 'http_status' => $res['http_status'], 'error' => $res['error_message'], 'latency_ms' => $res['latency_ms']];
    }

    /**
     * Deployments on the resource behind a config (free, read-only).
     * @return array{ok: bool, error: ?string, items: list<array{deployment_name: string, model_name: string, status: string}>}
     */
    public function listDeployments(array $config): array
    {
        $host = preg_replace('#/openai(/v1)?$#', '', rtrim((string)$config['endpoint_base'], '/'));
        $res = $this->send($config, 'GET', $host . '/openai/deployments?api-version=2022-12-01', null, null);
        if ($res['outcome'] !== 'success') {
            return ['ok' => false, 'error' => $res['error_message'] ?? 'Request failed', 'items' => []];
        }
        $items = [];
        foreach ((array)($res['json']['data'] ?? []) as $d) {
            if (is_array($d) && is_string($d['id'] ?? null) && is_string($d['model'] ?? null)) {
                $items[] = ['deployment_name' => $d['id'], 'model_name' => $d['model'], 'status' => (string)($d['status'] ?? '')];
            }
        }
        return ['ok' => true, 'error' => null, 'items' => $items];
    }

    /** Does a model name look like an image model? */
    public static function isImageModel(string $model): bool
    {
        return (bool)preg_match('/image|dall-?e|flux|stable|mai-image/i', $model);
    }

    // ------------------------------------------------------------------ internals

    private function send(array $config, string $method, string $url, ?array $body, ?string $clientRequestId, bool $audio = false): array
    {
        $base = ['http_status' => 0, 'azure_request_id' => null, 'json' => null, 'error_code' => null, 'error_message' => null, 'latency_ms' => 0, 'retry_after' => null];
        if (!AzureConfigRepository::hostAllowed((string)$config['endpoint_base'])) {
            return ['outcome' => 'failed', 'error_code' => 'endpoint_not_allowed', 'error_message' => 'The endpoint is not an allowed Azure host.'] + $base;
        }
        $key = self::keyFor($config);
        if ($key === null) {
            return ['outcome' => 'failed', 'error_code' => 'missing_key',
                    'error_message' => "The API key variable {$config['api_key_env']} is not set in .env."] + $base;
        }

        $headers = ['api-key: ' . $key, $audio ? 'Accept: audio/mpeg' : 'Accept: application/json'];
        if ($clientRequestId !== null) {
            $headers[] = 'x-ms-client-request-id: ' . $clientRequestId;
        }
        $payload = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $t0 = microtime(true);
        $r = ($this->transport)(
            $method, $url, $headers, $payload,
            max(10, Settings::int('azure.request_timeout_sec', 120)),
            max(3, Settings::int('azure.connect_timeout_sec', 10))
        );
        $latency = (int)round((microtime(true) - $t0) * 1000);
        $reqId = $r['headers']['apim-request-id'] ?? $r['headers']['x-request-id'] ?? $r['headers']['x-ms-request-id'] ?? null;
        $out = ['latency_ms' => $latency, 'azure_request_id' => $reqId, 'http_status' => (int)$r['status'],
            'retry_after' => self::retryAfter((array)($r['headers'] ?? []))] + $base;

        if ($r['error'] !== null) {
            $out['error_code'] = 'transport_' . (int)$r['errno'];
            $out['error_message'] = Logger::redact(self::transportMessage((int)$r['errno'], (string)$r['error']));
            $out['outcome'] = $r['sent'] ? 'unknown' : 'failed';
            return $out;
        }

        $json = json_decode((string)$r['body'], true);
        $status = (int)$r['status'];
        if ($audio && $status >= 200 && $status < 300) {
            $bytes = (string)$r['body'];
            if (strlen($bytes) > 3 && strlen($bytes) <= 20 * 1024 * 1024
                && (str_starts_with($bytes, 'ID3') || (ord($bytes[0]) === 255 && (ord($bytes[1]) & 224) === 224))) {
                return ['outcome' => 'success', 'audio' => $bytes] + $out;
            }
            return ['outcome' => 'unknown', 'error_code' => 'invalid_audio',
                'error_message' => 'Azure did not return a valid MP3 file.'] + $out;
        }
        if ($status >= 200 && $status < 300 && is_array($json)) {
            return ['outcome' => 'success', 'json' => $json] + $out;
        }
        $err = is_array($json['error'] ?? null) ? $json['error'] : [];
        $out['error_code'] = (string)($err['code'] ?? ($err['type'] ?? "http_{$status}"));
        $out['error_message'] = Logger::redact(mb_substr((string)($err['message'] ?? ('Azure returned HTTP ' . $status)), 0, 900));
        // A gateway timeout means Azure may still have done the work.
        $out['outcome'] = in_array($status, [408, 504], true) ? 'unknown' : 'failed';
        if ($status >= 200 && $status < 300) {
            $out['error_code'] = 'bad_response';
            $out['error_message'] = 'Azure returned a response that is not valid JSON.';
            $out['outcome'] = 'unknown';
        }
        return $out;
    }

    /** Seconds Azure asked us to wait (retry-after-ms, or Retry-After as seconds or an HTTP date), else null. */
    public static function retryAfter(array $headers): ?int
    {
        $ms = $headers['retry-after-ms'] ?? null;
        if (is_string($ms) && is_numeric($ms) && (float)$ms >= 0) {
            return min(3600, (int)ceil((float)$ms / 1000));
        }
        $v = trim((string)($headers['retry-after'] ?? ''));
        if ($v === '') {
            return null;
        }
        if (ctype_digit($v)) {
            return min(3600, (int)$v);
        }
        $at = strtotime($v);
        return $at === false ? null : max(0, min(3600, $at - Clock::now()->getTimestamp()));
    }

    private static function transportMessage(int $errno, string $error): string
    {
        return match ($errno) {
            28      => 'Azure did not answer in time.',
            6       => 'The Azure endpoint host name could not be resolved.',
            7       => 'Could not connect to the Azure endpoint.',
            60, 77  => 'PHP could not verify Azure\'s SSL certificate. Set curl.cainfo in php.ini to a current cacert.pem.',
            default => 'Network error talking to Azure: ' . $error,
        };
    }

    private static function emptyUsage(): array
    {
        return ['input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null, 'image_input_tokens' => null, 'image_output_tokens' => null];
    }

    /** gpt-image usage: input_tokens (+ input_tokens_details.text/image_tokens), output_tokens (image tokens), total_tokens. */
    public static function parseUsage(mixed $u): array
    {
        if (!is_array($u)) {
            return self::emptyUsage();
        }
        $int = fn($v) => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int)$v : null;
        $in = $int($u['input_tokens'] ?? null);
        $out = $int($u['output_tokens'] ?? null);
        return [
            'input_tokens'        => $in,
            'output_tokens'       => $out,
            'total_tokens'        => $int($u['total_tokens'] ?? null) ?? ($in !== null && $out !== null ? $in + $out : null),
            'image_input_tokens'  => $int($u['input_tokens_details']['image_tokens'] ?? null),
            'image_output_tokens' => $out,
        ];
    }

    /** Real HTTP via cURL. */
    public static function curlTransport(string $method, string $url, array $headers, ?string $body, int $timeout, int $connectTimeout): array
    {
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_CONNECTTIMEOUT  => $connectTimeout,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION  => function ($ch, string $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // Was anything sent? If the upload started, Azure may have received the request.
        $sent = (float)curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME) > 0 || (float)curl_getinfo($ch, CURLINFO_SIZE_UPLOAD) > 0;
        $error = $errno ? curl_error($ch) : null;
        curl_close($ch);
        return [
            'status'  => $status,
            'headers' => $respHeaders,
            'body'    => is_string($resp) ? $resp : '',
            'error'   => $error,
            'errno'   => $errno,
            'sent'    => $errno ? $sent : true,
        ];
    }
}
