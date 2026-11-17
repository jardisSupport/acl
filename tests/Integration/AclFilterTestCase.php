<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Integration;

use InvalidArgumentException;
use JardisSupport\Acl\AclFilter;
use JardisSupport\Acl\AclStore;
use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Tests\Support\AclTable;
use JardisSupport\Acl\Tests\Support\RecordTable;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\Contract\DbQuery\DbQueryBuilderInterface;
use JardisSupport\DbQuery\Data\Expression;
use JardisSupport\DbQuery\DbQuery;
use JardisSupport\Repository\Handler\QueryExecutor;
use PHPUnit\Framework\TestCase;

/**
 * AclFilter against a real database (one subclass per dialect): a business table of 20 documents, two
 * information kinds, three levels, two persons. The filter is run inside a real query, in both forms.
 */
abstract class AclFilterTestCase extends TestCase
{
    private const DOCS = 20;
    private const KIND_A = 1;
    private const KIND_B = 2;

    /** Group sets of the two persons. */
    private const PERSON_A = [10, 11, 12];
    private const PERSON_B = [20];

    protected DbConnectionInterface $connection;
    private AclStore $store;
    private AclFilter $filter;
    private QueryExecutor $executor;

    abstract protected function createConnection(): DbConnectionInterface;

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();
        AclTable::recreate($this->connection);
        RecordTable::documents($this->connection, self::DOCS);
        $this->store = new AclStore($this->connection);
        $this->filter = new AclFilter();
        $this->executor = new QueryExecutor($this->connection);
        $this->seed();
    }

    protected function tearDown(): void
    {
        RecordTable::drop($this->connection);
    }

    /**
     * Kind A: group 10 views 1-5; group 11 edits 3-8; group 12 holds all of 6-9; group 20 views 10-13, edits
     * 14-16 and holds all of 17; group 30 (nobody of ours) holds all of 18-20. Kind B, same numbers: group 10 holds
     * all of 15-20 and group 20 all of 1-5 - the entries of the other kind must not count.
     */
    private function seed(): void
    {
        $grants = [
            [self::KIND_A, range(1, 5), 10, AccessLevel::View],
            [self::KIND_A, range(3, 8), 11, AccessLevel::Edit],
            [self::KIND_A, range(6, 9), 12, AccessLevel::All],
            [self::KIND_A, range(10, 13), 20, AccessLevel::View],
            [self::KIND_A, range(14, 16), 20, AccessLevel::Edit],
            [self::KIND_A, [17], 20, AccessLevel::All],
            [self::KIND_A, range(18, 20), 30, AccessLevel::All],
            [self::KIND_B, range(15, 20), 10, AccessLevel::All],
            [self::KIND_B, range(1, 5), 20, AccessLevel::All],
        ];
        foreach ($grants as [$kind, $records, $group, $level]) {
            foreach ($records as $record) {
                $this->store->grant($kind, $record, $group, $level);
            }
        }
    }

    /**
     * @param callable(DbQuery): DbQueryBuilderInterface $restrict adds the filter to a query of acl_docs
     * @return list<int>
     */
    private function visibleIds(callable $restrict): array
    {
        $query = $restrict((new DbQuery())->select('id')->from('acl_docs', 'd'));
        $prepared = $query->orderBy('d.id')->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->executor->fetchAll($prepared),
        );
    }

    /**
     * @param list<int> $groups
     * @return list<int>
     */
    private function byRowCondition(int $kind, array $groups, AccessLevel $min): array
    {
        return $this->visibleIds(fn (DbQuery $q) => $q
            ->where(Expression::integer(1))->equals($this->filter->rowCondition($kind, $groups, $min, 'd.id')));
    }

    /**
     * @param list<int> $groups
     * @return list<int>
     */
    private function byRecordIds(int $kind, array $groups, AccessLevel $min): array
    {
        return $this->visibleIds(fn (DbQuery $q) => $q
            ->where('d.id')->in($this->filter->recordIds($kind, $groups, $min)));
    }

    // AK1 - rowCondition returns the expected set, each record once

    public function testRowConditionReturnsTheExpectedRecordsEachOnce(): void
    {
        self::assertSame(range(1, 9), $this->byRowCondition(self::KIND_A, self::PERSON_A, AccessLevel::View));
        self::assertSame(range(3, 9), $this->byRowCondition(self::KIND_A, self::PERSON_A, AccessLevel::Edit));
        self::assertSame(range(6, 9), $this->byRowCondition(self::KIND_A, self::PERSON_A, AccessLevel::All));
        self::assertSame(range(10, 17), $this->byRowCondition(self::KIND_A, self::PERSON_B, AccessLevel::View));
        self::assertSame(range(14, 17), $this->byRowCondition(self::KIND_A, self::PERSON_B, AccessLevel::Edit));
        self::assertSame([17], $this->byRowCondition(self::KIND_A, self::PERSON_B, AccessLevel::All));
    }

    public function testRowConditionOverSeveralGroupsListsARecordOnceNotOncePerGroup(): void
    {
        $groups = [...self::PERSON_A, ...self::PERSON_B, 30];

        self::assertSame(range(1, self::DOCS), $this->byRowCondition(self::KIND_A, $groups, AccessLevel::View));
    }

    public function testRowConditionIsOneConditionAmongOthers(): void
    {
        $ids = $this->visibleIds(fn (DbQuery $q) => $q
            ->where(Expression::integer(1))->equals(
                $this->filter->rowCondition(self::KIND_A, self::PERSON_A, AccessLevel::View, 'd.id'),
            )
            ->and('d.id')->greater(4)
            ->and('d.id')->lower(8));

        self::assertSame([5, 6, 7], $ids);
    }

    public function testRowConditionBehindAnotherConditionGivesTheExpectedSet(): void
    {
        // Since dbquery 1.3.1 a subquery value is inlined in every condition, not only in the first one.
        $ids = $this->visibleIds(fn (DbQuery $q) => $q
            ->where('d.tenant')->equals('north')
            ->and(Expression::integer(1))->equals(
                $this->filter->rowCondition(self::KIND_A, self::PERSON_A, AccessLevel::Edit, 'd.id'),
            ));

        self::assertSame(range(3, 9), $ids);
        self::assertSame(
            [14, 15, 16, 17],
            $this->visibleIds(fn (DbQuery $q) => $q
                ->where('d.tenant')->equals('south')
                ->and(Expression::integer(1))->equals(
                    $this->filter->rowCondition(self::KIND_A, self::PERSON_B, AccessLevel::Edit, 'd.id'),
                )),
        );
    }

    public function testRecordIdsBehindAnotherConditionGivesTheExpectedSet(): void
    {
        $north = $this->visibleIds(fn (DbQuery $q) => $q
            ->where('d.tenant')->equals('north')
            ->and('d.id')->in($this->filter->recordIds(self::KIND_A, self::PERSON_A, AccessLevel::Edit)));
        $south = $this->visibleIds(fn (DbQuery $q) => $q
            ->where('d.tenant')->equals('south')
            ->and('d.id')->in($this->filter->recordIds(self::KIND_A, self::PERSON_B, AccessLevel::Edit)));

        self::assertSame(range(3, 9), $north);
        self::assertSame([14, 15, 16, 17], $south);
    }

    public function testBothFormsBehindSeveralConditionsAndTogether(): void
    {
        $ids = $this->visibleIds(fn (DbQuery $q) => $q
            ->where('d.tenant')->equals('north')
            ->and('d.id')->greater(2)
            ->and(Expression::integer(1))->equals(
                $this->filter->rowCondition(self::KIND_A, self::PERSON_A, AccessLevel::View, 'd.id'),
            )
            ->and('d.id')->in($this->filter->recordIds(self::KIND_A, self::PERSON_A, AccessLevel::Edit)));

        self::assertSame(range(3, 9), $ids);
    }

    public function testRowConditionBindsAnUnqualifiedKeyColumnToTheOuterRow(): void
    {
        $prepared = (new DbQuery())
            ->select('id')
            ->from('acl_docs')
            ->where(Expression::integer(1))->equals(
                $this->filter->rowCondition(self::KIND_A, self::PERSON_B, AccessLevel::Edit, 'id'),
            )
            ->orderBy('id')
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        self::assertSame(
            [14, 15, 16, 17],
            array_map(static fn (array $row): int => (int) $row['id'], $this->executor->fetchAll($prepared)),
        );
    }

    // AK2 - recordIds gives the same set as rowCondition, for each minimum level

    public function testRecordIdsReturnsTheSameSetAsRowConditionForEachMinimumLevel(): void
    {
        foreach ([self::PERSON_A, self::PERSON_B, [...self::PERSON_A, ...self::PERSON_B]] as $groups) {
            foreach ([AccessLevel::View, AccessLevel::Edit, AccessLevel::All] as $min) {
                self::assertSame(
                    $this->byRowCondition(self::KIND_A, $groups, $min),
                    $this->byRecordIds(self::KIND_A, $groups, $min),
                    sprintf('groups %s, minimum %s', implode(',', $groups), $min->name),
                );
            }
        }
        self::assertSame(range(3, 9), $this->byRecordIds(self::KIND_A, self::PERSON_A, AccessLevel::Edit));
        self::assertSame([17], $this->byRecordIds(self::KIND_A, self::PERSON_B, AccessLevel::All));
    }

    public function testRecordIdsOverSeveralGroupsListsARecordOnce(): void
    {
        $sub = $this->filter->recordIds(self::KIND_A, [10, 11, 12], AccessLevel::View);
        $prepared = $sub->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);
        $numbers = array_map(
            static fn (array $row): int => (int) $row['record_id'],
            $this->executor->fetchAll($prepared),
        );

        self::assertCount(9, $numbers);
        self::assertSame(range(1, 9), (static function (array $n): array {
            sort($n);

            return $n;
        })($numbers));
    }

    // AK3 - visible in the filter <=> levelOf >= minimum (E1-I3), over every record, person and minimum

    public function testVisibleInTheFilterExactlyWhenLevelOfReachesTheMinimum(): void
    {
        $people = [
            'A' => self::PERSON_A,
            'B' => self::PERSON_B,
            'A+B' => [...self::PERSON_A, ...self::PERSON_B],
            'outsider' => [99],
            'nobody' => [],
        ];

        foreach ([self::KIND_A, self::KIND_B] as $kind) {
            foreach ($people as $name => $groups) {
                foreach ([AccessLevel::View, AccessLevel::Edit, AccessLevel::All] as $min) {
                    $expected = [];
                    for ($record = 1; $record <= self::DOCS; $record++) {
                        $level = $this->store->levelOf($kind, $record, $groups);
                        if ($level !== null && $level->value >= $min->value) {
                            $expected[] = $record;
                        }
                    }

                    $case = sprintf('kind %d, person %s, minimum %s', $kind, $name, $min->name);
                    self::assertSame($expected, $this->byRowCondition($kind, $groups, $min), $case . ' (row)');
                    self::assertSame($expected, $this->byRecordIds($kind, $groups, $min), $case . ' (ids)');
                }
            }
        }
    }

    // AK4 - empty group list sees nothing; entries of another kind with the same number do not count

    public function testAnEmptyGroupListSeesNothingInBothForms(): void
    {
        foreach ([AccessLevel::View, AccessLevel::Edit, AccessLevel::All] as $min) {
            self::assertSame([], $this->byRowCondition(self::KIND_A, [], $min));
            self::assertSame([], $this->byRecordIds(self::KIND_A, [], $min));
        }
    }

    public function testEntriesOfAnotherKindWithTheSameNumberDoNotCount(): void
    {
        // Group 10 holds "all" of 15-20 in kind B only; in kind A it reaches 1-9.
        self::assertSame(range(15, 20), $this->byRowCondition(self::KIND_B, [10], AccessLevel::All));
        self::assertSame(range(1, 9), $this->byRowCondition(self::KIND_A, [10, 11, 12], AccessLevel::View));
        self::assertSame(range(1, 5), $this->byRowCondition(self::KIND_B, [20], AccessLevel::All));
        self::assertSame([], $this->byRowCondition(3, self::PERSON_A, AccessLevel::View));
        self::assertSame([], $this->byRecordIds(3, self::PERSON_A, AccessLevel::View));
    }

    // Ground rules of the filter itself

    public function testFilterUsesTheTableNameItIsGiven(): void
    {
        $this->connection->pdo()->exec('DROP TABLE IF EXISTS acl_custom');
        $this->connection->pdo()->exec(
            'CREATE TABLE acl_custom (kind INT NOT NULL, record_id BIGINT NOT NULL, '
            . 'group_id BIGINT NOT NULL, level SMALLINT NOT NULL, PRIMARY KEY (kind, record_id, group_id))'
        );
        (new AclStore($this->connection, 'acl_custom'))->grant(self::KIND_A, 2, 77, AccessLevel::View);
        $custom = new AclFilter('acl_custom');

        $ids = $this->visibleIds(fn (DbQuery $q) => $q
            ->where(Expression::integer(1))->equals($custom->rowCondition(self::KIND_A, [77], AccessLevel::View, 'd.id')));
        $this->connection->pdo()->exec('DROP TABLE acl_custom');

        self::assertSame([2], $ids);
    }

    public function testInvalidTableNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AclFilter('acl_entries; DROP TABLE x');
    }

    public function testAKeyColumnThatIsNoIdentifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->filter->rowCondition(self::KIND_A, [1], AccessLevel::View, 'id) OR (1=1');
    }

    public function testBuildingTheFilterNeedsNoConnectionAndRunsNothing(): void
    {
        $filter = new AclFilter();

        self::assertInstanceOf(
            DbQueryBuilderInterface::class,
            $filter->rowCondition(self::KIND_A, [1], AccessLevel::View, 'id'),
        );
        self::assertInstanceOf(
            DbQueryBuilderInterface::class,
            $filter->recordIds(self::KIND_A, [1], AccessLevel::View),
        );
    }
}
