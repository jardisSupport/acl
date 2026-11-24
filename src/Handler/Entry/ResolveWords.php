<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Handler\Entry;

use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Data\Entry;
use JardisSupport\Acl\Data\WordEntries;

/**
 * Turns pairs group -> level word into entries. Needs no connection. A word other than `view`, `edit`, `all`
 * (including '' and any other spelling) makes its group invalid; nothing is thrown.
 */
final class ResolveWords
{
    /**
     * @param array<int, string> $words level word by group
     */
    public function __invoke(array $words): WordEntries
    {
        $entries = [];
        $invalid = [];
        foreach ($words as $group => $word) {
            $level = AccessLevel::fromWord($word);
            if ($level === null) {
                $invalid[] = $group;
                continue;
            }
            $entries[] = new Entry($group, $level);
        }

        return new WordEntries($entries, $invalid);
    }
}
