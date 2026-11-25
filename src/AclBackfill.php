<?php

declare(strict_types=1);

namespace JardisSupport\Acl;

use Closure;
use JardisSupport\Acl\Data\BackfillResult;
use JardisSupport\Acl\Exception\BackfillUnresolved;
use JardisSupport\Acl\Handler\Backfill\BackfillEntries;
use JardisSupport\Acl\Handler\Backfill\FindRecordsWithoutEntry;
use JardisSupport\Acl\Handler\Backfill\JudgeBackfill;
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
    private readonly Closure $judgeBackfill;

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
        $this->judgeBackfill = (new JudgeBackfill())->__invoke(...);
    }

    /**
     * Writes one entry "all" for each record of $sourceTable that holds no entry of the kind.
     *
     * The group is the one $groupOfCreator names for the record's creator (from $creatorColumn, per tenant from
     * $tenantColumn, one call per tenant and batch with the creators seen there; returns group by creator). A
     * record without a creator, or whose creator the closure does not know, goes to $fallbackGroup; without a
     * fallback group it is skipped and stays without an entry. "Without a creator" is a $creatorColumn that holds
     * null, the empty text or 0 (the empty value of a whole-number column). Records with an entry are never
     * touched, so a second run writes nothing. $batch bounds the records read and written at once.
     *
     * $fallbackGroup is a number, or a closure that names the fallback group of a tenant (null: none, the records
     * of that tenant are skipped). The closure is asked at most once per tenant and batch, and only when a record
     * of that tenant needs it. The run never throws a verdict; {@see judge()} gives it.
     *
     * $progress (optional, last argument) is called after each page that was read and written, with a
     * BackfillResult of the running totals up to and including that page (the last call equals the returned
     * result). A source without records calls it never. It changes nothing in the result or in the transaction
     * promises (the run opens none); an exception thrown by it is not caught and ends the run, the caller's
     * bracket decides about the rows written so far. It names no total and no duration: the number of records
     * is not known beforehand.
     *
     * @param Closure(?string, list<string>): array<string, int> $groupOfCreator
     * @param int|Closure(?string): ?int|null $fallbackGroup
     * @param (Closure(BackfillResult): void)|null $progress
     */
    public function run(
        int $kind,
        string $sourceTable,
        string $keyColumn,
        ?string $creatorColumn,
        ?string $tenantColumn,
        Closure $groupOfCreator,
        int|Closure|null $fallbackGroup,
        int $batch = 1000,
        ?Closure $progress = null,
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
            $progress,
        );
    }

    /**
     * Judges the runs of one pass together: when records with a creator were met and not one of them resolved to a
     * group, the creator lookup found nothing and the pass is refused. One resolved creator, or no record with a
     * creator, lets it stand. Pass the results of every run of the pass (several source tables): the verdict
     * counts their sum, not each run.
     *
     * Writes nothing and brackets nothing. Run the runs and this verdict inside ONE transaction of the caller
     * (jardissupport/repository RunInTransaction): the exception then rolls every row of the pass back. A later
     * pass that sees only records left over (no fallback group, no creator resolved) is refused again and writes
     * nothing.
     *
     * @throws BackfillUnresolved with the number of records with a creator
     */
    public function judge(BackfillResult ...$results): void
    {
        ($this->judgeBackfill)(...$results);
    }
}
