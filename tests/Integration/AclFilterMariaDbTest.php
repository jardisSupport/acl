<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Integration;

use JardisSupport\Acl\Tests\Support\Connections;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;

/** AclFilter against MariaDB. */
final class AclFilterMariaDbTest extends AclFilterTestCase
{
    protected function createConnection(): DbConnectionInterface
    {
        return Connections::mariadb();
    }
}
