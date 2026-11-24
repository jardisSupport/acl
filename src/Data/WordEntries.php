<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Data;

/**
 * Outcome of turning pairs group -> level word into entries: the entries of the pairs with a valid word and the
 * groups whose word is none of `view`, `edit`, `all`.
 */
final readonly class WordEntries
{
    /**
     * @param list<Entry> $entries entries of the pairs with a valid word, in the order of the pairs
     * @param list<int> $invalid groups whose word is invalid, in the order of the pairs
     */
    public function __construct(
        public array $entries,
        public array $invalid,
    ) {
    }
}
