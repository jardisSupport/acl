<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\DbDelete;
use JardisSupport\Repository\Handler\QueryExecutor;

/**
 * Removes the entries of several groups on one record. An empty list writes nothing; a group without an entry or named
 * twice is harmless. One delete per batch of 500 groups, no transaction.
 */
final class RevokeEntries
{
    private const BATCH = 500;

    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly string $table,
    ) {
    }

    /**
     * @param list<int> $groups
     */
    public function __invoke(int $kind, int $recordId, array $groups): void
    {
        foreach (array_chunk(array_values(array_unique($groups)), self::BATCH) as $batch) {
            $prepared = (new DbDelete())
                ->from($this->table)
                ->where('kind')->equals($kind)
                ->and('record_id')->equals($recordId)
                ->and('group_id')->in($batch)
                ->sql($this->executor->getDialect(), prepared: true);
            \assert($prepared instanceof DbPreparedQueryInterface);

            $this->executor->execute($prepared);
        }
    }
}
