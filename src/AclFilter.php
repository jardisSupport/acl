<?php

declare(strict_types=1);

namespace JardisSupport\Acl;

use Closure;
use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Handler\Filter\BuildRecordIds;
use JardisSupport\Acl\Handler\Filter\BuildRowCondition;
use JardisSupport\Acl\Handler\Table\AssertTableName;
use JardisSupport\Contract\DbQuery\DbQueryBuilderInterface;

/**
 * Builds the dbquery objects that restrict a query of a record table to the records a person may see.
 *
 * It needs no connection and runs nothing: it only builds. A record is visible when an entry of the
 * information kind exists for it, held by one of the person's groups at the minimum level or above; an
 * empty group list sees nothing. Both forms give the same set as AclStore::levelOf.
 *
 * Since jardissupport/dbquery 1.3.1 a subquery value is inlined in every condition of the query, so the filter
 * condition may stand at any position, behind other conditions (e.g. a tenant column) as well as first.
 */
final class AclFilter
{
    private readonly Closure $buildRowCondition;
    private readonly Closure $buildRecordIds;

    public function __construct(string $table = 'acl_entries')
    {
        $table = (new AssertTableName())->__invoke($table);

        $this->buildRowCondition = (new BuildRowCondition($table))->__invoke(...);
        $this->buildRecordIds = (new BuildRecordIds($table))->__invoke(...);
    }

    /**
     * Single-value subquery bound to the key column of the outer row, for
     * `where(Expression::integer(1))->equals($filter->rowCondition(...))`.
     *
     * Name the key column with the alias of the outer table (`alias.id`) when its bare name also exists in
     * the entry table (kind, record_id, group_id, level).
     *
     * @param list<int> $groups
     */
    public function rowCondition(
        int $kind,
        array $groups,
        AccessLevel $min,
        string $keyColumn,
    ): DbQueryBuilderInterface {
        return ($this->buildRowCondition)($kind, $groups, $min, $keyColumn);
    }

    /**
     * Key set for `where($keyColumn)->in($filter->recordIds(...))`; each record number once.
     *
     * @param list<int> $groups
     */
    public function recordIds(int $kind, array $groups, AccessLevel $min): DbQueryBuilderInterface
    {
        return ($this->buildRecordIds)($kind, $groups, $min);
    }
}
