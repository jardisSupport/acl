<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\DbInsert;
use JardisSupport\Repository\Handler\QueryExecutor;

/** Sets one group to one level on several records of a kind: inserts missing rows, overwrites existing ones. */
final class UpsertEntries
{
    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly string $table,
    ) {
    }

    /**
     * @param list<int> $recordIds
     */
    public function __invoke(int $kind, int $group, AccessLevel $level, array $recordIds): void
    {
        if ($recordIds === []) {
            return;
        }

        $insert = (new DbInsert())
            ->into($this->table)
            ->fields('kind', 'record_id', 'group_id', 'level');
        foreach ($recordIds as $recordId) {
            $insert->values($kind, $recordId, $group, $level->value);
        }

        $prepared = $insert
            ->onConflict('kind', 'record_id', 'group_id')
            ->doUpdate(['level' => $level->value])
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        $this->executor->execute($prepared);
    }
}
