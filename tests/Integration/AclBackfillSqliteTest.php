<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Integration;

use JardisSupport\Acl\Tests\Support\Connections;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;

/** AclBackfill against SQLite. */
final class AclBackfillSqliteTest extends AclBackfillTestCase
{
    protected function createConnection(): DbConnectionInterface
    {
        return Connections::sqlite();
    }
}
