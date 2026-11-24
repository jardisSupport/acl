<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Support;

use PDO;
use PDOStatement;

/**
 * Test helper: a statement class that remembers the SQL and the bound values of every statement executed on the
 * connection it was installed on, so a test can look at the form of a query without a database specific log.
 */
final class RecordingStatement extends PDOStatement
{
    /** @var list<array{sql: string, bindings: list<mixed>}> */
    private static array $executed = [];

    /** @var array<int|string, mixed> */
    private array $bound = [];

    protected function __construct()
    {
    }

    /** Starts recording on the connection; forgets what was recorded before. */
    public static function install(PDO $pdo): void
    {
        self::$executed = [];
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [self::class]);
    }

    /** Stops recording on the connection. */
    public static function uninstall(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
    }

    /**
     * The statements executed since install(), in order, that read from the given table.
     *
     * @return list<array{sql: string, bindings: list<mixed>}>
     */
    public static function selectsFrom(string $table): array
    {
        return array_values(array_filter(
            self::$executed,
            static fn (array $statement): bool => str_starts_with($statement['sql'], 'SELECT')
                && str_contains($statement['sql'], $table),
        ));
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bound[$param] = $value;

        return parent::bindValue($param, $value, $type);
    }

    public function execute(?array $params = null): bool
    {
        self::$executed[] = [
            'sql' => $this->queryString,
            'bindings' => array_values($params ?? $this->bound),
        ];
        $this->bound = [];

        return parent::execute($params);
    }
}
