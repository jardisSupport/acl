<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Data\Entry;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\DbQuery;
use JardisSupport\Repository\Handler\QueryExecutor;

/** Loads every entry of one record, ordered by group. */
final class FindEntries
{
    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly string $table,
    ) {
    }

    /**
     * @return list<Entry>
     */
    public function __invoke(int $kind, int $recordId): array
    {
        $prepared = (new DbQuery())
            ->select('group_id, level')
            ->from($this->table)
            ->where('kind')->equals($kind)
            ->and('record_id')->equals($recordId)
            ->orderBy('group_id')
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        $entries = [];
        foreach ($this->executor->fetchAll($prepared) as $row) {
            $entries[] = new Entry((int) $row['group_id'], AccessLevel::from((int) $row['level']));
        }

        return $entries;
    }
}
