<?php

declare(strict_types=1);

namespace JardisSupport\Acl;

use Closure;
use JardisSupport\Acl\Data\BackfillResult;
use JardisSupport\Acl\Handler\Backfill\BackfillEntries;
use JardisSupport\Acl\Handler\Backfill\FindRecordsWithoutEntry;
use JardisSupport\Acl\Handler\Entry\UpsertEntries;
use JardisSupport\Acl\Handler\Table\AssertColumnName;
use JardisSupport\Acl\Handler\Table\AssertTableName;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Repository\Handler\QueryExecutor;

/**
 * Backfills access entries for records that exist already: every record of a source table without an entry
 * gets one entry "all", for the group of its creator or else for a fallback group.
 *
 * Like AclStore it knows only numbers and opens no transaction; the caller brackets the run
 * (jardissupport/repository RunInTransaction) when it must succeed or fail as a whole.
 */
final class AclBackfill
{
    private readonly Closure $backfillEntries;

    public function __construct(DbConnectionInterface $connection, string $table = 'acl_entries')
    {
        $table = (new AssertTableName())->__invoke($table);
        $executor = new QueryExecutor($connection);
        $assertTable = (new AssertTableName())->__invoke(...);
        $assertColumn = (new AssertColumnName())->__invoke(...);
        $findRecords = (new FindRecordsWithoutEntry($executor, $table))->__invoke(...);
        $upsertEntries = (new UpsertEntries($executor, $table))->__invoke(...);

        $this->backfillEntries = (new BackfillEntries($assertTable, $assertColumn, $findRecords, $upsertEntries))
            ->__invoke(...);
    }

    /**
     * Writes one entry "all" for each record of $sourceTable that holds no entry of the kind.
     *
     * The group is the one $groupOfCreator names for the record's creator (from $creatorColumn, per tenant from
     * $tenantColumn, one call per tenant and batch with the creators seen there; returns group by creator). A
     * record without a creator, or whose creator the closure does not know, goes to $fallbackGroup; without a
     * fallback group it is skipped and stays without an entry. Records with an entry are never touched, so a
     * second run writes nothing. $batch bounds the records read and written at once.
     *
     * @param Closure(?string, list<string>): array<string, int> $groupOfCreator
     */
    public function run(
        int $kind,
        string $sourceTable,
        string $keyColumn,
        ?string $creatorColumn,
        ?string $tenantColumn,
        Closure $groupOfCreator,
        ?int $fallbackGroup,
        int $batch = 1000,
    ): BackfillResult {
        return ($this->backfillEntries)(
            $kind,
            $sourceTable,
            $keyColumn,
            $creatorColumn,
            $tenantColumn,
            $groupOfCreator,
            $fallbackGroup,
            $batch,
        );
    }
}
