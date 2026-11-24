<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Backfill;

use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\Data\Expression;
use JardisSupport\DbQuery\DbQuery;
use JardisSupport\Repository\Handler\QueryExecutor;

/**
 * Loads the next batch of records of a source table that hold no entry of the kind, in key order after the
 * last key seen. The first batch has no lower bound ($afterKey null), so the key column may be any integer type and
 * hold any key of its range. The names are validated by the caller.
 *
 * A page reads only its own key range, on both sides: the lower bound bounds the source rows AND the lookup of
 * existing entries. Bounding only the source leaves the correlated side unbounded; the database may then read every
 * entry of the kind written so far on each page, and the time per page grows with the progress of the run.
 */
final class FindRecordsWithoutEntry
{
    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly string $table,
    ) {
    }

    /**
     * @return list<array{key: int, creator: ?string, tenant: ?string}>
     */
    public function __invoke(
        int $kind,
        string $sourceTable,
        string $keyColumn,
        ?string $creatorColumn,
        ?string $tenantColumn,
        ?int $afterKey,
        int $batch,
    ): array {
        $fields = ['src.' . $keyColumn];
        if ($creatorColumn !== null) {
            $fields[] = 'src.' . $creatorColumn;
        }
        if ($tenantColumn !== null) {
            $fields[] = 'src.' . $tenantColumn;
        }

        $hasEntry = (new DbQuery())
            ->select('1')
            ->from($this->table)
            ->where('kind')->equals($kind)
            ->and('record_id')->equals(Expression::column('src.' . $keyColumn));
        if ($afterKey !== null) {
            $hasEntry->and('record_id')->greater($afterKey);
        }

        $query = (new DbQuery())
            ->select(implode(', ', $fields))
            ->from($sourceTable, 'src');
        if ($afterKey !== null) {
            $query->where('src.' . $keyColumn)->greater($afterKey);
        }

        $prepared = $query
            ->notExists($hasEntry)
            ->orderBy('src.' . $keyColumn)
            ->limit($batch)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        $records = [];
        foreach ($this->executor->fetchAll($prepared) as $row) {
            $records[] = [
                'key' => (int) $row[$keyColumn],
                'creator' => $creatorColumn !== null && $row[$creatorColumn] !== null
                    ? (string) $row[$creatorColumn]
                    : null,
                'tenant' => $tenantColumn !== null && $row[$tenantColumn] !== null
                    ? (string) $row[$tenantColumn]
                    : null,
            ];
        }

        return $records;
    }
}
