<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Data;

/** Outcome of one backfill run: rows written, how many of them went to the fallback group, records skipped. */
final readonly class BackfillResult
{
    public function __construct(
        private int $written,
        private int $toFallback,
        private int $skipped,
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
}
