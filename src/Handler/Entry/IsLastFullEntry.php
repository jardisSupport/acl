<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\DbQuery;
use JardisSupport\Repository\Handler\QueryExecutor;

/** True when the group holds level "all" on the record and no other group does. */
final class IsLastFullEntry
{
    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly string $table,
    ) {
    }

    public function __invoke(int $kind, int $recordId, int $group): bool
    {
        $prepared = (new DbQuery())
            ->select('group_id')
            ->from($this->table)
            ->where('kind')->equals($kind)
            ->and('record_id')->equals($recordId)
            ->and('level')->equals(AccessLevel::All->value)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        $rows = $this->executor->fetchAll($prepared);

        return count($rows) === 1 && (int) $rows[0]['group_id'] === $group;
    }
}
