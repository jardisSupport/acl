<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Data;

/**
 * How far a group may reach into a record: view < edit < all. The integer is the stored value; the word
 * (`view`, `edit`, `all`) is the one spelling for model, request body and OpenAPI.
 */
enum AccessLevel: int
{
    case View = 1;
    case Edit = 2;
    case All = 3;

    /** The word of the level: exactly `view`, `edit` or `all`. */
    public function word(): string
    {
        return match ($this) {
            self::View => 'view',
            self::Edit => 'edit',
            self::All => 'all',
        };
    }

    /** The level of a word; null for any word other than `view`, `edit`, `all` (case-sensitive, no trimming). */
    public static function fromWord(string $word): ?self
    {
        return match ($word) {
            'view' => self::View,
            'edit' => self::Edit,
            'all' => self::All,
            default => null,
        };
    }
}
