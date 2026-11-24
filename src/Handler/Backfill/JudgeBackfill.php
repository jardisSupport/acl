<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Backfill;

use JardisSupport\Acl\Data\BackfillResult;
use JardisSupport\Acl\Exception\BackfillUnresolved;

/**
 * Judges the results of all backfill runs of one pass together: records with a creator were met, and not one of
 * them resolved to a group. That is the clear case of a creator lookup that found nothing, and it refuses the pass.
 * Any single resolved creator, or no record with a creator at all, lets the pass stand.
 *
 * Writes and reads nothing; the caller decides what refusing means (rolling back its transaction).
 */
final class JudgeBackfill
{
    /**
     * @throws BackfillUnresolved
     */
    public function __invoke(BackfillResult ...$results): void
    {
        $withCreator = 0;
        $resolved = 0;
        foreach ($results as $result) {
            $withCreator += $result->withCreator();
            $resolved += $result->resolved();
        }

        if ($withCreator > 0 && $resolved === 0) {
            throw new BackfillUnresolved($withCreator);
        }
    }
}
