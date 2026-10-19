<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Storage\Collection;
use App\Storage\FileStore;

/**
 * Admin-entered prices (storage/data/model_pricing.json). Nothing is
 * hard-coded: costs stay "no pricing" until an admin enters official rates.
 *
 * pricing_method
 *   per_token    — text models: input / cached input / output, USD per 1M tokens
 *   per_image    — flat USD per image for a given size + quality
 *   image_tokens — token-metered image models: text input, image input,
 *                  image output, USD per 1M tokens
 *
 * Resolution for a request at time T: rows for the model (and method / size /
 * quality) whose window [effective_from, effective_to) contains T; a row
 * scoped to the specific deployment beats a model-wide row; then the latest
 * effective_from wins. Adding a new price auto-closes the previous open row
 * for the same scope, so history is preserved and never overlaps.
 * Amounts are stored as decimal strings to avoid float drift.
 */
final class PricingRepository
{
    public const FILE = 'model_pricing.json';
    public const METHODS = ['per_token', 'per_image', 'image_tokens'];

    private Collection $c;

    public function __construct(private FileStore $fs)
    {
        $this->c = new Collection($fs, self::FILE);
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

    public function add(array $d, ?int $userId = null): array
    {
        $row = $this->validate($d);
        $row['created_by'] = $userId;
        $scope = self::scopeKey($row);

        return $this->c->mutate(function (array &$items, array &$meta) use ($row, $scope) {
            foreach ($items as &$it) {
                if (self::scopeKey($it) !== $scope) {
                    continue;
                }
                if ($it['effective_from'] === $row['effective_from']) {
                    throw new \InvalidArgumentException('A price for this model/scope already starts at that time; edit it instead.');
                }
                // Close the open row that started before the new one.
                if ($it['effective_to'] === null && $it['effective_from'] < $row['effective_from']) {
                    $it['effective_to'] = $row['effective_from'];
                    $it['updated_at'] = Clock::nowIso();
                }
                // A new row inserted before an existing later row ends where that one starts.
                if ($it['effective_from'] > $row['effective_from']
                    && ($row['effective_to'] === null || $row['effective_to'] > $it['effective_from'])) {
                    $row['effective_to'] = $it['effective_from'];
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

    /**
     * Price row in force for a request, or null if none was entered.
     * $atIso: UTC ISO timestamp of the request.
     */
    public function resolve(string $model, ?int $azureConfigId, string $atIso, string $method, ?string $size = null, ?string $quality = null): ?array
    {
        $model = strtolower($model);
        $best = null;
        foreach ($this->c->all() as $r) {
            if (strtolower($r['model_name']) !== $model || $r['pricing_method'] !== $method) {
                continue;
            }
            if ($r['azure_config_id'] !== null && $r['azure_config_id'] !== $azureConfigId) {
                continue;
            }
            if ($method === 'per_image' && ($r['image_size'] !== $size || $r['image_quality'] !== $quality)) {
                continue;
            }
            if ($r['effective_from'] > $atIso || ($r['effective_to'] !== null && $r['effective_to'] <= $atIso)) {
                continue;
            }
            if ($best === null) {
                $best = $r;
                continue;
            }
            $rSpecific = $r['azure_config_id'] !== null;
            $bSpecific = $best['azure_config_id'] !== null;
            if ($rSpecific !== $bSpecific ? $rSpecific : $r['effective_from'] > $best['effective_from']) {
                $best = $r;
            }
        }
        return $best;
    }

    private static function scopeKey(array $r): string
    {
        return implode('|', [
            strtolower($r['model_name']), $r['azure_config_id'] ?? '*', $r['pricing_method'],
            $r['image_size'] ?? '', $r['image_quality'] ?? '',
        ]);
    }

    private function validate(array $d): array
    {
        $model = trim((string)($d['model_name'] ?? ''));
        if ($model === '' || mb_strlen($model) > 100) {
            throw new \InvalidArgumentException('Model name is required');
        }
        $method = (string)($d['pricing_method'] ?? '');
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Pricing method must be per_token, per_image or image_tokens');
        }
        $cfg = $d['azure_config_id'] ?? null;
        $cfg = ($cfg === null || $cfg === '') ? null : (int)$cfg;

        $dec = static function ($v, string $name, bool $required): ?string {
            if ($v === null || $v === '') {
                if ($required) {
                    throw new \InvalidArgumentException("{$name} is required for this pricing method");
                }
                return null;
            }
            $v = trim((string)$v);
            if (!preg_match('/^\d{1,8}(\.\d{1,8})?$/', $v)) {
                throw new \InvalidArgumentException("{$name} must be a non-negative number (max 8 decimals)");
            }
            return $v;
        };
        $iso = static function ($v, string $name, bool $required): ?string {
            if ($v === null || $v === '') {
                if ($required) {
                    throw new \InvalidArgumentException("{$name} is required");
                }
                return null;
            }
            try {
                return Clock::toIso(new \DateTimeImmutable((string)$v, Clock::tz()));
            } catch (\Exception) {
                throw new \InvalidArgumentException("{$name} is not a valid date/time");
            }
        };

        $row = [
            'model_name'          => $model,
            'azure_config_id'     => $cfg,
            'pricing_method'      => $method,
            'input_per_1m'        => $dec($d['input_per_1m'] ?? null, 'Input price', in_array($method, ['per_token', 'image_tokens'], true)),
            'cached_input_per_1m' => $dec($d['cached_input_per_1m'] ?? null, 'Cached input price', false),
            'output_per_1m'       => $dec($d['output_per_1m'] ?? null, 'Output price', $method === 'per_token'),
            'image_input_per_1m'  => $dec($d['image_input_per_1m'] ?? null, 'Image input price', false),
            'image_output_per_1m' => $dec($d['image_output_per_1m'] ?? null, 'Image output price', $method === 'image_tokens'),
            'image_size'          => $method === 'per_image' ? (trim((string)($d['image_size'] ?? '')) ?: throw new \InvalidArgumentException('Image size is required')) : null,
            'image_quality'       => $method === 'per_image' ? (trim((string)($d['image_quality'] ?? '')) ?: throw new \InvalidArgumentException('Image quality is required')) : null,
            'price_per_image'     => $dec($d['price_per_image'] ?? null, 'Price per image', $method === 'per_image'),
            'currency'            => 'USD',
            // Effective times are entered in IST and stored as UTC.
            'effective_from'      => $iso($d['effective_from'] ?? null, 'Effective from', true),
            'effective_to'        => $iso($d['effective_to'] ?? null, 'Effective to', false),
            'source_note'         => mb_substr(trim((string)($d['source_note'] ?? '')), 0, 255) ?: null,
        ];
        if ($row['effective_to'] !== null && $row['effective_to'] <= $row['effective_from']) {
            throw new \InvalidArgumentException('Effective to must be after effective from');
        }
        return $row;
    }
}
