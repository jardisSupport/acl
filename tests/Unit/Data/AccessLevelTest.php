<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Unit\Data;

use JardisSupport\Acl\Data\AccessLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The words of the levels: the one spelling for model, body and OpenAPI. */
final class AccessLevelTest extends TestCase
{
    /**
     * @return array<string, array{0: AccessLevel, 1: string}>
     */
    public static function words(): array
    {
        return [
            'view' => [AccessLevel::View, 'view'],
            'edit' => [AccessLevel::Edit, 'edit'],
            'all' => [AccessLevel::All, 'all'],
        ];
    }

    #[DataProvider('words')]
    public function testEveryLevelHasItsWordAndComesBackFromIt(AccessLevel $level, string $word): void
    {
        self::assertSame($word, $level->word());
        self::assertSame($level, AccessLevel::fromWord($word));
    }

    public function testExactlyThreeWordsExist(): void
    {
        $words = array_map(static fn (AccessLevel $level): string => $level->word(), AccessLevel::cases());

        self::assertSame(['view', 'edit', 'all'], $words);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function otherWords(): array
    {
        return [
            'empty' => [''],
            'unknown' => ['read'],
            'upper case' => ['View'],
            'all caps' => ['ALL'],
            'padded' => [' edit'],
            'stored number' => ['1'],
            'enum case name' => ['Edit'],
        ];
    }

    #[DataProvider('otherWords')]
    public function testAnyOtherWordYieldsNull(string $word): void
    {
        self::assertNull(AccessLevel::fromWord($word));
    }
}
