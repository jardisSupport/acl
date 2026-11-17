<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Tests\Unit\Data;

use JardisSupport\Acl\Data\AclRule;
use PHPUnit\Framework\TestCase;

/** Name and message key of the rule "the last entry with all stays" are a contract with the 422 of the caller. */
final class AclRuleTest extends TestCase
{
    public function testNameAndMessageKeyAreFixed(): void
    {
        self::assertSame('LastFullEntryStays', AclRule::NAME);
        self::assertSame('acl.lastFullEntry', AclRule::MESSAGE_KEY);
    }
}
