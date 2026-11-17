<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Support;

use JardisAdapter\DbConnection\Factory\ConnectionFactory;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;

/** Test helper: the connection of each of the four dialects (services from support/docker-compose.yml). */
final class Connections
{
    public static function mysql(): DbConnectionInterface
    {
        return self::mysqlFamily('MYSQL');
    }

    public static function mariadb(): DbConnectionInterface
    {
        return self::mysqlFamily('MARIADB');
    }

    public static function postgres(): DbConnectionInterface
    {
        return (new ConnectionFactory())->postgres(
            host: (string) getenv('POSTGRES_HOST'),
            user: (string) getenv('POSTGRES_USER'),
            password: (string) getenv('POSTGRES_PASSWORD'),
            database: (string) getenv('POSTGRES_DATABASE'),
            port: (int) getenv('POSTGRES_PORT'),
        );
    }

    public static function sqlite(): DbConnectionInterface
    {
        return (new ConnectionFactory())->sqlite();
    }

    private static function mysqlFamily(string $prefix): DbConnectionInterface
    {
        return (new ConnectionFactory())->mysql(
            host: (string) getenv($prefix . '_HOST'),
            user: (string) getenv($prefix . '_USER'),
            password: (string) getenv($prefix . '_PASSWORD'),
            database: (string) getenv($prefix . '_DATABASE'),
            port: (int) getenv($prefix . '_PORT'),
        );
    }
}
