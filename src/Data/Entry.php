<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Data;

/** One access entry of a record: a group and the level it holds. */
final readonly class Entry
{
    public function __construct(
        public int $group,
        public AccessLevel $level,
    ) {
    }
}
