<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Integration;

use Closure;
use JardisSupport\Acl\AclBackfill;
use JardisSupport\Acl\AclFilter;
use JardisSupport\Acl\AclStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionNamedType;

/** Closure-orchestrator structure: one closure per public method, handlers with one entry point, no SQL text. */
final class StructureTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function orchestrators(): array
    {
        return [
            'AclStore' => [AclStore::class],
            'AclFilter' => [AclFilter::class],
            'AclBackfill' => [AclBackfill::class],
        ];
    }

    /**
     * @param class-string $orchestrator
     */
    #[DataProvider('orchestrators')]
    public function testEveryPublicMethodOfTheOrchestratorCallsExactlyOneClosure(string $orchestrator): void
    {
        $class = new ReflectionClass($orchestrator);
        $closureProperties = [];
        foreach ($class->getProperties() as $property) {
            $type = $property->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === Closure::class) {
                $closureProperties[] = $property->getName();
            }
        }
        $lines = (array) file((string) $class->getFileName());

        $methods = array_filter(
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $m): bool => !$m->isConstructor(),
        );
        self::assertNotEmpty($methods);

        foreach ($methods as $method) {
            $body = implode('', array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));

            self::assertSame(
                1,
                preg_match_all('/\(\$this->(\w+)\)\(/', $body, $calls),
                $method->getName() . ' must call exactly one closure',
            );
            self::assertContains($calls[1][0], $closureProperties, $method->getName());
            self::assertSame(
                0,
                preg_match('/\b(if|foreach|for|while|switch|match|\?\?)\b|\bnew\b/', $body),
                $method->getName() . ' must not carry logic of its own',
            );
        }
    }

    /**
     * @return array<string, array{0: class-string, 1: list<string>}>
     */
    public static function contracts(): array
    {
        return [
            'AclStore' => [
                AclStore::class,
                [
                    'check',
                    'entriesOf',
                    'grant',
                    'grantAll',
                    'hasEntriesOfGroup',
                    'isLastFullEntry',
                    'levelOf',
                    'reassignGroup',
                    'removeRecord',
                    'revoke',
                ],
            ],
            'AclFilter' => [AclFilter::class, ['recordIds', 'rowCondition']],
            'AclBackfill' => [AclBackfill::class, ['run']],
        ];
    }

    /**
     * @param class-string $orchestrator
     * @param list<string> $expected
     */
    #[DataProvider('contracts')]
    public function testTheOrchestratorOffersTheMethodsOfTheContractAndNothingElse(
        string $orchestrator,
        array $expected,
    ): void {
        $names = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            array_filter(
                (new ReflectionClass($orchestrator))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $m): bool => !$m->isConstructor(),
            ),
        );
        sort($names);

        self::assertSame($expected, $names);
    }

    public function testTheCountCapOfAFilteredListIsAFixedPublicIntegerOf10000(): void
    {
        $constant = new ReflectionClassConstant(AclFilter::class, 'COUNT_CAP');

        self::assertTrue($constant->isPublic());
        self::assertSame(10000, $constant->getValue());
        self::assertSame('int', (string) $constant->getType());
    }

    /**
     * @param class-string $orchestrator
     */
    #[DataProvider('orchestrators')]
    public function testEveryClosurePropertyOfTheOrchestratorIsUsedByAMethod(string $orchestrator): void
    {
        $class = new ReflectionClass($orchestrator);
        $source = implode('', (array) file((string) $class->getFileName()));

        foreach ($class->getProperties() as $property) {
            $type = $property->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === Closure::class) {
                self::assertStringContainsString('($this->' . $property->getName() . ')(', $source);
            }
        }
    }

    public function testEveryHandlerHasExactlyOnePublicEntryPoint(): void
    {
        $handlers = [];
        foreach ($this->phpFiles(self::SRC . '/Handler') as $file) {
            $handlers[] = $file;
        }
        self::assertNotEmpty($handlers);

        foreach ($handlers as $file) {
            $fqcn = 'JardisSupport\\Acl\\' . str_replace(
                '/',
                '\\',
                substr((string) realpath($file), strlen((string) realpath(self::SRC)) + 1, -4),
            );
            $public = array_map(
                static fn (ReflectionMethod $m): string => $m->getName(),
                array_filter(
                    (new ReflectionClass($fqcn))->getMethods(ReflectionMethod::IS_PUBLIC),
                    static fn (ReflectionMethod $m): bool => !$m->isConstructor(),
                ),
            );

            self::assertSame(['__invoke'], array_values($public), $fqcn);
        }
    }

    /**
     * @return list<string>
     */
    private function dialectTestCases(): array
    {
        $cases = glob(__DIR__ . '/*TestCase.php');

        return array_map(static fn (string $file): string => basename($file, 'TestCase.php'), (array) $cases);
    }

    public function testEveryDatabaseTestCaseHasOneClassPerDialect(): void
    {
        $prefixes = $this->dialectTestCases();
        self::assertContains('AclDecision', $prefixes);
        self::assertContains('AclStore', $prefixes);
        self::assertContains('AclFilter', $prefixes);
        self::assertContains('AclBackfill', $prefixes);

        foreach ($prefixes as $prefix) {
            foreach (['MySql', 'MariaDb', 'Postgres', 'Sqlite'] as $dialect) {
                $file = __DIR__ . '/' . $prefix . $dialect . 'Test.php';
                self::assertFileExists($file, $prefix . ' lacks the ' . $dialect . ' class');
                self::assertStringContainsString(
                    'extends ' . $prefix . 'TestCase',
                    (string) file_get_contents($file),
                    $file,
                );
            }
        }
    }

    public function testNoSqlTextUnderSrc(): void
    {
        $sql = '/\b(SELECT\b.+\bFROM|INSERT\s+INTO|UPDATE\s+\S+\s+SET|DELETE\s+FROM|CREATE\s+TABLE|'
            . 'ON\s+CONFLICT|ON\s+DUPLICATE|GREATEST)\b/is';

        foreach ($this->phpFiles(self::SRC) as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    self::assertSame(0, preg_match($sql, $token[1]), $file . ': SQL text ' . $token[1]);
                }
            }
        }
    }

    public function testNoRawExpressionUnderSrc(): void
    {
        foreach ($this->phpFiles(self::SRC) as $file) {
            self::assertStringNotContainsString('Expression::raw', (string) file_get_contents($file), $file);
            self::assertStringNotContainsString('->exec(', (string) file_get_contents($file), $file);
        }
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
