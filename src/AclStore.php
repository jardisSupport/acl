<?php

declare(strict_types=1);

namespace JardisSupport\Acl;

use Closure;
use JardisSupport\Acl\Data\AccessDecision;
use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Data\Entry;
use JardisSupport\Acl\Handler\Entry\DecideAccess;
use JardisSupport\Acl\Handler\Entry\FindEntries;
use JardisSupport\Acl\Handler\Entry\FindHighestLevel;
use JardisSupport\Acl\Handler\Entry\GrantEntries;
use JardisSupport\Acl\Handler\Entry\GrantEntry;
use JardisSupport\Acl\Handler\Entry\HasEntriesOfGroup;
use JardisSupport\Acl\Handler\Entry\IsLastFullEntry;
use JardisSupport\Acl\Handler\Entry\MoveEntries;
use JardisSupport\Acl\Handler\Entry\ReassignGroup;
use JardisSupport\Acl\Handler\Entry\RemoveRecord;
use JardisSupport\Acl\Handler\Entry\RevokeEntry;
use JardisSupport\Acl\Handler\Entry\UpsertEntries;
use JardisSupport\Acl\Handler\Table\AssertTableName;
use JardisSupport\Contract\DbConnection\DbConnectionInterface;
use JardisSupport\Repository\Handler\QueryExecutor;

/**
 * Orchestrates the access entries of records on a database connection via jardissupport/dbquery.
 *
 * One entry says: group G may reach record R of information kind K up to level L. The store knows only
 * these numbers. It opens no transaction; the caller brackets calls that must succeed or fail together
 * (jardissupport/repository RunInTransaction). The table DDL ships in resources/sql/<driver>.sql.
 */
final class AclStore
{
    private readonly Closure $findHighestLevel;
    private readonly Closure $decideAccess;
    private readonly Closure $grantEntry;
    private readonly Closure $grantEntries;
    private readonly Closure $revokeEntry;
    private readonly Closure $isLastFullEntry;
    private readonly Closure $removeRecord;
    private readonly Closure $reassignGroup;
    private readonly Closure $hasEntriesOfGroup;
    private readonly Closure $findEntries;

    public function __construct(DbConnectionInterface $connection, string $table = 'acl_entries')
    {
        $table = (new AssertTableName())->__invoke($table);
        $executor = new QueryExecutor($connection);
        $upsertEntries = (new UpsertEntries($executor, $table))->__invoke(...);
        $moveEntries = (new MoveEntries($executor, $upsertEntries, $table))->__invoke(...);

        $this->findHighestLevel = (new FindHighestLevel($executor, $table))->__invoke(...);
        $this->decideAccess = (new DecideAccess($this->findHighestLevel))->__invoke(...);
        $this->grantEntry = (new GrantEntry($upsertEntries))->__invoke(...);
        $this->grantEntries = (new GrantEntries($upsertEntries))->__invoke(...);
        $this->revokeEntry = (new RevokeEntry($executor, $table))->__invoke(...);
        $this->isLastFullEntry = (new IsLastFullEntry($executor, $table))->__invoke(...);
        $this->removeRecord = (new RemoveRecord($executor, $table))->__invoke(...);
        $this->reassignGroup = (new ReassignGroup($executor, $moveEntries, $table))->__invoke(...);
        $this->hasEntriesOfGroup = (new HasEntriesOfGroup($executor, $table))->__invoke(...);
        $this->findEntries = (new FindEntries($executor, $table))->__invoke(...);
    }

    /**
     * Highest level any of the groups holds on the record; null when none holds an entry (also for an
     * empty group list, an unknown kind or an unknown record).
     *
     * @param list<int> $groups
     */
    public function levelOf(int $kind, int $recordId, array $groups): ?AccessLevel
    {
        return ($this->findHighestLevel)($kind, $recordId, $groups);
    }

    /**
     * Decides whether the groups reach the record at the required level, from the same view as levelOf():
     * Granted exactly when levelOf() is at least the required level; NotVisible when levelOf() is null (no row,
     * empty group list, other information kind); otherwise TooLow. The caller branches, it never compares.
     *
     * @param list<int> $groups
     */
    public function check(int $kind, int $recordId, array $groups, AccessLevel $required): AccessDecision
    {
        return ($this->decideAccess)($kind, $recordId, $groups, $required);
    }

    /** Gives the group a level on the record; an entry the group already holds is overwritten. */
    public function grant(int $kind, int $recordId, int $group, AccessLevel $level): void
    {
        ($this->grantEntry)($kind, $recordId, $group, $level);
    }

    /**
     * Gives several groups a level each on the record: an empty list writes nothing, a group named twice keeps
     * the last level, an entry a group already holds is overwritten (as grant()).
     *
     * Runs one statement per distinct group and opens no transaction: the caller sets it (jardissupport/repository
     * RunInTransaction), otherwise an interrupted call leaves the entries partly written.
     *
     * @param list<Entry> $entries
     */
    public function grantAll(int $kind, int $recordId, array $entries): void
    {
        ($this->grantEntries)($kind, $recordId, $entries);
    }

    /** Removes the entry of the group on the record; a group without an entry is a no-op. */
    public function revoke(int $kind, int $recordId, int $group): void
    {
        ($this->revokeEntry)($kind, $recordId, $group);
    }

    /** True when the group holds level "all" on the record and no other group does. */
    public function isLastFullEntry(int $kind, int $recordId, int $group): bool
    {
        return ($this->isLastFullEntry)($kind, $recordId, $group);
    }

    /** Removes every entry of the record (and no other) and returns how many were removed. */
    public function removeRecord(int $kind, int $recordId): int
    {
        return ($this->removeRecord)($kind, $recordId);
    }

    /**
     * Moves every entry of the group $from to the group $to and returns how many entries left $from.
     * Where $to already holds an entry on the same record, the higher level stays.
     *
     * Runs several statements and opens no transaction: the caller sets it (jardissupport/repository
     * RunInTransaction), otherwise an interrupted call leaves the entries partly moved.
     */
    public function reassignGroup(int $from, int $to): int
    {
        return ($this->reassignGroup)($from, $to);
    }

    /** True when at least one entry, of any information kind, carries the group (E4: delete a group). */
    public function hasEntriesOfGroup(int $group): bool
    {
        return ($this->hasEntriesOfGroup)($group);
    }

    /**
     * Every entry of the record, ordered by group.
     *
     * @return list<Entry>
     */
    public function entriesOf(int $kind, int $recordId): array
    {
        return ($this->findEntries)($kind, $recordId);
    }
}
