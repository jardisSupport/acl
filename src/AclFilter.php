<?php

declare(strict_types=1);

namespace JardisSupport\Acl;

use Closure;
use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Handler\Filter\BuildExistsQuery;
use JardisSupport\Acl\Handler\Table\AssertTableName;
use JardisSupport\Contract\DbQuery\DbQueryBuilderInterface;

/**
 * Builds the dbquery subquery that restricts a query of a record table to the records a person may see.
 *
 * It needs no connection and runs nothing: it only builds. A record is visible when an entry of the
 * information kind exists for it, held by one of the person's groups at the minimum level or above; an
 * empty group list sees nothing. The set is the same as AclStore::levelOf gives.
 *
 * Since jardissupport/dbquery 1.3.1 a subquery value is inlined in every condition of the query, so the filter
 * condition may stand at any position, behind other conditions (e.g. a tenant column) as well as first.
 */
final class AclFilter
{
    /**
     * Fixed limit of the capped total of a filtered list.
     *
     * A caller that counts a filtered list counts it capped: inside `SELECT 1` with the conditions of the page
     * (including `exists(existsQuery(...))`), without column list, without sorting, `limit(COUNT_CAP + 1)`;
     * outside `COUNT(*)`. The total of a filtered list is at most COUNT_CAP; when the count exceeds it, the
     * answer reports COUNT_CAP and carries a marker that the total is capped.
     */
    public const int COUNT_CAP = 10000;

    private readonly Closure $buildExistsQuery;

    public function __construct(string $table = 'acl_entries')
    {
        $table = (new AssertTableName())->__invoke($table);

        $this->buildExistsQuery = (new BuildExistsQuery($table))->__invoke(...);
    }

    /**
     * Subquery for `$query->exists($filter->existsQuery(...))`: `SELECT 1 FROM <table> WHERE kind = ? AND
     * record_id = <keyColumn of the outer row> AND group_id IN (...) AND level >= ?`.
     *
     * Name the key column with the alias of the outer table (`alias.id`) when its bare name also exists in
     * the entry table (kind, record_id, group_id, level).
     *
     * @param list<int> $groups
     */
    public function existsQuery(
        int $kind,
        array $groups,
        AccessLevel $min,
        string $keyColumn,
    ): DbQueryBuilderInterface {
        return ($this->buildExistsQuery)($kind, $groups, $min, $keyColumn);
    }
}
