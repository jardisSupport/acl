<?php

declare(strict_types=1);

namespace JardisSupport\Acl;

use Closure;
use JardisSupport\Acl\Data\AccessDecision;
use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Data\Entry;
use JardisSupport\Acl\Data\WordEntries;
use JardisSupport\Acl\Handler\Entry\DecideAccess;
use JardisSupport\Acl\Handler\Entry\FindEntries;
use JardisSupport\Acl\Handler\Entry\FindFullEntryGroups;
use JardisSupport\Acl\Handler\Entry\FindHighestLevel;
use JardisSupport\Acl\Handler\Entry\GrantEntries;
use JardisSupport\Acl\Handler\Entry\GrantEntry;
use JardisSupport\Acl\Handler\Entry\HasEntriesOfGroup;
use JardisSupport\Acl\Handler\Entry\IsLastFullEntry;
use JardisSupport\Acl\Handler\Entry\KeepsFullEntryWith;
use JardisSupport\Acl\Handler\Entry\KeepsFullEntryWithout;
use JardisSupport\Acl\Handler\Entry\MoveEntries;
use JardisSupport\Acl\Handler\Entry\ReassignGroup;
use JardisSupport\Acl\Handler\Entry\RemoveRecord;
use JardisSupport\Acl\Handler\Entry\ResolveWords;
use JardisSupport\Acl\Handler\Entry\RevokeEntries;
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
    private readonly Closure $keepsFullEntryWith;
    private readonly Closure $keepsFullEntryWithout;
    private readonly Closure $revokeEntries;
    private readonly Closure $resolveWords;

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
        $findFullEntryGroups = (new FindFullEntryGroups($executor, $table))->__invoke(...);
        $this->keepsFullEntryWith = (new KeepsFullEntryWith($findFullEntryGroups))->__invoke(...);
        $this->keepsFullEntryWithout = (new KeepsFullEntryWithout($findFullEntryGroups))->__invoke(...);
        $this->revokeEntries = (new RevokeEntries($executor, $table))->__invoke(...);
        $this->resolveWords = (new ResolveWords())->__invoke(...);
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

    /**
     * Verdict over the state AFTER setting the entries: true exactly when at least one group then holds level "all"
     * on the record, whether it is in the list or outside it. A group named twice counts with its last level (as
     * grantAll). Reads, writes nothing: ask first, then write, both inside the caller's transaction (jardissupport/
     * repository RunInTransaction).
     *
     * @param list<Entry> $entries
     */
    public function keepsFullEntryWith(int $kind, int $recordId, array $entries): bool
    {
        return ($this->keepsFullEntryWith)($kind, $recordId, $entries);
    }

    /**
     * Verdict over the state AFTER removing the groups: true exactly when a group outside the list holds level "all"
     * on the record. An empty list asks for the present state. Reads, writes nothing; the caller sets the transaction
     * as for keepsFullEntryWith().
     *
     * @param list<int> $groups
     */
    public function keepsFullEntryWithout(int $kind, int $recordId, array $groups): bool
    {
        return ($this->keepsFullEntryWithout)($kind, $recordId, $groups);
    }

    /**
     * Removes the entries of several groups on the record: an empty list writes nothing, a group without an entry or
     * named twice is harmless (as revoke()).
     *
     * Runs one statement per 500 groups and opens no transaction: the caller sets it (jardissupport/repository
     * RunInTransaction), otherwise an interrupted call leaves the entries partly removed.
     *
     * @param list<int> $groups
     */
    public function revokeAll(int $kind, int $recordId, array $groups): void
    {
        ($this->revokeEntries)($kind, $recordId, $groups);
    }

    /**
     * Turns pairs group -> level word into entries: the entries of the pairs with a valid word (`view`, `edit`,
     * `all`) in the order of the pairs, and the groups whose word is none of them (including '' and any other
     * spelling). Throws nothing, needs no database; the caller answers the invalid groups.
     *
     * @param array<int, string> $words level word by group
     */
    public function entriesOfWords(array $words): WordEntries
    {
        return ($this->resolveWords)($words);
    }
}
