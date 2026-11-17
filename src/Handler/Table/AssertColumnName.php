<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Table;

use InvalidArgumentException;

/** Guards a column name handed in by the caller: a plain, unqualified identifier. */
final class AssertColumnName
{
    public function __invoke(string $column): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid column name "%s"', $column));
        }

        return $column;
    }
}
