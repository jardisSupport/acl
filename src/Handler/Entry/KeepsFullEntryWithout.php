<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use Closure;

/**
 * Verdict over the state AFTER removing the groups: true exactly when a group outside the list holds level "all" on
 * the record. An empty list asks for the present state. Reads the record, writes nothing.
 */
final class KeepsFullEntryWithout
{
    /**
     * @param Closure(int, int): list<int> $findFullEntryGroups
     */
    public function __construct(
        private readonly Closure $findFullEntryGroups,
    ) {
    }

    /**
     * @param list<int> $groups
     */
    public function __invoke(int $kind, int $recordId, array $groups): bool
    {
        $removed = array_flip($groups);

        foreach (($this->findFullEntryGroups)($kind, $recordId) as $group) {
            if (!array_key_exists($group, $removed)) {
                return true;
            }
        }

        return false;
    }
}
