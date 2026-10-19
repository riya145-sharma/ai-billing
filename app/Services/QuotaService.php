<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Ids;
use App\Core\Settings;
use App\Repositories\AuditLog;
use App\Repositories\ImageGenerationRepository;
use App\Repositories\SlotDefinitionRepository;
use App\Repositories\UserRepository;
use App\Storage\FileStore;
use DateTimeImmutable;

/**
 * Image quota: one successful image per time slot, max N per IST day.
 *
 * Storage: quota/YYYY/MM/YYYY-MM-DD.json — one file per IST day holding
 *   slots         "<scope_key>|<slot_code>" => slot state
 *   reservations  "rsv_…" => attempt state
 *   idempotency   "<user_id>|<client key>" => "rsv_…"
 * scope_key is "u:<user_id>" (per-user quotas, default) or "org" (shared).
 *
 * Every check-and-reserve happens inside ONE exclusive lock on that day file,
 * so refreshes, extra tabs and simultaneous requests are serialised: exactly
 * one reservation can win a slot.
 *
 * Lifecycles
 *   slot:         reserved → used                 (commit)
 *                 reserved → released             (confirmed failure; slot reusable in its window)
 *                 reserved → reconciling → used | released   (outcome unknown, e.g. lost response)
 *   reservation:  active → committed | released, active → reconciling → committed | released
 *
 * While any slot of the scope is reserved/reconciling, no new reservation is
 * allowed that day — an in-flight or unverified attempt must be resolved
 * before another generation can start.
 *
 * Expired windows are never carried forward: availability is always judged
 * against the current slot's own window.
 *
 * Required call order for the generator (Phase 4):
 *   reserve() → ImageGenerationRepository::create(reservation_id) → Azure call
 *   → commit(actual image count) | release(confirmed failure) | markReconciling(unknown)
 */
final class QuotaService
{
    public const BASE = 'quota';

    public const MSG_DAILY_LIMIT = 'You have reached your daily limit of 3 image generations. Your quota will reset tomorrow.';
    public const MSG_SLOT_USED   = 'Image generation for this time period has already been used.';

    private SlotDefinitionRepository $defs;
    private UserRepository $users;
    private AuditLog $audit;

    public function __construct(private FileStore $fs)
    {
        $this->defs = new SlotDefinitionRepository($fs);
        $this->users = new UserRepository($fs);
        $this->audit = new AuditLog($fs);
    }

    // ------------------------------------------------------------------ configuration helpers

    /** @return array{0: string, 1: string} [scope_type, scope_key] */
    public function scopeFor(int $userId): array
    {
        return Settings::get('quota.scope', 'user') === 'org' ? ['org', 'org'] : ['user', 'u:' . $userId];
    }

    /** Daily image limit for a user: per-user override or global setting, capped at the number of active slots. */
    public function dailyLimitFor(array $user): int
    {
        $limit = $user['daily_image_limit'] ?? null;
        $limit = $limit === null ? Settings::int('quota.daily_image_limit', 3) : (int)$limit;
        return max(0, min($limit, count($this->defs->active())));
    }

    /** Active windows for an IST date with concrete instants. */
    public function windowsFor(string $date): array
    {
        $out = [];
        foreach ($this->defs->active() as $d) {
            $out[] = [
                'code'     => $d['code'],
                'label'    => $d['label'],
                'start'    => $d['start'],
                'end'      => $d['end'],
                'start_at' => Clock::localAt($date, $d['start']),
                'end_at'   => Clock::localAt($date, $d['end']),
            ];
        }
        return $out;
    }

    /** The window containing $now, or null (e.g. 00:00–05:59 IST). */
    public function currentWindow(?DateTimeImmutable $now = null): ?array
    {
        $now ??= Clock::now();
        foreach ($this->windowsFor(Clock::businessDate($now)) as $w) {
            if ($now >= $w['start_at'] && $now < $w['end_at']) {
                return $w;
            }
        }
        return null;
    }

    /** Next window that has not started yet (today or tomorrow), or null if none configured. */
    public function nextWindowStart(?DateTimeImmutable $now = null): ?array
    {
        $now ??= Clock::now();
        $today = Clock::businessDate($now);
        $tomorrow = Clock::businessDate($now->modify('+1 day'));
        foreach ([$today, $tomorrow] as $date) {
            foreach ($this->windowsFor($date) as $w) {
                if ($w['start_at'] > $now) {
                    return $w + ['date' => $date];
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ status (dashboard)

    public function status(int $userId): array
    {
        $user = $this->users->find($userId) ?? throw new \InvalidArgumentException('User not found');
        [$scopeType, $scopeKey] = $this->scopeFor($userId);
        $now = Clock::now();
        $date = Clock::businessDate($now);
        $limit = $this->dailyLimitFor($user);
        $doc = $this->fs->read(Ids::dayFile(self::BASE, $date), self::blankDay($date));

        $imagesToday = self::imagesUsed($doc, $scopeKey);
        $slots = [];
        $next = null;
        foreach ($this->windowsFor($date) as $w) {
            $row = $doc['slots'][$scopeKey . '|' . $w['code']] ?? null;
            $state = match (true) {
                $row !== null && $row['status'] === 'used'                                   => 'used',
                $row !== null && in_array($row['status'], ['reserved', 'reconciling'], true) => 'in_progress',
                $now >= $w['end_at']                                                         => 'expired',
                $now < $w['start_at']                                                        => 'upcoming',
                default                                                                      => 'available',
            };
            if ($next === null && in_array($state, ['available', 'upcoming'], true)) {
                $next = ['code' => $w['code'], 'label' => $w['label'], 'starts_at' => Clock::toIso($w['start_at']), 'is_now' => $state === 'available'];
            }
            $slots[] = [
                'code'        => $w['code'],
                'label'       => $w['label'],
                'window'      => $w['start'] . '–' . $w['end'],
                'window_text' => self::windowText($w),
                'state'       => $state,
                'images_used' => (int)($row['images_used'] ?? 0),
                'used_at'     => $row['used_at'] ?? null,
            ];
        }

        $openSlots = count(array_filter($slots, fn($s) => in_array($s['state'], ['available', 'upcoming'], true)));
        $remaining = max(0, min($limit - $imagesToday, $openSlots));
        if ($remaining === 0) {
            $next = null;
        }
        $limitReached = $limit > 0 && $imagesToday >= $limit;

        return [
            'date'              => $date,
            'scope'             => $scopeType,
            'daily_limit'       => $limit,
            'images_today'      => $imagesToday,
            'remaining_today'   => $remaining,
            'limit_reached'     => $limitReached,
            'message'           => $limit === 0 ? 'Image generation is not enabled for your account.'
                                   : ($limitReached ? self::dailyLimitMessage($limit) : null),
            'slots'             => $slots,
            'next_available'    => $next ?? ($remaining === 0 ? $this->tomorrowFirst($now) : null),
            'images_this_month' => (new ImageGenerationRepository($this->fs))
                ->countSucceededInMonth(Clock::billingPeriod($now), $scopeType === 'org' ? null : $userId),
            'resets_at'         => Clock::toIso(Clock::localAt($date, '24:00')),
        ];
    }

    // ------------------------------------------------------------------ reserve / commit / release

    /**
     * Atomically reserve the current slot. Safe to retry with the same
     * $idempotencyKey (e.g. a double-submitted form): the original
     * reservation is returned with replayed = true.
     *
     * @return array{reservation: array, replayed: bool}
     * @throws QuotaException when a rule blocks the request
     */
    public function reserve(int $userId, string $idempotencyKey): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $idempotencyKey)) {
            throw new \InvalidArgumentException('Invalid request id');
        }
        $user = $this->users->find($userId);
        if ($user === null || $user['status'] !== 'active') {
            throw new QuotaException('account_disabled', 'Your account is not active.', 403);
        }
        $limit = $this->dailyLimitFor($user);
        if ($limit === 0) {
            throw new QuotaException('no_image_access', 'Image generation is not enabled for your account.', 403);
        }

        $now = Clock::now();
        $window = $this->currentWindow($now);
        if ($window === null) {
            $next = $this->nextWindowStart($now);
            throw new QuotaException(
                'outside_window',
                $next ? "Image generation is not available right now. The next slot ({$next['label']}) opens at "
                    . $next['start_at']->format('g:i A') . ' IST.'
                    : 'Image generation is not available right now.',
                409,
                ['next_starts_at' => $next ? Clock::toIso($next['start_at']) : null]
            );
        }

        $date = Clock::businessDate($now);
        [$scopeType, $scopeKey] = $this->scopeFor($userId);
        $ttl = max(60, Settings::int('quota.reservation_ttl_sec', 300));

        $result = $this->fs->update(Ids::dayFile(self::BASE, $date), function (array &$doc) use (
            $userId, $idempotencyKey, $limit, $now, $date, $window, $scopeType, $scopeKey, $ttl
        ) {
            $doc += self::blankDay($date);
            $idem = $userId . '|' . $idempotencyKey;
            if (isset($doc['idempotency'][$idem])) {
                return ['reservation' => $doc['reservations'][$doc['idempotency'][$idem]], 'replayed' => true];
            }

            if (self::imagesUsed($doc, $scopeKey) >= $limit) {
                throw new QuotaException('daily_limit_reached', self::dailyLimitMessage($limit), 429);
            }
            foreach ($doc['slots'] as $s) {
                if ($s['scope_key'] === $scopeKey && in_array($s['status'], ['reserved', 'reconciling'], true)) {
                    throw new QuotaException(
                        $s['status'] === 'reconciling' ? 'pending_reconciliation' : 'generation_in_progress',
                        $s['status'] === 'reconciling'
                            ? 'A previous image generation is still being verified. Please try again in a few minutes.'
                            : 'An image generation is already in progress. Please wait for it to finish.',
                        409
                    );
                }
            }

            $slotKey = $scopeKey . '|' . $window['code'];
            $slot = $doc['slots'][$slotKey] ?? null;
            if ($slot !== null && $slot['status'] === 'used') {
                throw new QuotaException('slot_used', self::MSG_SLOT_USED, 409);
            }

            $rid = Ids::make('rsv', $date);
            $nowIso = Clock::toIso($now);
            $doc['reservations'][$rid] = [
                'id'                => $rid,
                'idempotency_key'   => $idempotencyKey,
                'user_id'           => $userId,
                'slot_key'          => $slotKey,
                'slot_code'         => $window['code'],
                'status'            => 'active',
                'generation_id'     => null,
                'final_image_count' => null,
                'unverified'        => false,
                'release_reason'    => null,
                'created_at'        => $nowIso,
                'expires_at'        => Clock::toIso($now->modify("+{$ttl} seconds")),
                'resolved_at'       => null,
            ];
            $doc['slots'][$slotKey] = [
                'scope_type'            => $scopeType,
                'scope_key'             => $scopeKey,
                'slot_code'             => $window['code'],
                'label'                 => $window['label'],
                'window_start'          => Clock::toIso($window['start_at']),   // snapshot: later admin edits don't rewrite history
                'window_end'            => Clock::toIso($window['end_at']),
                'status'                => 'reserved',
                'user_id'               => $userId,
                'active_reservation_id' => $rid,
                'images_used'           => 0,
                'used_at'               => null,
                'release_count'         => (int)($slot['release_count'] ?? 0),
                'reserved_at'           => $nowIso,
            ];
            $doc['idempotency'][$idem] = $rid;
            return ['reservation' => $doc['reservations'][$rid], 'replayed' => false];
        }, self::blankDay($date));

        if (!$result['replayed']) {
            $this->audit->record('quota.reserved', $userId, 'reservation', $result['reservation']['id'], ['slot' => $window['code']]);
        }
        return $result;
    }

    /**
     * Mark the attempt successful with the ACTUAL number of images returned.
     * Idempotent. A count of 0 is treated as a failure and releases the slot.
     */
    public function commit(string $reservationId, int $imageCount, ?string $generationId = null, bool $unverified = false): array
    {
        if ($imageCount <= 0) {
            return $this->release($reservationId, 'no_images_returned');
        }
        $date = Ids::dateOf($reservationId, 'rsv');
        $late = false;
        $res = $this->fs->update(Ids::dayFile(self::BASE, $date), function (array &$doc) use ($reservationId, $imageCount, $generationId, $unverified, &$late) {
            $r = $doc['reservations'][$reservationId] ?? throw new \InvalidArgumentException('Reservation not found');
            if ($r['status'] === 'committed') {
                return $r;
            }
            // A success that arrives after the attempt was released still counts
            // (real images exist); the slot is marked used regardless.
            $late = $r['status'] === 'released';
            $now = Clock::nowIso();
            $r['status'] = 'committed';
            $r['final_image_count'] = $imageCount;
            $r['generation_id'] = $generationId ?? $r['generation_id'];
            $r['unverified'] = $unverified;
            $r['resolved_at'] = $now;
            $doc['reservations'][$reservationId] = $r;

            $slot = &$doc['slots'][$r['slot_key']];
            $slot['status'] = 'used';
            $slot['images_used'] = (int)($slot['images_used'] ?? 0) + $imageCount;
            $slot['used_at'] ??= $now;
            $slot['user_id'] = $r['user_id'];
            if (($slot['active_reservation_id'] ?? null) === $reservationId) {
                $slot['active_reservation_id'] = null;
            }
            unset($slot);
            return $r;
        }, self::blankDay($date));

        $this->audit->record(
            $late ? 'quota.late_commit' : ($unverified ? 'quota.committed_unverified' : 'quota.committed'),
            $res['user_id'], 'reservation', $reservationId,
            ['images' => $imageCount, 'generation_id' => $generationId] + ($imageCount > 1 ? ['multi_image_output' => true] : [])
        );
        return $res;
    }

    /** Confirmed failure: free the slot so it can be used again within its window. Idempotent. */
    public function release(string $reservationId, string $reason): array
    {
        $date = Ids::dateOf($reservationId, 'rsv');
        $changed = false;
        $res = $this->fs->update(Ids::dayFile(self::BASE, $date), function (array &$doc) use ($reservationId, $reason, &$changed) {
            $r = $doc['reservations'][$reservationId] ?? throw new \InvalidArgumentException('Reservation not found');
            if ($r['status'] === 'released') {
                return $r;
            }
            if ($r['status'] === 'committed') {
                throw new \LogicException('A committed reservation cannot be released (use adminReleaseSlot).');
            }
            $r['status'] = 'released';
            $r['release_reason'] = mb_substr($reason, 0, 255);
            $r['resolved_at'] = Clock::nowIso();
            $doc['reservations'][$reservationId] = $r;

            $slot = &$doc['slots'][$r['slot_key']];
            if (($slot['active_reservation_id'] ?? null) === $reservationId && in_array($slot['status'], ['reserved', 'reconciling'], true)) {
                $slot['status'] = 'released';
                $slot['active_reservation_id'] = null;
                $slot['release_count'] = (int)($slot['release_count'] ?? 0) + 1;
            }
            unset($slot);
            $changed = true;
            return $r;
        }, self::blankDay($date));

        if ($changed) {
            $this->audit->record('quota.released', $res['user_id'], 'reservation', $reservationId, ['reason' => $reason]);
        }
        return $res;
    }

    /** Outcome unknown (timeout, lost response): block the scope until reconcile() resolves it. */
    public function markReconciling(string $reservationId, string $reason): array
    {
        $date = Ids::dateOf($reservationId, 'rsv');
        return $this->fs->update(Ids::dayFile(self::BASE, $date), function (array &$doc) use ($reservationId, $reason) {
            $r = $doc['reservations'][$reservationId] ?? throw new \InvalidArgumentException('Reservation not found');
            if ($r['status'] !== 'active') {
                return $r;
            }
            $r['status'] = 'reconciling';
            $r['release_reason'] = mb_substr($reason, 0, 255);
            $doc['reservations'][$reservationId] = $r;
            if (($doc['slots'][$r['slot_key']]['active_reservation_id'] ?? null) === $reservationId) {
                $doc['slots'][$r['slot_key']]['status'] = 'reconciling';
            }
            return $r;
        }, self::blankDay($date));
    }

    /** Link the generation record to its reservation (used by the reconciler). */
    public function attachGeneration(string $reservationId, string $generationId): void
    {
        $date = Ids::dateOf($reservationId, 'rsv');
        $this->fs->update(Ids::dayFile(self::BASE, $date), function (array &$doc) use ($reservationId, $generationId) {
            if (isset($doc['reservations'][$reservationId])) {
                $doc['reservations'][$reservationId]['generation_id'] = $generationId;
            }
        }, self::blankDay($date));
    }

    /** Reservation a user made today with a given idempotency key (to recover a result after a dropped connection). */
    public function reservationByKey(int $userId, string $idempotencyKey, ?string $date = null): ?array
    {
        $date ??= Clock::businessDate();
        $doc = $this->fs->read(Ids::dayFile(self::BASE, $date), self::blankDay($date));
        $rid = $doc['idempotency'][$userId . '|' . $idempotencyKey] ?? null;
        return $rid !== null ? ($doc['reservations'][$rid] ?? null) : null;
    }

    public function getReservation(string $reservationId): ?array
    {
        $date = Ids::dateOf($reservationId, 'rsv');
        return $this->fs->read(Ids::dayFile(self::BASE, $date), self::blankDay($date))['reservations'][$reservationId] ?? null;
    }

    /**
     * Admin override: give a slot back (e.g. a generation was counted as
     * "unverified" but the admin confirmed in Azure that it failed).
     */
    public function adminReleaseSlot(string $date, string $scopeKey, string $slotCode, int $adminId, string $reason): array
    {
        $slotKey = $scopeKey . '|' . $slotCode;
        $slot = $this->fs->update(Ids::dayFile(self::BASE, $date), function (array &$doc) use ($slotKey, $reason) {
            $slot = $doc['slots'][$slotKey] ?? throw new \InvalidArgumentException('Slot not found');
            foreach ($doc['reservations'] as $id => $r) {
                if ($r['slot_key'] === $slotKey && in_array($r['status'], ['active', 'reconciling', 'committed'], true)) {
                    $doc['reservations'][$id]['status'] = 'released';
                    $doc['reservations'][$id]['release_reason'] = 'admin: ' . mb_substr($reason, 0, 200);
                    $doc['reservations'][$id]['resolved_at'] = Clock::nowIso();
                }
            }
            $slot['status'] = 'released';
            $slot['images_used'] = 0;
            $slot['active_reservation_id'] = null;
            $slot['release_count'] = (int)($slot['release_count'] ?? 0) + 1;
            $doc['slots'][$slotKey] = $slot;
            return $slot;
        }, self::blankDay($date));
        $this->audit->record('quota.admin_released', $adminId, 'slot', "{$date}|{$slotKey}", ['reason' => $reason]);
        return $slot;
    }

    /**
     * Resolve stale attempts (cron). For each active reservation past its
     * expiry, and each reconciling one, ask $resolver what really happened:
     *   ['outcome' => 'succeeded', 'image_count' => n, 'generation_id' => …]
     *   ['outcome' => 'failed']
     *   ['outcome' => 'unknown']
     * Unknown outcomes older than quota.max_unverified_minutes are counted as
     * used (conservative: the image may exist) and flagged "unverified" so an
     * admin can give the slot back.
     *
     * @return array{committed: int, released: int, reconciling: int, unverified: int}
     */
    public function reconcile(callable $resolver, int $daysBack = 2): array
    {
        $now = Clock::now();
        $nowIso = Clock::toIso($now);
        $maxAge = max(5, Settings::int('quota.max_unverified_minutes', 30));
        $stats = ['committed' => 0, 'released' => 0, 'reconciling' => 0, 'unverified' => 0];

        for ($i = $daysBack; $i >= 0; $i--) {
            $date = Clock::businessDate($now->modify("-{$i} day"));
            $doc = $this->fs->read(Ids::dayFile(self::BASE, $date), self::blankDay($date));
            foreach ($doc['reservations'] as $r) {
                $stale = $r['status'] === 'reconciling' || ($r['status'] === 'active' && $r['expires_at'] <= $nowIso);
                if (!$stale) {
                    continue;
                }
                $decision = $resolver($r);
                $outcome = $decision['outcome'] ?? 'unknown';
                if ($outcome === 'succeeded' && (int)($decision['image_count'] ?? 0) > 0) {
                    $this->commit($r['id'], (int)$decision['image_count'], $decision['generation_id'] ?? null);
                    $stats['committed']++;
                } elseif ($outcome === 'failed') {
                    $this->release($r['id'], 'reconciled: ' . ($decision['reason'] ?? 'failed'));
                    $stats['released']++;
                } else {
                    $ageMin = ($now->getTimestamp() - Clock::parseIso($r['created_at'])->getTimestamp()) / 60;
                    if ($ageMin >= $maxAge) {
                        // n=1 is always requested, so an unverifiable attempt counts as one image.
                        $this->commit($r['id'], 1, $r['generation_id'], true);
                        $stats['unverified']++;
                    } else {
                        $this->markReconciling($r['id'], 'awaiting verification');
                        $stats['reconciling']++;
                    }
                }
            }
        }
        return $stats;
    }

    // ------------------------------------------------------------------ helpers

    public static function blankDay(string $date): array
    {
        return ['date' => $date, 'slots' => [], 'reservations' => [], 'idempotency' => []];
    }

    private static function imagesUsed(array $doc, string $scopeKey): int
    {
        $n = 0;
        foreach ($doc['slots'] ?? [] as $s) {
            if ($s['scope_key'] === $scopeKey && $s['status'] === 'used') {
                $n += (int)$s['images_used'];
            }
        }
        return $n;
    }

    private static function dailyLimitMessage(int $limit): string
    {
        return $limit === 3 ? self::MSG_DAILY_LIMIT
            : "You have reached your daily limit of {$limit} image generation" . ($limit === 1 ? '' : 's') . '. Your quota will reset tomorrow.';
    }

    private static function windowText(array $w): string
    {
        $end = $w['end_at']->modify('-1 minute');
        return $w['start_at']->format('g:i A') . ' – ' . $end->format('g:i A');
    }

    private function tomorrowFirst(DateTimeImmutable $now): ?array
    {
        $date = Clock::businessDate($now->modify('+1 day'));
        $w = $this->windowsFor($date)[0] ?? null;
        return $w ? ['code' => $w['code'], 'label' => $w['label'], 'starts_at' => Clock::toIso($w['start_at']), 'is_now' => false] : null;
    }
}
