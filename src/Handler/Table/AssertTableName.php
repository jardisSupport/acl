<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Table;

use InvalidArgumentException;

/** Guards a configurable table name: a plain identifier, optionally schema-qualified. */
final class AssertTableName
{
    public function __invoke(string $table, string $what = 'ACL table'): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid %s name "%s"', $what, $table));
        }

        return $table;
    }
}
