<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use Closure;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\DbQuery;
use JardisSupport\Repository\Handler\QueryExecutor;

/**
 * Moves every entry of one group to another group and returns how many entries left the source group.
 *
 * Works in batches so a large group never sits in memory at once. Runs several statements and opens
 * no transaction of its own; the caller brackets the call.
 */
final class ReassignGroup
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly Closure $moveEntries,
        private readonly string $table,
    ) {
    }

    public function __invoke(int $from, int $to): int
    {
        if ($from === $to) {
            return 0;
        }

        $moved = 0;
        while (($rows = $this->nextBatch($from)) !== []) {
            ($this->moveEntries)($from, $to, $rows);
            $moved += count($rows);
        }

        return $moved;
    }

    /**
     * @return list<array{kind: int, record_id: int, level: int}>
     */
    private function nextBatch(int $from): array
    {
        $prepared = (new DbQuery())
            ->select('kind, record_id, level')
            ->from($this->table)
            ->where('group_id')->equals($from)
            ->orderBy('kind')
            ->orderBy('record_id')
            ->limit(self::BATCH_SIZE)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        $rows = [];
        foreach ($this->executor->fetchAll($prepared) as $row) {
            $rows[] = [
                'kind' => (int) $row['kind'],
                'record_id' => (int) $row['record_id'],
                'level' => (int) $row['level'],
            ];
        }

        return $rows;
    }
}
