<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use Closure;
use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Data\Entry;

/**
 * Verdict over the state AFTER setting the entries: true exactly when at least one group then holds level "all" on
 * the record - in the list or outside it. A group named twice counts with its last level (as grantAll). Reads the
 * record, writes nothing.
 */
final class KeepsFullEntryWith
{
    /**
     * @param Closure(int, int): list<int> $findFullEntryGroups
     */
    public function __construct(
        private readonly Closure $findFullEntryGroups,
    ) {
    }

    /**
     * @param list<Entry> $entries
     */
    public function __invoke(int $kind, int $recordId, array $entries): bool
    {
        $lastByGroup = [];
        foreach ($entries as $entry) {
            $lastByGroup[$entry->group] = $entry->level;
        }

        foreach ($lastByGroup as $level) {
            if ($level === AccessLevel::All) {
                return true;
            }
        }

        foreach (($this->findFullEntryGroups)($kind, $recordId) as $group) {
            if (!array_key_exists($group, $lastByGroup)) {
                return true;
            }
        }

        return false;
    }
}
