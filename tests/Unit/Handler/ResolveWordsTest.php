<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Unit\Handler;

use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Data\Entry;
use JardisSupport\Acl\Handler\Entry\ResolveWords;
use PHPUnit\Framework\TestCase;

/** The words closure needs no connection: it runs without a database. */
final class ResolveWordsTest extends TestCase
{
    public function testPairsBecomeEntriesInOrderAndInvalidWordsNameTheirGroup(): void
    {
        $result = (new ResolveWords())([10 => 'view', 11 => 'all', 12 => '', 13 => 'ALL', 14 => 'edit']);

        self::assertEquals(
            [new Entry(10, AccessLevel::View), new Entry(11, AccessLevel::All), new Entry(14, AccessLevel::Edit)],
            $result->entries,
        );
        self::assertSame([12, 13], $result->invalid);
    }

    public function testNoPairsGiveNoEntriesAndNoInvalidGroup(): void
    {
        $result = (new ResolveWords())([]);

        self::assertSame([], $result->entries);
        self::assertSame([], $result->invalid);
    }
}
