<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\DbDelete;
use JardisSupport\Repository\Handler\QueryExecutor;

/** Removes the entry of one group on one record; a group without an entry is a no-op. */
final class RevokeEntry
{
    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly string $table,
    ) {
    }

    public function __invoke(int $kind, int $recordId, int $group): void
    {
        $prepared = (new DbDelete())
            ->from($this->table)
            ->where('kind')->equals($kind)
            ->and('record_id')->equals($recordId)
            ->and('group_id')->equals($group)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        $this->executor->execute($prepared);
    }
}
