<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Integration;

use JardisSupport\Acl\Tests\Support\Connections;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;

/** AclBackfill against MySQL. */
final class AclBackfillMySqlTest extends AclBackfillTestCase
{
    protected function createConnection(): DbConnectionInterface
    {
        return Connections::mysql();
    }
}
