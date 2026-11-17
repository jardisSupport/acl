<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Integration;

use InvalidArgumentException;
use JardisSupport\Acl\AclStore;
use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Data\Entry;
use JardisSupport\Acl\Tests\Support\AclTable;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use PHPUnit\Framework\TestCase;

/** AclStore behaviour against a real database (one subclass per dialect). */
abstract class AclStoreTestCase extends TestCase
{
    private const KIND_A = 1;
    private const KIND_B = 2;

    protected DbConnectionInterface $connection;
    protected AclStore $store;

    abstract protected function createConnection(): DbConnectionInterface;

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
        AclTable::recreate($this->connection);
        $this->store = new AclStore($this->connection);
    }

    protected function tearDown(): void
    {
        if ($this->connection->pdo()->inTransaction()) {
            $this->connection->pdo()->rollBack();
        }
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

    // AK1 - table from resources/sql

    public function testShippedTableIsUsableAndItsDdlIsIdempotent(): void
    {
        AclTable::create($this->connection);
        $this->store->grant(self::KIND_A, 10, 7, AccessLevel::Edit);
        AclTable::create($this->connection);

        self::assertSame([7 => 2], $this->levels(self::KIND_A, 10));
    }

    public function testCustomTableNameIsUsed(): void
    {
        $this->connection->pdo()->exec('DROP TABLE IF EXISTS acl_custom');
        $this->connection->pdo()->exec(
            'CREATE TABLE acl_custom (kind INT NOT NULL, record_id BIGINT NOT NULL, '
            . 'group_id BIGINT NOT NULL, level SMALLINT NOT NULL, PRIMARY KEY (kind, record_id, group_id))'
        );
        $custom = new AclStore($this->connection, 'acl_custom');

        $custom->grant(self::KIND_A, 1, 5, AccessLevel::View);

        self::assertSame(AccessLevel::View, $custom->levelOf(self::KIND_A, 1, [5]));
        self::assertNull($this->store->levelOf(self::KIND_A, 1, [5]));
        $this->connection->pdo()->exec('DROP TABLE acl_custom');
    }

    public function testInvalidTableNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AclStore($this->connection, 'acl_entries; DROP TABLE x');
    }

    // AK2 - levelOf

    public function testLevelOfReturnsHighestLevelOverSeveralGroups(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::View);
        $this->store->grant(self::KIND_A, 10, 2, AccessLevel::All);
        $this->store->grant(self::KIND_A, 10, 3, AccessLevel::Edit);

        self::assertSame(AccessLevel::All, $this->store->levelOf(self::KIND_A, 10, [1, 2, 3]));
        self::assertSame(AccessLevel::Edit, $this->store->levelOf(self::KIND_A, 10, [1, 3]));
        self::assertSame(AccessLevel::View, $this->store->levelOf(self::KIND_A, 10, [1, 99]));
    }

    public function testLevelOfIsNullForForeignKindRecordOrEmptyGroupList(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::All);

        self::assertNull($this->store->levelOf(self::KIND_B, 10, [1]));
        self::assertNull($this->store->levelOf(self::KIND_A, 11, [1]));
        self::assertNull($this->store->levelOf(self::KIND_A, 10, [2]));
        self::assertNull($this->store->levelOf(self::KIND_A, 10, []));
    }

    public function testLevelOfSeparatesTwoKindsWithTheSameRecordId(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::View);
        $this->store->grant(self::KIND_B, 10, 1, AccessLevel::All);

        self::assertSame(AccessLevel::View, $this->store->levelOf(self::KIND_A, 10, [1]));
        self::assertSame(AccessLevel::All, $this->store->levelOf(self::KIND_B, 10, [1]));
    }

    // AK3 - grant

    public function testGrantTwiceForTheSameGroupKeepsOneRowWithTheLastLevel(): void
    {
        $this->store->grant(self::KIND_A, 10, 4, AccessLevel::All);
        $this->store->grant(self::KIND_A, 10, 4, AccessLevel::View);

        self::assertEquals([new Entry(4, AccessLevel::View)], $this->store->entriesOf(self::KIND_A, 10));
        self::assertSame(AccessLevel::View, $this->store->levelOf(self::KIND_A, 10, [4]));
    }

    // AK4 - revoke, isLastFullEntry

    public function testRevokeRemovesExactlyOneRow(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::All);
        $this->store->grant(self::KIND_A, 10, 2, AccessLevel::View);
        $this->store->grant(self::KIND_A, 11, 1, AccessLevel::View);
        $this->store->grant(self::KIND_B, 10, 1, AccessLevel::View);

        $this->store->revoke(self::KIND_A, 10, 1);

        self::assertSame([2 => 1], $this->levels(self::KIND_A, 10));
        self::assertSame([1 => 1], $this->levels(self::KIND_A, 11));
        self::assertSame([1 => 1], $this->levels(self::KIND_B, 10));
    }

    public function testRevokeOfAnUnknownEntryIsANoOp(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::All);

        $this->store->revoke(self::KIND_A, 10, 99);

        self::assertSame([1 => 3], $this->levels(self::KIND_A, 10));
    }

    public function testIsLastFullEntryIsTrueOnlyWithoutAnyOtherFullEntry(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::All);
        $this->store->grant(self::KIND_A, 10, 2, AccessLevel::Edit);
        $this->store->grant(self::KIND_A, 10, 3, AccessLevel::View);

        self::assertTrue($this->store->isLastFullEntry(self::KIND_A, 10, 1));

        $this->store->grant(self::KIND_A, 10, 2, AccessLevel::All);

        self::assertFalse($this->store->isLastFullEntry(self::KIND_A, 10, 1));
        self::assertFalse($this->store->isLastFullEntry(self::KIND_A, 10, 2));
    }

    public function testIsLastFullEntryIsFalseForAGroupThatDoesNotHoldAll(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::All);
        $this->store->grant(self::KIND_A, 10, 2, AccessLevel::Edit);

        self::assertFalse($this->store->isLastFullEntry(self::KIND_A, 10, 2));
        self::assertFalse($this->store->isLastFullEntry(self::KIND_A, 10, 99));
        self::assertFalse($this->store->isLastFullEntry(self::KIND_A, 77, 1));
    }

    public function testIsLastFullEntryIgnoresFullEntriesOfOtherRecordsAndKinds(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::All);
        $this->store->grant(self::KIND_A, 11, 2, AccessLevel::All);
        $this->store->grant(self::KIND_B, 10, 3, AccessLevel::All);

        self::assertTrue($this->store->isLastFullEntry(self::KIND_A, 10, 1));
    }

    // AK5 - removeRecord, reassignGroup, entriesOf

    public function testRemoveRecordRemovesAllRowsOfTheRecordAndNoOthers(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::All);
        $this->store->grant(self::KIND_A, 10, 2, AccessLevel::View);
        $this->store->grant(self::KIND_A, 11, 1, AccessLevel::View);
        $this->store->grant(self::KIND_B, 10, 1, AccessLevel::Edit);

        $removed = $this->store->removeRecord(self::KIND_A, 10);

        self::assertSame(2, $removed);
        self::assertSame([], $this->levels(self::KIND_A, 10));
        self::assertSame([1 => 1], $this->levels(self::KIND_A, 11));
        self::assertSame([1 => 2], $this->levels(self::KIND_B, 10));
    }

    public function testRemoveRecordOfAnUnknownRecordRemovesNothing(): void
    {
        self::assertSame(0, $this->store->removeRecord(self::KIND_A, 404));
    }

    public function testReassignGroupMovesTheRowsOfTheSourceGroup(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::Edit);
        $this->store->grant(self::KIND_A, 11, 1, AccessLevel::View);
        $this->store->grant(self::KIND_B, 10, 1, AccessLevel::All);
        $this->store->grant(self::KIND_A, 10, 9, AccessLevel::View);

        $moved = $this->store->reassignGroup(1, 2);

        self::assertSame(3, $moved);
        self::assertSame([2 => 2, 9 => 1], $this->levels(self::KIND_A, 10));
        self::assertSame([2 => 1], $this->levels(self::KIND_A, 11));
        self::assertSame([2 => 3], $this->levels(self::KIND_B, 10));
    }

    public function testReassignGroupKeepsTheHigherLevelOnCollision(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::All);
        $this->store->grant(self::KIND_A, 10, 2, AccessLevel::View);
        $this->store->grant(self::KIND_A, 11, 1, AccessLevel::View);
        $this->store->grant(self::KIND_A, 11, 2, AccessLevel::Edit);
        $this->store->grant(self::KIND_A, 12, 1, AccessLevel::Edit);
        $this->store->grant(self::KIND_A, 12, 2, AccessLevel::Edit);

        $moved = $this->store->reassignGroup(1, 2);

        self::assertSame(3, $moved);
        self::assertSame([2 => 3], $this->levels(self::KIND_A, 10));
        self::assertSame([2 => 2], $this->levels(self::KIND_A, 11));
        self::assertSame([2 => 2], $this->levels(self::KIND_A, 12));
    }

    public function testReassignGroupToItselfChangesNothing(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::All);

        self::assertSame(0, $this->store->reassignGroup(1, 1));
        self::assertSame([1 => 3], $this->levels(self::KIND_A, 10));
    }

    public function testReassignGroupWithoutRowsMovesNothing(): void
    {
        $this->store->grant(self::KIND_A, 10, 5, AccessLevel::All);

        self::assertSame(0, $this->store->reassignGroup(1, 2));
        self::assertSame([5 => 3], $this->levels(self::KIND_A, 10));
    }

    public function testReassignGroupHandlesMoreRowsThanOneBatch(): void
    {
        $total = 1203;
        for ($record = 1; $record <= $total; $record++) {
            $level = AccessLevel::from(($record % 3) + 1);
            $this->store->grant(self::KIND_A, $record, 1, $level);
            if ($record % 5 === 0) {
                $this->store->grant(self::KIND_A, $record, 2, AccessLevel::from((($record + 1) % 3) + 1));
            }
        }

        $moved = $this->store->reassignGroup(1, 2);

        self::assertSame($total, $moved);
        for ($record = 1; $record <= $total; $record++) {
            $expected = ($record % 3) + 1;
            if ($record % 5 === 0) {
                $expected = max($expected, (($record + 1) % 3) + 1);
            }
            self::assertSame([2 => $expected], $this->levels(self::KIND_A, $record), "record $record");
        }
    }

    public function testEntriesOfReturnsExactlyTheRowsOfTheRecord(): void
    {
        $this->store->grant(self::KIND_A, 10, 3, AccessLevel::View);
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::All);
        $this->store->grant(self::KIND_A, 11, 2, AccessLevel::Edit);
        $this->store->grant(self::KIND_B, 10, 2, AccessLevel::Edit);

        self::assertEquals(
            [new Entry(1, AccessLevel::All), new Entry(3, AccessLevel::View)],
            $this->store->entriesOf(self::KIND_A, 10),
        );
        self::assertSame([], $this->store->entriesOf(self::KIND_A, 12));
    }

    // AK6 - transaction belongs to the caller

    public function testRollbackOfTheCallersTransactionRemovesTheRow(): void
    {
        $pdo = $this->connection->pdo();

        $pdo->beginTransaction();
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::All);
        self::assertSame(AccessLevel::All, $this->store->levelOf(self::KIND_A, 10, [1]));
        $pdo->rollBack();

        self::assertNull($this->store->levelOf(self::KIND_A, 10, [1]));
        self::assertSame([], $this->store->entriesOf(self::KIND_A, 10));
    }

    public function testStoreOpensNoTransactionOfItsOwn(): void
    {
        $pdo = $this->connection->pdo();
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::Edit);
        $this->store->grant(self::KIND_A, 11, 1, AccessLevel::Edit);
        $this->store->reassignGroup(1, 2);
        $this->store->revoke(self::KIND_A, 10, 2);
        $this->store->removeRecord(self::KIND_A, 11);

        self::assertFalse($pdo->inTransaction());
    }

    public function testRollbackUndoesAReassignGroupOfTheCallersTransaction(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::All);
        $this->store->grant(self::KIND_A, 10, 2, AccessLevel::View);
        $pdo = $this->connection->pdo();

        $pdo->beginTransaction();
        $this->store->reassignGroup(1, 2);
        $pdo->rollBack();

        self::assertSame([1 => 3, 2 => 1], $this->levels(self::KIND_A, 10));
    }
}
