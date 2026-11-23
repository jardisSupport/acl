<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Exception;

use RuntimeException;

/**
 * Thrown by {@see \JardisSupport\Acl\AclBackfill::judge()} when a backfill met records with a creator and not one
 * creator resolved to a group: the creator lookup (the translation from creator to group) most likely returned
 * nothing for the whole run. The run itself never throws this.
 *
 * Carries the number of records with a creator, summed over all runs judged.
 */
final class BackfillUnresolved extends RuntimeException
{
    public function __construct(public readonly int $withCreator)
    {
        parent::__construct(sprintf(
            'Backfill refused: %d record(s) with a creator, but no creator resolved to a group.',
            $withCreator,
        ));
    }
}
