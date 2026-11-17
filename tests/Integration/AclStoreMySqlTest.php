<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Integration;

use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;

/** AclStore against the real MySQL service of support/docker-compose.yml. */
final class AclStoreMySqlTest extends AclStoreTestCase
{
    protected function createConnection(): DbConnectionInterface
    {
        return (new ConnectionFactory())->mysql(
            host: (string) getenv('MYSQL_HOST'),
            user: (string) getenv('MYSQL_USER'),
            password: (string) getenv('MYSQL_PASSWORD'),
            database: (string) getenv('MYSQL_DATABASE'),
            port: (int) getenv('MYSQL_PORT'),
        );
    }
}
