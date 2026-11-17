<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use Closure;
use JardisSupport\Acl\Data\AccessDecision;
use JardisSupport\Acl\Data\AccessLevel;

/**
 * Decides whether groups reach a record at the required level. It wraps FindHighestLevel, so the decision
 * and levelOf() show the same view (E1-I3): granted exactly when the highest level is at least the required one.
 */
final class DecideAccess
{
    public function __construct(
        private readonly Closure $findHighestLevel,
    ) {
    }

    /**
     * @param list<int> $groups
     */
    public function __invoke(int $kind, int $recordId, array $groups, AccessLevel $required): AccessDecision
    {
        $held = ($this->findHighestLevel)($kind, $recordId, $groups);

        return match (true) {
            $held === null => AccessDecision::NotVisible,
            $held->value >= $required->value => AccessDecision::Granted,
            default => AccessDecision::TooLow,
        };
    }
}
