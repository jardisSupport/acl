<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Integration;

use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;

/** AclStore against in-memory SQLite (fourth dialect, no service needed). */
final class AclStoreSqliteTest extends AclStoreTestCase
{
    protected function createConnection(): DbConnectionInterface
    {
        return (new ConnectionFactory())->sqlite();
    }
}
