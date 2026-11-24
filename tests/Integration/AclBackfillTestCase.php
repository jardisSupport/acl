<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Integration;

use Closure;
use InvalidArgumentException;
use JardisSupport\Acl\AclBackfill;
use JardisSupport\Acl\AclStore;
use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Data\BackfillResult;
use JardisSupport\Acl\Data\Entry;
use JardisSupport\Acl\Exception\BackfillUnresolved;
use JardisSupport\Acl\Tests\Support\AclTable;
use JardisSupport\Acl\Tests\Support\RecordingStatement;
use JardisSupport\Acl\Tests\Support\RecordTable;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** AclBackfill against a real database (one subclass per dialect). */
abstract class AclBackfillTestCase extends TestCase
{
    private const KIND_A = 1;
    private const KIND_B = 2;
    private const FALLBACK = 500;

    protected DbConnectionInterface $connection;
    private AclStore $store;
    private AclBackfill $backfill;

    /** @var list<array{0: ?string, 1: list<string>}> calls of the creator closure: tenant, creators */
    private array $calls = [];

    abstract protected function createConnection(): DbConnectionInterface;

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
        AclTable::recreate($this->connection);
        $this->store = new AclStore($this->connection);
        $this->backfill = new AclBackfill($this->connection);
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        if ($this->connection->pdo()->inTransaction()) {
            $this->connection->pdo()->rollBack();
        }
        RecordTable::drop($this->connection);
    }

    /** The groups of two tenants: u1 and u2 in t1, only u1 in t2. */
    private function groupOfCreator(): Closure
    {
        return function (?string $tenant, array $creators): array {
            $this->calls[] = [$tenant, $creators];
            $groups = ['t1' => ['u1' => 101, 'u2' => 102], 't2' => ['u1' => 201]][$tenant] ?? [];

            return array_intersect_key($groups, array_flip($creators));
        };
    }

    /**
     * @return array<int, int> level value by group
     */
    private function levels(int $kind, int $recordId): array
    {
        $levels = [];
        foreach ($this->store->entriesOf($kind, $recordId) as $entry) {
            $levels[$entry->group] = $entry->level->value;
        }

        return $levels;
    }

    private function runBackfill(?int $fallback = self::FALLBACK, int $batch = 1000): BackfillResult
    {
        return $this->backfill->run(
            self::KIND_A,
            'acl_src',
            'id',
            'created_by',
            'tenant',
            $this->groupOfCreator(),
            $fallback,
            $batch,
        );
    }

    /** Six records: two creators per tenant, no creator, an unknown creator, one with an entry already. */
    private function sixRecords(): void
    {
        RecordTable::sources($this->connection, [
            [1, 'u1', 't1'],
            [2, 'u1', 't2'],
            [3, null, 't1'],
            [4, 'ghost', 't1'],
            [5, 'u2', 't1'],
            [6, 'u1', 't1'],
        ]);
        $this->store->grant(self::KIND_A, 6, 99, AccessLevel::View);
    }

    // AK5 - creator group per tenant, fallback, existing entries stay, second run writes nothing, skipped

    public function testRecordWithoutEntryGetsAllForTheGroupOfItsCreatorPerTenant(): void
    {
        $this->sixRecords();

        $this->runBackfill();

        self::assertSame([101 => 3], $this->levels(self::KIND_A, 1));
        self::assertSame([201 => 3], $this->levels(self::KIND_A, 2));
        self::assertSame([102 => 3], $this->levels(self::KIND_A, 5));
    }

    public function testRecordWithoutCreatorOrWithAnUnknownCreatorGoesToTheFallbackGroup(): void
    {
        $this->sixRecords();

        $result = $this->runBackfill();

        self::assertSame([self::FALLBACK => 3], $this->levels(self::KIND_A, 3));
        self::assertSame([self::FALLBACK => 3], $this->levels(self::KIND_A, 4));
        self::assertSame(5, $result->written());
        self::assertSame(2, $result->toFallback());
        self::assertSame(0, $result->skipped());
    }

    public function testExistingEntriesStayAndTheRecordIsNotTouched(): void
    {
        $this->sixRecords();

        $this->runBackfill();

        self::assertSame([99 => 1], $this->levels(self::KIND_A, 6));
    }

    public function testSecondRunWritesNothing(): void
    {
        $this->sixRecords();
        $this->runBackfill();
        $this->calls = [];

        $second = $this->runBackfill();

        self::assertSame(0, $second->written());
        self::assertSame(0, $second->toFallback());
        self::assertSame(0, $second->skipped());
        self::assertSame([], $this->calls);
        self::assertSame([101 => 3], $this->levels(self::KIND_A, 1));
    }

    public function testWithoutFallbackGroupRecordsWithoutAGroupAreSkippedAndStayWithoutEntry(): void
    {
        $this->sixRecords();

        $result = $this->runBackfill(null);

        self::assertSame(3, $result->written());
        self::assertSame(0, $result->toFallback());
        self::assertSame(2, $result->skipped());
        self::assertSame([], $this->levels(self::KIND_A, 3));
        self::assertSame([], $this->levels(self::KIND_A, 4));
        self::assertSame([101 => 3], $this->levels(self::KIND_A, 1));

        $again = $this->runBackfill(null);

        self::assertSame(0, $again->written());
        self::assertSame(2, $again->skipped());
    }

    public function testSkippedRecordsDoNotStopTheRunAcrossBatches(): void
    {
        $rows = [];
        for ($id = 1; $id <= 10; $id++) {
            $rows[] = [$id, $id % 2 === 0 ? null : 'u1', 't1'];
        }
        RecordTable::sources($this->connection, $rows);

        foreach ([1, 2, 3, 10, 50] as $batch) {
            AclTable::recreate($this->connection);
            $result = $this->runBackfill(null, $batch);

            self::assertSame(5, $result->written(), 'batch ' . $batch);
            self::assertSame(5, $result->skipped(), 'batch ' . $batch);
            foreach ([1, 3, 5, 7, 9] as $id) {
                self::assertSame([101 => 3], $this->levels(self::KIND_A, $id), "batch $batch record $id");
            }
            foreach ([2, 4, 6, 8, 10] as $id) {
                self::assertSame([], $this->levels(self::KIND_A, $id), "batch $batch record $id");
            }
        }
    }

    /**
     * Every page reads only its own key range, on both sides: the lookup of existing entries carries the lower
     * bound of the page too, so the time per page does not grow with the entries already written.
     */
    public function testEveryPageAfterTheFirstBoundsTheEntryLookupByTheSameLowerKey(): void
    {
        $rows = [];
        for ($id = 101; $id <= 105; $id++) {
            $rows[] = [$id, null, null];
        }
        RecordTable::sources($this->connection, $rows);
        $pdo = $this->connection->pdo();

        RecordingStatement::install($pdo);
        $result = $this->runBackfill(self::FALLBACK, 2);
        $pages = RecordingStatement::selectsFrom('acl_src');
        RecordingStatement::uninstall($pdo);

        self::assertSame(5, $result->written());
        self::assertCount(3, $pages);
        // page 1 has no lower bound: the kind is its only bound value
        self::assertSame([self::KIND_A], $pages[0]['bindings']);
        // page 2 and 3: the last key of the page before bounds the records AND the entry lookup
        foreach ([1 => 102, 2 => 104] as $page => $afterKey) {
            self::assertSame(
                2,
                count(array_keys($pages[$page]['bindings'], $afterKey, true)),
                'page ' . ($page + 1) . ' binds the lower key ' . $afterKey . ' on both sides',
            );
            self::assertMatchesRegularExpression(
                '/NOT EXISTS\s*\(.*record_id\W+\s*>\s*\?/is',
                $pages[$page]['sql'],
                'page ' . ($page + 1) . ': the entry lookup carries the lower bound',
            );
        }
    }

    public function testMoreRecordsThanTheDefaultBatchAreAllBackfilled(): void
    {
        $rows = [];
        for ($id = 1; $id <= 2503; $id++) {
            $rows[] = [$id, null, null];
        }
        RecordTable::sources($this->connection, $rows);

        $result = $this->backfill->run(
            self::KIND_A,
            'acl_src',
            'id',
            null,
            null,
            $this->groupOfCreator(),
            self::FALLBACK,
        );

        self::assertSame(2503, $result->written());
        self::assertSame(2503, $result->toFallback());
        self::assertSame([self::FALLBACK => 3], $this->levels(self::KIND_A, 1));
        self::assertSame([self::FALLBACK => 3], $this->levels(self::KIND_A, 2503));
        self::assertSame([], $this->calls);
    }

    /**
     * The first page has no lower bound: the key column may be any integer type, hold negative keys and 0, and
     * reach the ends of its range.
     *
     * @return array<string, array{0: string, 1: int, 2: int}> key type, smallest and largest key of its range
     */
    public static function keyTypes(): array
    {
        return [
            'SMALLINT' => ['SMALLINT', -32768, 32767],
            'INTEGER' => ['INTEGER', -2147483648, 2147483647],
            'BIGINT' => ['BIGINT', PHP_INT_MIN, PHP_INT_MAX],
        ];
    }

    #[DataProvider('keyTypes')]
    public function testKeysOfAnyIntegerTypeIncludingNegativeZeroAndTheRangeEndsAreAllBackfilled(
        string $keyType,
        int $min,
        int $max,
    ): void {
        $keys = [$min, -7, -1, 0, 1, 2, 9, $max];
        RecordTable::sources(
            $this->connection,
            array_map(static fn (int $key): array => [$key, null, null], $keys),
            'acl_src',
            $keyType,
        );

        foreach ([1, 3, 100] as $batch) {
            AclTable::recreate($this->connection);
            $result = $this->runBackfill(self::FALLBACK, $batch);

            self::assertSame(count($keys), $result->written(), "$keyType batch $batch");
            foreach ($keys as $key) {
                self::assertSame(
                    [self::FALLBACK => 3],
                    $this->levels(self::KIND_A, $key),
                    "$keyType batch $batch key $key",
                );
            }
        }
    }

    #[DataProvider('keyTypes')]
    public function testSecondRunOverKeysOfAnyIntegerTypeWritesNothing(string $keyType, int $min, int $max): void
    {
        RecordTable::sources(
            $this->connection,
            [[$min, null, null], [0, null, null], [$max, null, null]],
            'acl_src',
            $keyType,
        );
        $this->runBackfill(self::FALLBACK, 2);

        $again = $this->runBackfill(self::FALLBACK, 2);

        self::assertSame(0, $again->written(), $keyType);
    }

    public function testAnEntryOfAnotherKindDoesNotCountAsAnEntryOfThisKind(): void
    {
        RecordTable::sources($this->connection, [[1, 'u1', 't1'], [2, 'u1', 't1']]);
        $this->store->grant(self::KIND_B, 1, 77, AccessLevel::All);

        $result = $this->runBackfill();

        self::assertSame(2, $result->written());
        self::assertSame([101 => 3], $this->levels(self::KIND_A, 1));
        self::assertSame([77 => 3], $this->levels(self::KIND_B, 1));
    }

    public function testTheClosureIsAskedOncePerTenantAndBatchWithTheCreatorsSeenThere(): void
    {
        RecordTable::sources($this->connection, [
            [1, 'u1', 't1'],
            [2, 'u2', 't1'],
            [3, 'u1', 't1'],
            [4, 'u1', 't2'],
            [5, null, 't2'],
        ]);

        $this->runBackfill();

        self::assertCount(2, $this->calls);
        self::assertSame('t1', $this->calls[0][0]);
        self::assertEqualsCanonicalizing(['u1', 'u2'], $this->calls[0][1]);
        self::assertSame([['t2', ['u1']]], [$this->calls[1]]);
    }

    public function testWithoutTenantColumnTheClosureGetsNullAsTenant(): void
    {
        RecordTable::sources($this->connection, [[1, 'u1', 't1'], [2, 'u2', 't2']]);
        $seen = [];
        $closure = function (?string $tenant, array $creators) use (&$seen): array {
            $seen[] = $tenant;

            return ['u1' => 11, 'u2' => 12];
        };

        $result = $this->backfill->run(self::KIND_A, 'acl_src', 'id', 'created_by', null, $closure, null);

        self::assertSame([null], $seen);
        self::assertSame(2, $result->written());
        self::assertSame([11 => 3], $this->levels(self::KIND_A, 1));
        self::assertSame([12 => 3], $this->levels(self::KIND_A, 2));
    }

    public function testWithoutCreatorColumnEverythingGoesToTheFallbackGroup(): void
    {
        RecordTable::sources($this->connection, [[1, 'u1', 't1'], [2, 'u2', 't2']]);

        $result = $this->backfill->run(
            self::KIND_A,
            'acl_src',
            'id',
            null,
            'tenant',
            $this->groupOfCreator(),
            self::FALLBACK,
        );

        self::assertSame(2, $result->toFallback());
        self::assertSame([self::FALLBACK => 3], $this->levels(self::KIND_A, 1));
        self::assertSame([], $this->calls);
    }

    public function testNumericCreatorsAreHandedOverAsStringsAndMatched(): void
    {
        RecordTable::sources($this->connection, [[1, '42', 't1'], [2, '7', 't1']]);
        $seen = [];
        $closure = function (?string $tenant, array $creators) use (&$seen): array {
            $seen = $creators;

            return ['42' => 142, '7' => 107];
        };

        $this->backfill->run(self::KIND_A, 'acl_src', 'id', 'created_by', 'tenant', $closure, null);

        self::assertContainsOnly('string', $seen);
        self::assertEqualsCanonicalizing(['42', '7'], $seen);
        self::assertSame([142 => 3], $this->levels(self::KIND_A, 1));
        self::assertSame([107 => 3], $this->levels(self::KIND_A, 2));
    }

    public function testEntriesWrittenAreAllLevelAndReadableThroughTheStore(): void
    {
        RecordTable::sources($this->connection, [[1, 'u1', 't1']]);

        $this->runBackfill();

        self::assertEquals([new Entry(101, AccessLevel::All)], $this->store->entriesOf(self::KIND_A, 1));
        self::assertSame(AccessLevel::All, $this->store->levelOf(self::KIND_A, 1, [101]));
    }

    // Ground rules

    public function testRollbackOfTheCallersTransactionUndoesTheBackfill(): void
    {
        $this->sixRecords();
        $pdo = $this->connection->pdo();

        $pdo->beginTransaction();
        $this->runBackfill();
        self::assertSame([101 => 3], $this->levels(self::KIND_A, 1));
        $pdo->rollBack();

        self::assertSame([], $this->levels(self::KIND_A, 1));
        self::assertSame([99 => 1], $this->levels(self::KIND_A, 6));
    }

    public function testBackfillOpensNoTransactionOfItsOwn(): void
    {
        $this->sixRecords();

        $this->runBackfill();

        self::assertFalse($this->connection->pdo()->inTransaction());
    }

    public function testCustomTableNameIsUsed(): void
    {
        $this->connection->pdo()->exec('DROP TABLE IF EXISTS acl_custom');
        $this->connection->pdo()->exec(
            'CREATE TABLE acl_custom (kind INT NOT NULL, record_id BIGINT NOT NULL, '
            . 'group_id BIGINT NOT NULL, level SMALLINT NOT NULL, PRIMARY KEY (kind, record_id, group_id))'
        );
        RecordTable::sources($this->connection, [[1, 'u1', 't1']]);

        $result = (new AclBackfill($this->connection, 'acl_custom'))
            ->run(self::KIND_A, 'acl_src', 'id', 'created_by', 'tenant', $this->groupOfCreator(), null);
        $custom = new AclStore($this->connection, 'acl_custom');
        $levelInCustom = $custom->levelOf(self::KIND_A, 1, [101]);
        $this->connection->pdo()->exec('DROP TABLE acl_custom');

        self::assertSame(1, $result->written());
        self::assertSame(AccessLevel::All, $levelInCustom);
        self::assertSame([], $this->levels(self::KIND_A, 1));
    }

    public function testForeignNamesAreRejectedBeforeAnythingRuns(): void
    {
        RecordTable::sources($this->connection, [[1, 'u1', 't1']]);
        $closure = $this->groupOfCreator();

        $bad = [
            ['acl_src; DROP TABLE acl_src', 'id', 'created_by', 'tenant'],
            ['acl_src', 'id FROM acl_src --', 'created_by', 'tenant'],
            ['acl_src', 'id', 'created_by) OR (1=1', 'tenant'],
            ['acl_src', 'id', 'created_by', 'ten ant'],
            ['acl_src', 'a.id', 'created_by', 'tenant'],
        ];
        foreach ($bad as [$table, $key, $creator, $tenant]) {
            try {
                $this->backfill->run(self::KIND_A, $table, $key, $creator, $tenant, $closure, self::FALLBACK);
                self::fail('expected InvalidArgumentException for ' . $table . ' / ' . $key . ' / ' . $creator);
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->levels(self::KIND_A, 1));
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testInvalidTableNameOfTheAclTableIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AclBackfill($this->connection, 'acl_entries; DROP TABLE x');
    }

    public function testBatchSizeBelowOneIsRejected(): void
    {
        RecordTable::sources($this->connection, [[1, 'u1', 't1']]);

        $this->expectException(InvalidArgumentException::class);

        $this->runBackfill(self::FALLBACK, 0);
    }

    public function testEmptySourceTableWritesNothing(): void
    {
        RecordTable::sources($this->connection, []);

        $result = $this->runBackfill();

        self::assertSame([0, 0, 0], [$result->written(), $result->toFallback(), $result->skipped()]);
    }

    // E5-P0a - counters, fallback group per tenant, verdict over several runs

    /** A creator lookup that knows nobody. */
    private function lookupKnowingNobody(): Closure
    {
        return function (?string $tenant, array $creators): array {
            $this->calls[] = [$tenant, $creators];

            return [];
        };
    }

    /**
     * One run on a source table; each source table is its own information kind (A, or B for acl_src2).
     *
     * @param int|Closure(?string): ?int|null $fallback
     */
    private function runOn(string $table, Closure $lookup, int|Closure|null $fallback, int $batch = 1000): BackfillResult
    {
        $kind = $table === 'acl_src2' ? self::KIND_B : self::KIND_A;

        return $this->backfill->run($kind, $table, 'id', 'created_by', 'tenant', $lookup, $fallback, $batch);
    }

    private function entryRows(): int
    {
        return (int) $this->connection->pdo()->query('SELECT COUNT(*) FROM acl_entries')->fetchColumn();
    }

    /** A fallback closure by tenant that records how often it is asked. */
    private function fallbackByTenant(array &$asked): Closure
    {
        return function (?string $tenant) use (&$asked): ?int {
            $asked[] = $tenant;

            return ['t1' => 501, 't2' => null][$tenant] ?? null;
        };
    }

    public function testCountersTellRecordsWithCreatorAndResolvedOnes(): void
    {
        $this->sixRecords();

        $result = $this->runBackfill();

        // records 1, 2, 4, 5 carry a creator (3 none, 6 has an entry); 1, 2, 5 resolved, 4 (ghost) did not
        self::assertSame(4, $result->withCreator());
        self::assertSame(3, $result->resolved());
        self::assertSame(5, $result->written());
        self::assertSame(2, $result->toFallback());
    }

    public function testCountersStandAtZeroWhenNothingIsMet(): void
    {
        RecordTable::sources($this->connection, []);

        $result = $this->runBackfill();

        self::assertSame([0, 0], [$result->withCreator(), $result->resolved()]);
    }

    public function testNewCountersDefaultToZeroOnTheConstructor(): void
    {
        $result = new BackfillResult(3, 2, 1);

        self::assertSame([0, 0], [$result->withCreator(), $result->resolved()]);
    }

    // T1 (AK 1): two source tables, creators present, none resolved: the verdict throws, the caller's rollback
    // leaves no row

    public function testVerdictOverTwoRunsThrowsAndTheCallersRollbackLeavesNoRow(): void
    {
        RecordTable::sources($this->connection, [[1, 'u1', 't1'], [2, 'u2', 't1']]);
        RecordTable::sources($this->connection, [[1, 'u1', 't2'], [2, null, 't2']], 'acl_src2');
        $pdo = $this->connection->pdo();
        $lookup = $this->lookupKnowingNobody();
        $asked = [];
        $fallback = function (?string $tenant) use (&$asked): ?int {
            $asked[] = $tenant;

            return self::FALLBACK;
        };

        $pdo->beginTransaction();
        $first = $this->runOn('acl_src', $lookup, $fallback);
        $second = $this->runOn('acl_src2', $lookup, $fallback);
        self::assertSame(4, $this->entryRows());

        try {
            $this->backfill->judge($first, $second);
            self::fail('expected BackfillUnresolved');
        } catch (BackfillUnresolved $e) {
            self::assertSame(3, $e->withCreator);
            self::assertStringContainsString('3 record(s) with a creator', $e->getMessage());
            $pdo->rollBack();
        }

        self::assertSame(0, $this->entryRows());
        self::assertSame(0, $first->resolved() + $second->resolved());
    }

    public function testRunNeverThrowsTheVerdictItself(): void
    {
        RecordTable::sources($this->connection, [[1, 'u1', 't1']]);

        $result = $this->runOn('acl_src', $this->lookupKnowingNobody(), null);

        self::assertSame(1, $result->withCreator());
        self::assertSame(0, $result->resolved());
        self::assertSame(1, $result->skipped());
    }

    public function testBackfillUnresolvedIsARuntimeException(): void
    {
        self::assertInstanceOf(\RuntimeException::class, new BackfillUnresolved(1));
    }

    // T2 (AK 1, G8): none resolved in table 1, one in table 2: the sum counts, no verdict

    public function testOneResolvedCreatorInTheOtherTableLetsThePassStand(): void
    {
        RecordTable::sources($this->connection, [[1, 'ghost', 't1'], [2, 'ghost', 't1']]);
        RecordTable::sources($this->connection, [[1, 'u1', 't1']], 'acl_src2');

        $first = $this->runOn('acl_src', $this->groupOfCreator(), self::FALLBACK);
        $second = $this->runOn('acl_src2', $this->groupOfCreator(), self::FALLBACK);

        self::assertSame(0, $first->resolved());
        self::assertSame(1, $second->resolved());
        $this->backfill->judge($first, $second);
        self::assertSame(3, $this->entryRows());
    }

    public function testVerdictWithoutResultsDoesNotThrow(): void
    {
        $this->backfill->judge();

        $this->expectNotToPerformAssertions();
    }

    // T3 (AK 2): one of many resolved, the rest by the fallback closure of their tenant, tenant without one skipped

    public function testFallbackClosureNamesTheGroupPerTenantAndNullSkipsTheRestOfThatTenant(): void
    {
        RecordTable::sources($this->connection, [
            [1, 'u1', 't1'],
            [2, 'ghost', 't1'],
            [3, null, 't1'],
            [4, 'ghost', 't2'],
            [5, '', 't2'],
        ]);
        $asked = [];

        $result = $this->runOn('acl_src', $this->groupOfCreator(), $this->fallbackByTenant($asked));

        self::assertSame([101 => 3], $this->levels(self::KIND_A, 1));
        self::assertSame([501 => 3], $this->levels(self::KIND_A, 2));
        self::assertSame([501 => 3], $this->levels(self::KIND_A, 3));
        self::assertSame([], $this->levels(self::KIND_A, 4));
        self::assertSame([], $this->levels(self::KIND_A, 5));
        self::assertSame(3, $result->written());
        self::assertSame(2, $result->toFallback());
        self::assertSame(2, $result->skipped());
        self::assertSame(3, $result->withCreator());
        self::assertSame(1, $result->resolved());
        $this->backfill->judge($result);
    }

    // T4 (AK 3): no record with a creator: no verdict

    public function testRecordsWithoutCreatorNeverRefuseThePass(): void
    {
        RecordTable::sources($this->connection, [[1, null, 't1'], [2, '', 't1'], [3, null, 't2']]);
        $withColumn = $this->runOn('acl_src', $this->groupOfCreator(), self::FALLBACK);
        RecordTable::sources($this->connection, [[1, 'u1', 't1']], 'acl_src2');
        $withoutColumn = $this->backfill->run(
            self::KIND_B,
            'acl_src2',
            'id',
            null,
            'tenant',
            $this->groupOfCreator(),
            self::FALLBACK,
        );

        self::assertSame(0, $withColumn->withCreator());
        self::assertSame(0, $withoutColumn->withCreator());
        $this->backfill->judge($withColumn, $withoutColumn);
        self::assertSame(4, $this->entryRows());
    }

    public function testRecordsWithoutCreatorAndWithoutFallbackAreSkippedWithoutVerdict(): void
    {
        RecordTable::sources($this->connection, [[1, null, 't1'], [2, '', 't1']]);

        $result = $this->runOn('acl_src', $this->groupOfCreator(), null);

        self::assertSame(2, $result->skipped());
        $this->backfill->judge($result);
        self::assertSame(0, $this->entryRows());
    }

    // T6 (AK 5): second run with the fallback group in both tenants writes nothing, no verdict

    public function testSecondRunWithFallbackInBothTenantsWritesNothingAndIsNotRefused(): void
    {
        RecordTable::sources($this->connection, [[1, 'u1', 't1'], [2, 'ghost', 't2'], [3, null, 't2']]);
        $both = fn (?string $tenant): ?int => self::FALLBACK;
        $first = $this->runOn('acl_src', $this->groupOfCreator(), $both);
        $this->backfill->judge($first);
        $rows = $this->entryRows();

        $second = $this->runOn('acl_src', $this->groupOfCreator(), $both);

        self::assertSame(2, $first->toFallback());
        self::assertSame(1, $first->resolved());
        self::assertSame([0, 0, 0, 0, 0], [
            $second->written(),
            $second->toFallback(),
            $second->skipped(),
            $second->withCreator(),
            $second->resolved(),
        ]);
        $this->backfill->judge($second);
        self::assertSame($rows, $this->entryRows());
    }

    // Folgelauf mit Rest (Bescheid 11, as decided): records left over keep the second pass refused, nothing written

    public function testLeftOverRecordsRefuseTheNextPassAndWriteNothing(): void
    {
        RecordTable::sources($this->connection, [[1, 'u1', 't1'], [2, 'ghost', 't1'], [3, 'ghost', 't2']]);
        $first = $this->runOn('acl_src', $this->groupOfCreator(), null);
        $this->backfill->judge($first);
        self::assertSame(1, $first->written());
        self::assertSame(1, $this->entryRows());

        $second = $this->runOn('acl_src', $this->groupOfCreator(), null);

        self::assertSame([0, 2, 2, 0], [$second->written(), $second->withCreator(), $second->skipped(), $second->resolved()]);
        $this->expectException(BackfillUnresolved::class);
        try {
            $this->backfill->judge($second);
        } finally {
            self::assertSame(1, $this->entryRows());
        }
    }

    // T7 (arbeitsliste 2): the fallback closure is asked once per tenant and batch, only when a record needs it

    public function testFallbackClosureIsAskedOncePerTenantAndBatchAndOnlyWhenNeeded(): void
    {
        RecordTable::sources($this->connection, [
            [1, 'ghost', 't1'],
            [2, null, 't1'],
            [3, 'ghost', 't1'],
            [4, null, 't2'],
            [5, 'u1', 't3'],
        ]);
        $asked = [];

        $this->runOn('acl_src', $this->lookupKnowingNobody(), $this->fallbackByTenant($asked));

        self::assertSame(['t1', 't2', 't3'], $asked);
    }

    public function testFallbackClosureIsNotAskedWhenEveryCreatorResolves(): void
    {
        RecordTable::sources($this->connection, [[1, 'u1', 't1'], [2, 'u2', 't1'], [3, 'u1', 't2']]);
        $asked = [];

        $this->runOn('acl_src', $this->groupOfCreator(), $this->fallbackByTenant($asked));

        self::assertSame([], $asked);
    }

    public function testFallbackClosureIsAskedAgainInTheNextBatch(): void
    {
        RecordTable::sources($this->connection, [[1, null, 't1'], [2, null, 't1'], [3, null, 't1']]);
        $asked = [];

        $result = $this->runOn('acl_src', $this->groupOfCreator(), $this->fallbackByTenant($asked), 2);

        self::assertSame(['t1', 't1'], $asked);
        self::assertSame(3, $result->written());
    }

    public function testFallbackClosureGetsNullAsTenantWithoutTenantColumn(): void
    {
        RecordTable::sources($this->connection, [[1, null, 't1'], [2, null, 't2']]);
        $asked = [];
        $fallback = function (?string $tenant) use (&$asked): ?int {
            $asked[] = $tenant;

            return 77;
        };

        $result = $this->backfill->run(
            self::KIND_A,
            'acl_src',
            'id',
            'created_by',
            null,
            $this->groupOfCreator(),
            $fallback,
        );

        self::assertSame([null], $asked);
        self::assertSame(2, $result->toFallback());
        self::assertSame([77 => 3], $this->levels(self::KIND_A, 2));
    }
}
