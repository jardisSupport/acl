<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Integration;

use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;

/** AclStore against the real PostgreSQL service of support/docker-compose.yml. */
final class AclStorePostgresTest extends AclStoreTestCase
{
    protected function createConnection(): DbConnectionInterface
    {
        return (new ConnectionFactory())->postgres(
            host: (string) getenv('POSTGRES_HOST'),
            user: (string) getenv('POSTGRES_USER'),
            password: (string) getenv('POSTGRES_PASSWORD'),
            database: (string) getenv('POSTGRES_DATABASE'),
            port: (int) getenv('POSTGRES_PORT'),
        );
    }
}
