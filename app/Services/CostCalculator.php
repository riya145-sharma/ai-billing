<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Money;
use App\Core\Settings;
use App\Repositories\PricingRepository;
use App\Storage\FileStore;

/**
 * Estimated cost of a request from admin-entered prices (never hard-coded).
 * If no price is in force the cost stays null with cost_status = no_pricing,
 * so the dashboard can say "unpriced" instead of showing a wrong number.
 */
final class CostCalculator
{
    private PricingRepository $prices;

    public function __construct(FileStore $fs)
    {
        $this->prices = new PricingRepository($fs);
    }

    public function forText(array $config, array $usage, string $at): array
    {
        $row = $this->prices->resolve($config['model_name'], (int)$config['id'], substr($at, 0, 19) . 'Z', 'per_token');
        $empty = ['cost_usd' => null, 'cost_inr' => null, 'usd_inr_rate' => null, 'pricing_id' => null];
        if ($usage['input_tokens'] === null || $usage['output_tokens'] === null) {
            return ['cost_status' => 'no_usage'] + $empty;
        }
        if ($row === null) {
            return ['cost_status' => 'no_pricing'] + $empty;
        }
        $cached = min((int)$usage['input_tokens'], (int)($usage['cached_input_tokens'] ?? 0));
        $sum = Money::add(Money::mul((string)($usage['input_tokens'] - $cached), $row['input_per_1m']),
            Money::add(Money::mul((string)$cached, $row['cached_input_per_1m'] ?? $row['input_per_1m']),
                Money::mul((string)$usage['output_tokens'], $row['output_per_1m'])));
        $usd = Money::div($sum, '1000000');
        $rate = Settings::decimal('billing.usd_inr_rate', '0');
        return ['cost_status' => 'priced', 'cost_usd' => $usd, 'cost_inr' => Money::mul($usd, $rate),
            'usd_inr_rate' => $rate, 'pricing_id' => (int)$row['id']];
    }

    /**
     * Image cost. Token-metered models (gpt-image) use an image_tokens price
     * when Azure reported usage; otherwise a flat per_image price for the size/quality.
     */
    public function forImage(array $config, array $usage, int $imageCount, string $size, string $quality, string $atIso): array
    {
        $model = (string)$config['model_name'];
        $cfgId = isset($config['id']) ? (int)$config['id'] : null;
        $at = substr($atIso, 0, 19) . 'Z';

        $usd = null;
        $row = null;
        if ($usage['input_tokens'] !== null || $usage['output_tokens'] !== null) {
            $row = $this->prices->resolve($model, $cfgId, $at, 'image_tokens');
            if ($row !== null) {
                $imgIn = (int)($usage['image_input_tokens'] ?? 0);
                $textIn = max(0, (int)($usage['input_tokens'] ?? 0) - $imgIn);
                $out = (int)($usage['output_tokens'] ?? 0);
                $sum = Money::add(
                    Money::mul((string)$textIn, $row['input_per_1m']),
                    Money::add(
                        Money::mul((string)$imgIn, $row['image_input_per_1m'] ?? $row['input_per_1m']),
                        Money::mul((string)$out, $row['image_output_per_1m'])
                    )
                );
                $usd = Money::div($sum, '1000000');
            }
        }
        if ($usd === null) {
            $row = $this->prices->resolve($model, $cfgId, $at, 'per_image', $size, $quality);
            if ($row !== null) {
                $usd = Money::mul($row['price_per_image'], (string)max(1, $imageCount));
            }
        }
        if ($usd === null) {
            return ['cost_status' => 'no_pricing', 'cost_usd' => null, 'cost_inr' => null, 'usd_inr_rate' => null, 'pricing_id' => null];
        }
        $rate = Settings::decimal('billing.usd_inr_rate', '0');
        return [
            'cost_status'  => 'priced',
            'cost_usd'     => $usd,
            'usd_inr_rate' => $rate,
            'cost_inr'     => Money::mul($usd, $rate),
            'pricing_id'   => (int)$row['id'],
        ];
    }
}
