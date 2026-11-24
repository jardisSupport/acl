<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\DbQuery;
use JardisSupport\Repository\Handler\QueryExecutor;

/** The groups that hold level "all" on one record of one information kind. */
final class FindFullEntryGroups
{
    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly string $table,
    ) {
    }

    /**
     * @return list<int>
     */
    public function __invoke(int $kind, int $recordId): array
    {
        $prepared = (new DbQuery())
            ->select('group_id')
            ->from($this->table)
            ->where('kind')->equals($kind)
            ->and('record_id')->equals($recordId)
            ->and('level')->equals(AccessLevel::All->value)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        $groups = [];
        foreach ($this->executor->fetchAll($prepared) as $row) {
            $groups[] = (int) $row['group_id'];
        }

        return $groups;
    }
}
