<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Backfill;

use Closure;
use InvalidArgumentException;
use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Data\BackfillResult;

/**
 * Gives every record of a source table that holds no entry of the kind one entry "all": to the group of its
 * creator, else to the fallback group, else the record is skipped. The fallback group is a number or a closure
 * that names it per tenant; the closure is asked at most once per tenant and batch, and only when a record of
 * that tenant needs it.
 *
 * Works in key order and batch by batch, so skipped records (which stay without an entry) are passed over and
 * a large table never sits in memory at once. Existing entries stay: only records without one are touched.
 * Opens no transaction of its own; the caller brackets the call. Names are validated before anything runs.
 */
final class BackfillEntries
{
    public function __construct(
        private readonly Closure $assertTable,
        private readonly Closure $assertColumn,
        private readonly Closure $findRecords,
        private readonly Closure $upsertEntries,
    ) {
    }

    /**
     * @param Closure(?string, list<string>): array<string, int> $groupOfCreator
     * @param int|Closure(?string): ?int|null $fallbackGroup
     */
    public function __invoke(
        int $kind,
        string $sourceTable,
        string $keyColumn,
        ?string $creatorColumn,
        ?string $tenantColumn,
        Closure $groupOfCreator,
        int|Closure|null $fallbackGroup,
        int $batch,
    ): BackfillResult {
        $this->assertInput($sourceTable, $keyColumn, $creatorColumn, $tenantColumn, $batch);

        $written = 0;
        $toFallback = 0;
        $skipped = 0;
        $withCreator = 0;
        $resolved = 0;
        $afterKey = null;

        do {
            $records = ($this->findRecords)(
                $kind,
                $sourceTable,
                $keyColumn,
                $creatorColumn,
                $tenantColumn,
                $afterKey,
                $batch,
            );

            $placed = $this->placeRecords($records, $groupOfCreator, $fallbackGroup);
            foreach ($placed['idsByGroup'] as $group => $recordIds) {
                ($this->upsertEntries)($kind, $group, AccessLevel::All, $recordIds);
            }
            $written += $placed['written'];
            $toFallback += $placed['toFallback'];
            $skipped += $placed['skipped'];
            $withCreator += $placed['withCreator'];
            $resolved += $placed['resolved'];
            $afterKey = $records === [] ? $afterKey : $records[array_key_last($records)]['key'];
        } while (count($records) === $batch);

        return new BackfillResult($written, $toFallback, $skipped, $withCreator, $resolved);
    }

    private function assertInput(
        string $sourceTable,
        string $keyColumn,
        ?string $creatorColumn,
        ?string $tenantColumn,
        int $batch,
    ): void {
        ($this->assertTable)($sourceTable, 'source table');
        ($this->assertColumn)($keyColumn);
        if ($creatorColumn !== null) {
            ($this->assertColumn)($creatorColumn);
        }
        if ($tenantColumn !== null) {
            ($this->assertColumn)($tenantColumn);
        }
        if ($batch < 1) {
            throw new InvalidArgumentException(sprintf('Batch size must be at least 1, got %d', $batch));
        }
    }

    /**
     * Decides the group of each record of the batch: creator group, else fallback group, else skipped.
     *
     * @param list<array{key: int, creator: ?string, tenant: ?string}> $records
     * @param Closure(?string, list<string>): array<string, int> $groupOfCreator
     * @param int|Closure(?string): ?int|null $fallbackGroup
     * @return array{
     *     idsByGroup: array<int, list<int>>, written: int, toFallback: int, skipped: int,
     *     withCreator: int, resolved: int
     * }
     */
    private function placeRecords(array $records, Closure $groupOfCreator, int|Closure|null $fallbackGroup): array
    {
        $groupsByCreator = $this->resolveCreators($records, $groupOfCreator);
        $fallbacks = [];
        $placed = [
            'idsByGroup' => [],
            'written' => 0,
            'toFallback' => 0,
            'skipped' => 0,
            'withCreator' => 0,
            'resolved' => 0,
        ];

        foreach ($records as $record) {
            $hasCreator = $record['creator'] !== null && $record['creator'] !== '';
            $group = $hasCreator
                ? ($groupsByCreator[$this->tenantKey($record['tenant'])][$record['creator']] ?? null)
                : null;
            $placed['withCreator'] += $hasCreator ? 1 : 0;
            if ($group !== null) {
                $placed['resolved']++;
            }
            if ($group === null) {
                $group = $this->fallbackOf($fallbackGroup, $record['tenant'], $fallbacks);
                $placed['toFallback'] += $group === null ? 0 : 1;
            }
            if ($group === null) {
                $placed['skipped']++;
                continue;
            }
            $placed['idsByGroup'][$group][] = $record['key'];
            $placed['written']++;
        }

        return $placed;
    }

    /**
     * The fallback group of a tenant: the number itself, or what the closure names for the tenant. The closure
     * is asked on the first need of a tenant only; $asked keeps the answers of the batch.
     *
     * @param int|Closure(?string): ?int|null $fallbackGroup
     * @param array<string, ?int> $asked answer by tenant key
     */
    private function fallbackOf(int|Closure|null $fallbackGroup, ?string $tenant, array &$asked): ?int
    {
        if (!$fallbackGroup instanceof Closure) {
            return $fallbackGroup;
        }
        $key = $this->tenantKey($tenant);
        if (!array_key_exists($key, $asked)) {
            $answer = $fallbackGroup($tenant);
            $asked[$key] = $answer === null ? null : (int) $answer;
        }

        return $asked[$key];
    }

    /**
     * Asks the closure once per tenant of the batch for the groups of the creators seen there.
     *
     * @param list<array{key: int, creator: ?string, tenant: ?string}> $records
     * @param Closure(?string, list<string>): array<string, int> $groupOfCreator
     * @return array<string, array<string, int>> group by creator, by tenant key
     */
    private function resolveCreators(array $records, Closure $groupOfCreator): array
    {
        $creators = [];
        $tenants = [];
        foreach ($records as $record) {
            if ($record['creator'] === null || $record['creator'] === '') {
                continue;
            }
            $tenants[$this->tenantKey($record['tenant'])] = $record['tenant'];
            $creators[$this->tenantKey($record['tenant'])][$record['creator']] = true;
        }

        $groups = [];
        foreach ($creators as $tenantKey => $byCreator) {
            $found = $groupOfCreator($tenants[$tenantKey], array_map('strval', array_keys($byCreator)));
            foreach ($found as $creator => $group) {
                $groups[$tenantKey][(string) $creator] = (int) $group;
            }
        }

        return $groups;
    }

    /** Array key for a tenant; keeps "no tenant" apart from the tenant "". */
    private function tenantKey(?string $tenant): string
    {
        return $tenant === null ? 'n' : 's' . $tenant;
    }
}
