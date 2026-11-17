<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use Closure;
use JardisSupport\Acl\Data\AccessLevel;

/** Gives a group a level on one record: creates the entry or overwrites the level it already holds. */
final class GrantEntry
{
    public function __construct(
        private readonly Closure $upsertEntries,
    ) {
    }

    public function __invoke(int $kind, int $recordId, int $group, AccessLevel $level): void
    {
        ($this->upsertEntries)($kind, $group, $level, [$recordId]);
    }
}
