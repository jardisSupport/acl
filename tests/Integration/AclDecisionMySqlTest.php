<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Integration;

use JardisSupport\Acl\Tests\Support\Connections;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;

/** check, grantAll and hasEntriesOfGroup against MySql. */
final class AclDecisionMySqlTest extends AclDecisionTestCase
{
    protected function createConnection(): DbConnectionInterface
    {
        return Connections::mysql();
    }
}
