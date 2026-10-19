<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Core\Config;
use App\Storage\Collection;
use App\Storage\FileStore;

/**
 * Azure OpenAI deployments (storage/data/azure_configurations.json).
 * API keys are never stored: api_key_env names the environment variable
 * that holds the key. Exactly one default per purpose (chat / image).
 */
final class AzureConfigRepository
{
    public const FILE = 'azure_configurations.json';
    public const PURPOSES = ['chat', 'image', 'speech'];

    private Collection $c;

    public function __construct(private FileStore $fs)
    {
        $this->c = new Collection($fs, self::FILE, [
            'endpoint+deployment' => fn(array $r) => isset($r['endpoint_base'], $r['deployment_name'])
                ? strtolower(rtrim($r['endpoint_base'], '/') . '|' . $r['deployment_name']) : null,
            'default per purpose' => fn(array $r) => !empty($r['is_default']) ? 'default:' . $r['purpose'] : null,
        ]);
    }

    /** @return array<int, array> */
    public function all(): array
    {
        return $this->c->all();
    }

    public function find(int $id): ?array
    {
        return $this->c->find($id);
    }

    /** @return array<int, array> */
    public function active(string $purpose): array
    {
        return $this->c->where(fn(array $r) => $r['purpose'] === $purpose && !empty($r['is_active']));
    }

    /** Default active deployment for a purpose, or null (e.g. no image model deployed yet). */
    public function defaultFor(string $purpose): ?array
    {
        return $this->c->first(fn(array $r) => $r['purpose'] === $purpose && !empty($r['is_active']) && !empty($r['is_default']));
    }

    public function create(array $data): array
    {
        $row = $this->validate($data);
        if (!empty($row['is_default'])) {
            // Clear the previous default and insert atomically, under one lock.
            return $this->c->mutate(function (array &$items, array &$meta) use ($row) {
                foreach ($items as &$it) {
                    if ($it['purpose'] === $row['purpose']) {
                        $it['is_default'] = false;
                    }
                }
                unset($it);
                $id = (int)$meta['next_id'];
                $now = Clock::nowIso();
                $items[$id] = ['id' => $id] + $row + ['created_at' => $now, 'updated_at' => $now];
                $meta['next_id'] = $id + 1;
                return $items[$id];
            });
        }
        return $this->c->insert($row);
    }

    public function update(int $id, array $data): array
    {
        $current = $this->c->find($id) ?? throw new \InvalidArgumentException("Deployment {$id} not found");
        $row = $this->validate(array_merge($current, $data));
        return $this->c->mutate(function (array &$items) use ($id, $row) {
            if (!empty($row['is_default'])) {
                foreach ($items as $k => &$it) {
                    if ((int)$k !== $id && $it['purpose'] === $row['purpose']) {
                        $it['is_default'] = false;
                    }
                }
                unset($it);
            }
            $items[$id] = array_merge($items[$id], $row, ['updated_at' => Clock::nowIso()]);
            return $items[$id];
        });
    }

    /** Enable/disable without re-validating the row (so a broken entry can always be switched off). */
    public function setActive(int $id, bool $active): array
    {
        return $this->c->update($id, $active ? ['is_active' => true] : ['is_active' => false, 'is_default' => false]);
    }

    public function setDefault(int $id): array
    {
        return $this->update($id, ['is_default' => true, 'is_active' => true]);
    }

    public function recordVerification(int $id, bool $ok, array $capabilities, ?string $error): void
    {
        $this->c->update($id, [
            'verification_status' => $ok ? 'ok' : 'failed',
            'capabilities'        => $capabilities,
            'last_verified_at'    => Clock::nowIso(),
            'last_verify_error'   => $error === null ? null : mb_substr($error, 0, 500),
        ]);
    }

    /** Is a host one we are willing to send API keys to? (SSRF / key-leak guard) */
    public static function hostAllowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach ((array)Config::get('azure.allowed_host_suffixes', []) as $suffix) {
            if (str_ends_with($host, strtolower($suffix))) {
                return true;
            }
        }
        return false;
    }

    private function validate(array $d): array
    {
        $purpose = (string)($d['purpose'] ?? '');
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException('Purpose must be chat, image or speech');
        }
        $endpoint = rtrim(trim((string)($d['endpoint_base'] ?? '')), '/');
        if (!self::hostAllowed($endpoint)) {
            throw new \InvalidArgumentException('Endpoint must be an https Azure OpenAI / AI Services URL');
        }
        // The app adds the operation (…/images/generations) itself; a pasted operation URL would break every call.
        if (preg_match('#/(responses|chat/completions|completions|images(/generations)?|embeddings|deployments)(/|$)#i', (string)parse_url($endpoint, PHP_URL_PATH))) {
            throw new \InvalidArgumentException('Endpoint must be the base URL only, e.g. https://your-resource.openai.azure.com/openai/v1 — remove “/responses” or any other operation from the end.');
        }
        $style = (string)($d['api_style'] ?? 'v1');
        if (!in_array($style, ['v1', 'legacy'], true)) {
            throw new \InvalidArgumentException('API style must be v1 or legacy');
        }
        $apiVersion = isset($d['api_version']) && $d['api_version'] !== '' ? (string)$d['api_version'] : null;
        if ($style === 'legacy' && !preg_match('/^\d{4}-\d{2}-\d{2}(-preview)?$/', (string)$apiVersion)) {
            throw new \InvalidArgumentException('Legacy API style needs an api-version like 2025-04-01-preview');
        }
        $keyEnv = (string)($d['api_key_env'] ?? Config::get('azure.default_key_env', 'AZURE_OPENAI_API_KEY'));
        if (!preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $keyEnv)) {
            throw new \InvalidArgumentException('Key variable name must look like AZURE_OPENAI_API_KEY');
        }
        foreach (['name', 'deployment_name', 'model_name'] as $f) {
            $v = trim((string)($d[$f] ?? ''));
            if ($v === '' || mb_strlen($v) > 100) {
                throw new \InvalidArgumentException("{$f} is required (max 100 chars)");
            }
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', (string)$d['deployment_name'])) {
            throw new \InvalidArgumentException('Deployment name may only contain letters, digits, . _ -');
        }
        $retire = $d['retirement_date'] ?? null;
        if ($retire !== null && $retire !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$retire)) {
            throw new \InvalidArgumentException('Retirement date must be YYYY-MM-DD');
        }
        $posInt = fn($v) => ($v === null || $v === '') ? null
            : (filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: throw new \InvalidArgumentException('Limits must be positive integers'));

        return [
            'name'                => trim((string)$d['name']),
            'purpose'             => $purpose,
            'endpoint_base'       => $endpoint,
            'api_style'           => $style,
            'api_version'         => $apiVersion,
            'api_key_env'         => $keyEnv,
            'deployment_name'     => trim((string)$d['deployment_name']),
            'model_name'          => trim((string)$d['model_name']),
            'model_version'       => ($d['model_version'] ?? null) ?: null,
            'deployment_type'     => ($d['deployment_type'] ?? null) ?: null,
            'tpm_limit'           => $posInt($d['tpm_limit'] ?? null),
            'rpm_limit'           => $posInt($d['rpm_limit'] ?? null),
            'lifecycle_status'    => ($d['lifecycle_status'] ?? null) ?: null,
            'retirement_date'     => $retire ?: null,
            'capabilities'        => $d['capabilities'] ?? null,
            'verification_status' => $d['verification_status'] ?? 'unverified',
            'last_verified_at'    => $d['last_verified_at'] ?? null,
            'last_verify_error'   => $d['last_verify_error'] ?? null,
            'is_active'           => (bool)($d['is_active'] ?? true),
            'is_default'          => (bool)($d['is_default'] ?? false),
        ];
    }
}
