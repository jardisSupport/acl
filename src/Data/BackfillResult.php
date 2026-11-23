<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Data;

/**
 * Outcome of one backfill run: rows written, how many of them went to the fallback group, records skipped, how many
 * records had a creator and how many of those got the group of their creator.
 */
final readonly class BackfillResult
{
    public function __construct(
        private int $written,
        private int $toFallback,
        private int $skipped,
        private int $withCreator = 0,
        private int $resolved = 0,
    ) {
    }

    /** Entries written in total. */
    public function written(): int
    {
        return $this->written;
    }

    /** Of the written entries, those given to the fallback group (no creator, or none the closure knew). */
    public function toFallback(): int
    {
        return $this->toFallback;
    }

    /** Records left without an entry because neither a creator group nor a fallback group existed. */
    public function skipped(): int
    {
        return $this->skipped;
    }

    /** Records the run looked at that carry a creator (not null, not an empty string), whatever became of them. */
    public function withCreator(): int
    {
        return $this->withCreator;
    }

    /** Of the records with a creator, those that got the group of that creator (not the fallback group). */
    public function resolved(): int
    {
        return $this->resolved;
    }
}
