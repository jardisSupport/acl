<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\DbQuery;
use JardisSupport\Repository\Handler\QueryExecutor;

/** True when at least one entry, of any information kind, carries the group. */
final class HasEntriesOfGroup
{
    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly string $table,
    ) {
    }

    public function __invoke(int $group): bool
    {
        $prepared = (new DbQuery())
            ->select('group_id')
            ->from($this->table)
            ->where('group_id')->equals($group)
            ->limit(1)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        return $this->executor->fetchOne($prepared) !== null;
    }
}
