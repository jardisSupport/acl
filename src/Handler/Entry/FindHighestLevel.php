<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\DbQuery;
use JardisSupport\Repository\Handler\QueryExecutor;

/** Highest level any of the given groups holds on a record, or null when none holds an entry. */
final class FindHighestLevel
{
    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly string $table,
    ) {
    }

    /**
     * @param list<int> $groups
     */
    public function __invoke(int $kind, int $recordId, array $groups): ?AccessLevel
    {
        if ($groups === []) {
            return null;
        }

        $prepared = (new DbQuery())
            ->select('level')
            ->from($this->table)
            ->where('kind')->equals($kind)
            ->and('record_id')->equals($recordId)
            ->and('group_id')->in($groups)
            ->orderBy('level', 'DESC')
            ->limit(1)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        $row = $this->executor->fetchOne($prepared);

        return $row === null ? null : AccessLevel::from((int) $row['level']);
    }
}
