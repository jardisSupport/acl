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
use JardisSupport\Acl\Tests\Support\AclTable;
use JardisSupport\Acl\Tests\Support\RecordTable;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
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
}
