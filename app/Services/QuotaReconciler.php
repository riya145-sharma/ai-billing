<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Ids;
use App\Repositories\ImageGenerationRepository;
use App\Storage\FileStore;

/**
 * Decides what really happened to a stale reservation by looking at the
 * image generation record, which the generator creates BEFORE calling Azure.
 *
 *   no generation record → failed     (never reached the API)
 *   succeeded            → succeeded  (with its real image count; counts even if
 *                                      saving the file failed — Azure produced and billed it)
 *   failed               → failed
 *   pending / unknown    → unknown    (QuotaService waits, then counts it as used)
 */
final class QuotaReconciler
{
    private ImageGenerationRepository $images;

    public function __construct(FileStore $fs)
    {
        $this->images = new ImageGenerationRepository($fs);
    }

    public function __invoke(array $reservation): array
    {
        $gen = null;
        if (!empty($reservation['generation_id']) && Ids::isValid($reservation['generation_id'], 'img')) {
            $gen = $this->images->get($reservation['generation_id']);
        }
        if ($gen === null) {
            $date = Ids::dateOf($reservation['id'], 'rsv');
            foreach ($this->images->forDay($date, (int)$reservation['user_id']) as $g) {
                if (($g['reservation_id'] ?? null) === $reservation['id']) {
                    $gen = $g;
                    break;
                }
            }
        }
        if ($gen === null) {
            return ['outcome' => 'failed', 'reason' => 'no generation record'];
        }
        return match ($gen['status']) {
            'succeeded' => ['outcome' => 'succeeded', 'image_count' => max(1, (int)$gen['image_count']), 'generation_id' => $gen['id']],
            'failed'    => ['outcome' => 'failed', 'reason' => 'generation failed', 'generation_id' => $gen['id']],
            default     => ['outcome' => 'unknown', 'generation_id' => $gen['id']],
        };
    }
}
