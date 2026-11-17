<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Filter;

use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Contract\DbQuery\DbQueryBuilderInterface;
use JardisSupport\DbQuery\Data\Expression;
use JardisSupport\DbQuery\DbQuery;

/**
 * Builds the single-value subquery that says whether the row of an outer query is visible: it yields 1
 * for a row of the kind whose key an entry of one of the groups holds at the minimum level or above, and
 * no value otherwise. The key column is bound to the outer row; a qualified name (alias.column) keeps it
 * apart from the entry columns.
 */
final class BuildRowCondition
{
    public function __construct(
        private readonly string $table,
    ) {
    }

    /**
     * @param list<int> $groups
     */
    public function __invoke(int $kind, array $groups, AccessLevel $min, string $keyColumn): DbQueryBuilderInterface
    {
        $query = (new DbQuery())->select('1')->from($this->table)->limit(1);
        $query
            ->where('kind')->equals($kind)
            ->and('record_id')->equals(Expression::column($keyColumn))
            ->and('group_id')->in($groups)
            ->and('level')->greaterEquals($min->value);

        return $query;
    }
}
