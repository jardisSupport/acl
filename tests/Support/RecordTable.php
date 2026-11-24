<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Support;

use JardisSupport\Contract\DbConnection\DbConnectionInterface;

/** Test helper: a business table of the records the entries protect (the "Fachtabelle"). */
final class RecordTable
{
    /** Table of documents: id (the key an entry refers to) and a tenant column (ids 1-10 north, the rest south). */
    public static function documents(DbConnectionInterface $connection, int $count): void
    {
        $pdo = $connection->pdo();
        $pdo->exec('DROP TABLE IF EXISTS acl_docs');
        $pdo->exec('CREATE TABLE acl_docs (id BIGINT NOT NULL PRIMARY KEY, title VARCHAR(40) NOT NULL, tenant VARCHAR(10) NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO acl_docs (id, title, tenant) VALUES (?, ?, ?)');
        for ($id = 1; $id <= $count; $id++) {
            $insert->execute([$id, 'doc ' . $id, $id <= 10 ? 'north' : 'south']);
        }
    }

    /**
     * Table of existing records with creator and tenant, for the backfill.
     *
     * @param list<array{0: int, 1: ?string, 2: ?string}> $rows id, creator, tenant
     * @param string $table acl_src, or acl_src2 for a second source table
     * @param string $keyType integer type of the key column: SMALLINT, INTEGER or BIGINT
     */
    public static function sources(
        DbConnectionInterface $connection,
        array $rows,
        string $table = 'acl_src',
        string $keyType = 'BIGINT',
    ): void {
        self::assertSourceName($table);
        if (!in_array($keyType, ['SMALLINT', 'INTEGER', 'BIGINT'], true)) {
            throw new \InvalidArgumentException('Unknown key type ' . $keyType);
        }
        $pdo = $connection->pdo();
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        $pdo->exec(
            'CREATE TABLE ' . $table . ' (id ' . $keyType . ' NOT NULL PRIMARY KEY, created_by VARCHAR(40) NULL, '
            . 'tenant VARCHAR(40) NULL)'
        );
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO ' . $table . ' (id, created_by, tenant) VALUES (?, ?, ?)');
        foreach ($rows as $row) {
            $insert->execute($row);
        }
        $pdo->commit();
    }

    public static function drop(DbConnectionInterface $connection): void
    {
        $connection->pdo()->exec('DROP TABLE IF EXISTS acl_docs');
        $connection->pdo()->exec('DROP TABLE IF EXISTS acl_src');
        $connection->pdo()->exec('DROP TABLE IF EXISTS acl_src2');
    }

    private static function assertSourceName(string $table): void
    {
        if (!in_array($table, ['acl_src', 'acl_src2'], true)) {
            throw new \InvalidArgumentException('Unknown source table ' . $table);
        }
    }
}
