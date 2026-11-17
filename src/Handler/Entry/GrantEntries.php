<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use Closure;
use JardisSupport\Acl\Data\Entry;

/**
 * Gives several groups a level each on one record. An empty list writes nothing; a group named twice keeps the
 * last level; an entry the group already holds is overwritten. One upsert per distinct group, no transaction.
 */
final class GrantEntries
{
    public function __construct(
        private readonly Closure $upsertEntries,
    ) {
    }

    /**
     * @param list<Entry> $entries
     */
    public function __invoke(int $kind, int $recordId, array $entries): void
    {
        $lastByGroup = [];
        foreach ($entries as $entry) {
            $lastByGroup[$entry->group] = $entry;
        }

        foreach ($lastByGroup as $entry) {
            ($this->upsertEntries)($kind, $entry->group, $entry->level, [$recordId]);
        }
    }
}
