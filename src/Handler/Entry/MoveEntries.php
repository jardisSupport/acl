<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use Closure;
use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Contract\DbQuery\DbPreparedQueryInterface;
use JardisSupport\DbQuery\DbDelete;
use JardisSupport\DbQuery\DbQuery;
use JardisSupport\Repository\Handler\QueryExecutor;

/**
 * Moves a batch of entries from one group to another.
 *
 * Where the target group already holds an entry on the same record, the higher level stays: the
 * entry is overwritten only if the moved level is higher. The moved entries of the source group are
 * removed afterwards. Compared per level in PHP, because dbquery has no GREATEST.
 */
final class MoveEntries
{
    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly Closure $upsertEntries,
        private readonly string $table,
    ) {
    }

    /**
     * @param list<array{kind: int, record_id: int, level: int}> $rows entries of the source group
     */
    public function __invoke(int $from, int $to, array $rows): void
    {
        $levelsByKind = [];
        foreach ($rows as $row) {
            $levelsByKind[$row['kind']][$row['record_id']] = $row['level'];
        }

        foreach ($levelsByKind as $kind => $levels) {
            $recordIds = array_keys($levels);
            $held = $this->levelsHeldBy($to, $kind, $recordIds);

            $idsByLevel = [];
            foreach ($levels as $recordId => $level) {
                if (($held[$recordId] ?? 0) < $level) {
                    $idsByLevel[$level][] = $recordId;
                }
            }
            foreach ($idsByLevel as $level => $ids) {
                ($this->upsertEntries)($kind, $to, AccessLevel::from($level), $ids);
            }

            $this->remove($from, $kind, $recordIds);
        }
    }

    /**
     * @param list<int> $recordIds
     * @return array<int, int> level by record id
     */
    private function levelsHeldBy(int $group, int $kind, array $recordIds): array
    {
        $prepared = (new DbQuery())
            ->select('record_id, level')
            ->from($this->table)
            ->where('kind')->equals($kind)
            ->and('group_id')->equals($group)
            ->and('record_id')->in($recordIds)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        $held = [];
        foreach ($this->executor->fetchAll($prepared) as $row) {
            $held[(int) $row['record_id']] = (int) $row['level'];
        }

        return $held;
    }

    /**
     * @param list<int> $recordIds
     */
    private function remove(int $group, int $kind, array $recordIds): void
    {
        $prepared = (new DbDelete())
            ->from($this->table)
            ->where('kind')->equals($kind)
            ->and('group_id')->equals($group)
            ->and('record_id')->in($recordIds)
            ->sql($this->executor->getDialect(), prepared: true);
        \assert($prepared instanceof DbPreparedQueryInterface);

        $this->executor->execute($prepared);
    }
}
