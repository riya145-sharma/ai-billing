<?php
declare(strict_types=1);

namespace App\Storage;

use App\Core\Clock;

/**
 * A small keyed collection stored as one JSON file:
 *   {"meta": {"next_id": 3}, "items": {"1": {...}, "2": {...}}}
 *
 * Suited to low-volume, admin-managed data (users, deployments, prices,
 * budgets). High-volume history is partitioned by day elsewhere.
 *
 * Unique constraints are callables returning a key for an item (or null to
 * skip); they are checked inside the exclusive lock, so two concurrent
 * inserts of the same email cannot both succeed.
 */
final class Collection
{
    /**
     * @param array<string, callable(array): ?string> $unique  name => key function
     */
    public function __construct(
        private FileStore $fs,
        private string $file,
        private array $unique = [],
    ) {
    }

    public static function blank(): array
    {
        return ['meta' => ['next_id' => 1], 'items' => []];
    }

    /** @return array<int, array> id => item */
    public function all(): array
    {
        return $this->fs->read($this->file, self::blank())['items'] ?? [];
    }

    public function find(int $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /** @return array<int, array> */
    public function where(callable $predicate): array
    {
        return array_filter($this->all(), $predicate);
    }

    public function first(callable $predicate): ?array
    {
        foreach ($this->all() as $item) {
            if ($predicate($item)) {
                return $item;
            }
        }
        return null;
    }

    public function insert(array $item): array
    {
        return $this->fs->update($this->file, function (array &$doc) use ($item) {
            $doc += self::blank();
            $id = (int)$doc['meta']['next_id'];
            $now = Clock::nowIso();
            $item = ['id' => $id] + $item + ['created_at' => $now, 'updated_at' => $now];
            $this->assertUnique($doc['items'], $item, null);
            $doc['items'][$id] = $item;
            $doc['meta']['next_id'] = $id + 1;
            return $item;
        }, self::blank(), true);
    }

    /**
     * @param array|callable(array): array $changes  fields to merge, or a function returning the new item
     */
    public function update(int $id, array|callable $changes): array
    {
        return $this->fs->update($this->file, function (array &$doc) use ($id, $changes) {
            $doc += self::blank();
            if (!isset($doc['items'][$id])) {
                throw new StorageException("Record {$id} not found in {$this->file}");
            }
            $current = $doc['items'][$id];
            $next = is_callable($changes) ? $changes($current) : array_merge($current, $changes);
            $next['id'] = $id;
            $next['created_at'] = $current['created_at'] ?? Clock::nowIso();
            $next['updated_at'] = Clock::nowIso();
            $this->assertUnique($doc['items'], $next, $id);
            $doc['items'][$id] = $next;
            return $next;
        }, self::blank(), true);
    }

    public function delete(int $id): bool
    {
        return $this->fs->update($this->file, function (array &$doc) use ($id) {
            $doc += self::blank();
            if (!isset($doc['items'][$id])) {
                return false;
            }
            unset($doc['items'][$id]);
            return true;
        }, self::blank(), true);
    }

    /**
     * Locked access to the whole item map, for invariants spanning rows
     * (e.g. "only one default deployment per purpose"). $fn gets items by
     * reference; unique constraints are re-checked on every item afterwards.
     */
    public function mutate(callable $fn): mixed
    {
        return $this->fs->update($this->file, function (array &$doc) use ($fn) {
            $doc += self::blank();
            $result = $fn($doc['items'], $doc['meta']);
            foreach ($doc['items'] as $id => $item) {
                $this->assertUnique($doc['items'], $item, (int)$id);
            }
            return $result;
        }, self::blank(), true);
    }

    private function assertUnique(array $items, array $candidate, ?int $selfId): void
    {
        foreach ($this->unique as $name => $keyFn) {
            $key = $keyFn($candidate);
            if ($key === null) {
                continue;
            }
            foreach ($items as $id => $other) {
                if ($selfId !== null && (int)$id === $selfId) {
                    continue;
                }
                if ($keyFn($other) === $key) {
                    throw new DuplicateException("Duplicate {$name} in {$this->file}", $name);
                }
            }
        }
    }
}
