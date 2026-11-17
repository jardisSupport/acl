<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Support;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;

/** Test helper: (re)creates the shipped acl_entries table from resources/sql/<driver>.sql. */
final class AclTable
{
    public static function recreate(DbConnectionInterface $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS acl_entries');
        self::create($connection);
    }

    public static function create(DbConnectionInterface $connection): void
    {
        $file = dirname(__DIR__, 2) . '/resources/sql/' . $connection->getDriverName() . '.sql';

        $connection->pdo()->exec((string) file_get_contents($file));
    }
}
