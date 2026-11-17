<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Integration;

use JardisSupport\Acl\AclStore;
use JardisSupport\Acl\Data\AccessDecision;
use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Data\Entry;
use JardisSupport\Acl\Tests\Support\AclTable;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use PHPUnit\Framework\TestCase;

/**
 * check, grantAll and hasEntriesOfGroup against a real database (one subclass per dialect).
 *
 * Bestand of kind A: records 1-3 hold one level for group 1 (view, edit, all); record 4 has no entry; record 5 is
 * held by group 1 (view) and group 2 (all); record 6 only by group 3 (edit). Kind B repeats record 1 and 3 for
 * group 1 with the opposite level, so that a wrong kind would change an answer.
 */
abstract class AclDecisionTestCase extends TestCase
{
    private const KIND_A = 1;
    private const KIND_B = 2;
    private const RECORDS = [1, 2, 3, 4, 5, 6, 7];

    protected DbConnectionInterface $connection;
    private AclStore $store;

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

    private function seed(): void
    {
        $this->store->grant(self::KIND_A, 1, 1, AccessLevel::View);
        $this->store->grant(self::KIND_A, 2, 1, AccessLevel::Edit);
        $this->store->grant(self::KIND_A, 3, 1, AccessLevel::All);
        $this->store->grant(self::KIND_A, 5, 1, AccessLevel::View);
        $this->store->grant(self::KIND_A, 5, 2, AccessLevel::All);
        $this->store->grant(self::KIND_A, 6, 3, AccessLevel::Edit);
        $this->store->grant(self::KIND_B, 1, 1, AccessLevel::All);
        $this->store->grant(self::KIND_B, 3, 1, AccessLevel::View);
    }

    // AK1 - check: nine cases of held x required level, no row, empty groups, other kind

    public function testCheckAnswersEachOfTheNineCombinationsOfHeldAndRequiredLevel(): void
    {
        $this->seed();
        $expected = [
            [1, AccessLevel::View, AccessDecision::Granted],
            [1, AccessLevel::Edit, AccessDecision::TooLow],
            [1, AccessLevel::All, AccessDecision::TooLow],
            [2, AccessLevel::View, AccessDecision::Granted],
            [2, AccessLevel::Edit, AccessDecision::Granted],
            [2, AccessLevel::All, AccessDecision::TooLow],
            [3, AccessLevel::View, AccessDecision::Granted],
            [3, AccessLevel::Edit, AccessDecision::Granted],
            [3, AccessLevel::All, AccessDecision::Granted],
        ];

        foreach ($expected as [$record, $required, $decision]) {
            self::assertSame(
                $decision,
                $this->store->check(self::KIND_A, $record, [1], $required),
                sprintf('record %d, required %s', $record, $required->word()),
            );
        }
    }

    public function testCheckSaysNotVisibleWithoutARowForTheRecord(): void
    {
        $this->seed();

        foreach (AccessLevel::cases() as $required) {
            self::assertSame(AccessDecision::NotVisible, $this->store->check(self::KIND_A, 4, [1, 2, 3], $required));
            self::assertSame(AccessDecision::NotVisible, $this->store->check(self::KIND_A, 99, [1, 2, 3], $required));
        }
    }

    public function testCheckSaysNotVisibleForAnEmptyGroupList(): void
    {
        $this->seed();

        foreach (self::RECORDS as $record) {
            foreach (AccessLevel::cases() as $required) {
                self::assertSame(
                    AccessDecision::NotVisible,
                    $this->store->check(self::KIND_A, $record, [], $required),
                );
            }
        }
    }

    public function testCheckSaysNotVisibleForAGroupWithoutAnEntryOnTheRecord(): void
    {
        $this->seed();

        self::assertSame(AccessDecision::NotVisible, $this->store->check(self::KIND_A, 6, [1, 2], AccessLevel::View));
        self::assertSame(AccessDecision::NotVisible, $this->store->check(self::KIND_A, 3, [2, 3], AccessLevel::View));
    }

    public function testCheckSaysNotVisibleForAnInformationKindWithoutEntries(): void
    {
        $this->seed();

        self::assertSame(AccessDecision::NotVisible, $this->store->check(3, 3, [1, 2, 3], AccessLevel::View));
    }

    public function testCheckReadsTheEntriesOfTheAskedKindOnly(): void
    {
        $this->seed();

        self::assertSame(AccessDecision::TooLow, $this->store->check(self::KIND_A, 1, [1], AccessLevel::All));
        self::assertSame(AccessDecision::Granted, $this->store->check(self::KIND_B, 1, [1], AccessLevel::All));
        self::assertSame(AccessDecision::Granted, $this->store->check(self::KIND_A, 3, [1], AccessLevel::All));
        self::assertSame(AccessDecision::TooLow, $this->store->check(self::KIND_B, 3, [1], AccessLevel::Edit));
    }

    public function testCheckTakesTheHighestLevelOverSeveralGroups(): void
    {
        $this->seed();

        self::assertSame(AccessDecision::TooLow, $this->store->check(self::KIND_A, 5, [1], AccessLevel::All));
        self::assertSame(AccessDecision::Granted, $this->store->check(self::KIND_A, 5, [2], AccessLevel::All));
        self::assertSame(AccessDecision::Granted, $this->store->check(self::KIND_A, 5, [1, 2], AccessLevel::All));
        self::assertSame(AccessDecision::Granted, $this->store->check(self::KIND_A, 5, [2, 1], AccessLevel::All));
        self::assertSame(AccessDecision::Granted, $this->store->check(self::KIND_A, 5, [3, 1, 2, 9], AccessLevel::All));
        self::assertSame(AccessDecision::TooLow, $this->store->check(self::KIND_A, 5, [1, 9], AccessLevel::Edit));
    }

    // AK2 - check = granted exactly when levelOf reaches the required level (E1-I3), for every record

    public function testCheckIsGrantedExactlyWhenLevelOfReachesTheRequiredLevel(): void
    {
        $this->seed();
        $groupSets = [[], [1], [2], [3], [1, 2], [1, 3], [2, 3], [1, 2, 3], [9], [1, 9]];
        $checked = 0;

        foreach ([self::KIND_A, self::KIND_B, 3] as $kind) {
            foreach (self::RECORDS as $record) {
                foreach ($groupSets as $groups) {
                    $held = $this->store->levelOf($kind, $record, $groups);
                    foreach (AccessLevel::cases() as $required) {
                        $decision = $this->store->check($kind, $record, $groups, $required);
                        $context = sprintf('kind %d record %d groups [%s] required %s', $kind, $record, implode(',', $groups), $required->word());

                        self::assertSame(
                            $held !== null && $held->value >= $required->value,
                            $decision === AccessDecision::Granted,
                            $context,
                        );
                        self::assertSame($held === null, $decision === AccessDecision::NotVisible, $context);
                        self::assertSame(
                            $held !== null && $held->value < $required->value,
                            $decision === AccessDecision::TooLow,
                            $context,
                        );
                        $checked++;
                    }
                }
            }
        }

        self::assertSame(3 * 7 * 10 * 3, $checked);
    }

    // AK3 - grantAll

    public function testGrantAllWritesThreeRowsEachWithItsLevel(): void
    {
        $this->store->grantAll(self::KIND_A, 10, [
            new Entry(1, AccessLevel::All),
            new Entry(2, AccessLevel::Edit),
            new Entry(3, AccessLevel::View),
        ]);

        self::assertEquals(
            [new Entry(1, AccessLevel::All), new Entry(2, AccessLevel::Edit), new Entry(3, AccessLevel::View)],
            $this->store->entriesOf(self::KIND_A, 10),
        );
        self::assertSame([], $this->store->entriesOf(self::KIND_B, 10));
        self::assertSame([], $this->store->entriesOf(self::KIND_A, 11));
    }

    public function testGrantAllWithAnEmptyListWritesNothing(): void
    {
        $this->store->grantAll(self::KIND_A, 10, []);

        self::assertSame([], $this->store->entriesOf(self::KIND_A, 10));
        self::assertFalse($this->store->hasEntriesOfGroup(1));
    }

    public function testGrantAllWithTheSameGroupTwiceKeepsTheLastLevel(): void
    {
        $this->store->grantAll(self::KIND_A, 10, [
            new Entry(1, AccessLevel::All),
            new Entry(2, AccessLevel::Edit),
            new Entry(1, AccessLevel::View),
        ]);
        $this->store->grantAll(self::KIND_A, 11, [
            new Entry(1, AccessLevel::View),
            new Entry(1, AccessLevel::All),
        ]);

        self::assertEquals(
            [new Entry(1, AccessLevel::View), new Entry(2, AccessLevel::Edit)],
            $this->store->entriesOf(self::KIND_A, 10),
        );
        self::assertEquals([new Entry(1, AccessLevel::All)], $this->store->entriesOf(self::KIND_A, 11));
    }

    public function testGrantAllOverwritesAnExistingEntryAndLeavesOthersAlone(): void
    {
        $this->store->grant(self::KIND_A, 10, 1, AccessLevel::All);
        $this->store->grant(self::KIND_A, 10, 4, AccessLevel::Edit);
        $this->store->grant(self::KIND_B, 10, 1, AccessLevel::All);

        $this->store->grantAll(self::KIND_A, 10, [new Entry(1, AccessLevel::View), new Entry(2, AccessLevel::Edit)]);

        self::assertEquals(
            [new Entry(1, AccessLevel::View), new Entry(2, AccessLevel::Edit), new Entry(4, AccessLevel::Edit)],
            $this->store->entriesOf(self::KIND_A, 10),
        );
        self::assertEquals([new Entry(1, AccessLevel::All)], $this->store->entriesOf(self::KIND_B, 10));
    }

    public function testGrantAllDoesTheSameAsGrantForEachEntry(): void
    {
        $entries = [new Entry(1, AccessLevel::Edit), new Entry(2, AccessLevel::All), new Entry(3, AccessLevel::View)];
        $this->store->grantAll(self::KIND_A, 10, $entries);
        foreach ($entries as $entry) {
            $this->store->grant(self::KIND_A, 11, $entry->group, $entry->level);
        }

        self::assertEquals($this->store->entriesOf(self::KIND_A, 10), $this->store->entriesOf(self::KIND_A, 11));
    }

    public function testRollbackOfTheCallersTransactionLeavesNoRowOfGrantAll(): void
    {
        $pdo = $this->connection->pdo();

        $pdo->beginTransaction();
        $this->store->grantAll(self::KIND_A, 10, [new Entry(1, AccessLevel::All), new Entry(2, AccessLevel::View)]);
        self::assertCount(2, $this->store->entriesOf(self::KIND_A, 10));
        $pdo->rollBack();

        self::assertSame([], $this->store->entriesOf(self::KIND_A, 10));
        self::assertFalse($this->store->hasEntriesOfGroup(1));
        self::assertFalse($this->store->hasEntriesOfGroup(2));
    }

    public function testGrantAllAndCheckOpenNoTransactionOfTheirOwn(): void
    {
        $pdo = $this->connection->pdo();

        $this->store->grantAll(self::KIND_A, 10, [new Entry(1, AccessLevel::All), new Entry(2, AccessLevel::View)]);
        $this->store->check(self::KIND_A, 10, [1], AccessLevel::Edit);
        $this->store->hasEntriesOfGroup(1);

        self::assertFalse($pdo->inTransaction());
    }

    public function testACommittedTransactionOfTheCallerKeepsTheRowsOfGrantAll(): void
    {
        $pdo = $this->connection->pdo();

        $pdo->beginTransaction();
        $this->store->grantAll(self::KIND_A, 10, [new Entry(1, AccessLevel::All)]);
        $pdo->commit();

        self::assertEquals([new Entry(1, AccessLevel::All)], $this->store->entriesOf(self::KIND_A, 10));
    }

    // AK4 - hasEntriesOfGroup

    public function testNoGroupHasEntriesWithoutAnyRow(): void
    {
        self::assertFalse($this->store->hasEntriesOfGroup(1));
        self::assertFalse($this->store->hasEntriesOfGroup(0));
    }

    public function testAGroupHasEntriesWhileOneRowCarriesIt(): void
    {
        $this->seed();

        self::assertTrue($this->store->hasEntriesOfGroup(1));
        self::assertTrue($this->store->hasEntriesOfGroup(2));
        self::assertTrue($this->store->hasEntriesOfGroup(3));
        self::assertFalse($this->store->hasEntriesOfGroup(4));
    }

    public function testAnEntryOfAnyInformationKindCounts(): void
    {
        $this->store->grant(self::KIND_B, 10, 7, AccessLevel::View);

        self::assertTrue($this->store->hasEntriesOfGroup(7));

        $this->store->grant(77, 10, 8, AccessLevel::All);

        self::assertTrue($this->store->hasEntriesOfGroup(8));
    }

    public function testAGroupHasNoEntriesAfterItsLastRowIsGone(): void
    {
        $this->store->grant(self::KIND_A, 10, 7, AccessLevel::All);
        $this->store->grant(self::KIND_B, 11, 7, AccessLevel::View);
        $this->store->grant(self::KIND_A, 10, 8, AccessLevel::All);

        $this->store->revoke(self::KIND_A, 10, 7);
        self::assertTrue($this->store->hasEntriesOfGroup(7));

        $this->store->removeRecord(self::KIND_B, 11);
        self::assertFalse($this->store->hasEntriesOfGroup(7));
        self::assertTrue($this->store->hasEntriesOfGroup(8));
    }

    public function testAGroupMovedAwayHasNoEntriesAndTheTargetHasThem(): void
    {
        $this->store->grant(self::KIND_A, 10, 7, AccessLevel::All);

        $this->store->reassignGroup(7, 8);

        self::assertFalse($this->store->hasEntriesOfGroup(7));
        self::assertTrue($this->store->hasEntriesOfGroup(8));
    }

    // AK5 - the words of the levels, through the database

    public function testWordsOfTheLevelsSurviveTheRoundTripThroughTheTable(): void
    {
        foreach (['view', 'edit', 'all'] as $index => $word) {
            $level = AccessLevel::fromWord($word);
            self::assertNotNull($level);
            $this->store->grant(self::KIND_A, 10, $index + 1, $level);
        }

        $words = array_map(
            static fn (Entry $entry): string => $entry->level->word(),
            $this->store->entriesOf(self::KIND_A, 10),
        );

        self::assertSame(['view', 'edit', 'all'], $words);
        self::assertNull(AccessLevel::fromWord('read'));
    }
}
