<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Filter;

use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Contract\DbQuery\DbQueryBuilderInterface;
use JardisSupport\DbQuery\DbQuery;

/** Builds the query for the numbers of the records of a kind that a group holds at the minimum level or above. */
final class BuildRecordIds
{
    public function __construct(
        private readonly string $table,
    ) {
    }

    /**
     * @param list<int> $groups
     */
    public function __invoke(int $kind, array $groups, AccessLevel $min): DbQueryBuilderInterface
    {
        $query = (new DbQuery())->select('record_id')->distinct()->from($this->table);
        $query
            ->where('kind')->equals($kind)
            ->and('group_id')->in($groups)
            ->and('level')->greaterEquals($min->value);

        return $query;
    }
}
