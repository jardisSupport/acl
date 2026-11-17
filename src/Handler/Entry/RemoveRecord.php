<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\DbDelete;
use JardisSupport\Repository\Handler\QueryExecutor;

/** Removes every entry of one record and returns how many were removed. */
final class RemoveRecord
{
    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly string $table,
    ) {
    }

    public function __invoke(int $kind, int $recordId): int
    {
        $prepared = (new DbDelete())
            ->from($this->table)
            ->where('kind')->equals($kind)
            ->and('record_id')->equals($recordId)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        return $this->executor->execute($prepared)->rowCount();
    }
}
