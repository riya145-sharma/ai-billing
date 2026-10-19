<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Clock;
use App\Core\Ids;
use App\Core\Logger;
use App\Storage\FileStore;

/**
 * Append-only audit trail: audit/YYYY/MM/YYYY-MM-DD.jsonl (one JSON object per line).
 * Details are redacted before writing.
 */
final class AuditLog
{
    public const BASE = 'audit';

    public function __construct(private FileStore $fs)
    {
    }

    public function record(string $action, ?int $userId = null, ?string $entityType = null, ?string $entityId = null, array $details = []): void
    {
        if (!preg_match('/^[a-z0-9_.]{3,80}$/', $action)) {
            throw new \InvalidArgumentException("Bad audit action: {$action}");
        }
        $json = $details ? Logger::redact(json_encode($details, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)) : null;
        $this->fs->append(Ids::dayFile(self::BASE, Clock::businessDate(), 'jsonl'), [
            'ts'          => Clock::nowIso(),
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'details'     => $json === null ? null : json_decode($json, true),
            'ip'          => PHP_SAPI === 'cli' ? 'cli' : (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent'  => PHP_SAPI === 'cli' ? null : mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }

    /** Entries for one IST day, newest first. */
    public function forDay(string $date): array
    {
        return array_reverse($this->fs->readLines(Ids::dayFile(self::BASE, $date, 'jsonl')));
    }
}
