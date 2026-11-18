<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Filter;

use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Contract\DbQuery\DbQueryBuilderInterface;
use JardisSupport\DbQuery\Data\Expression;
use JardisSupport\DbQuery\DbQuery;

/**
 * Builds the subquery for `exists()` that says whether the row of an outer query is visible: a row of the kind
 * whose key an entry of one of the groups holds at the minimum level or above. The key column is bound to the
 * outer row; a qualified name (alias.column) keeps it apart from the entry columns. No limit and no distinct:
 * `EXISTS` stops at the first hit by itself.
 */
final class BuildExistsQuery
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
        $query = (new DbQuery())->select('1')->from($this->table);
        $query
            ->where('kind')->equals($kind)
            ->and('record_id')->equals(Expression::column($keyColumn))
            ->and('group_id')->in($groups)
            ->and('level')->greaterEquals($min->value);

        return $query;
    }
}
