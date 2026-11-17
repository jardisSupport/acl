<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Data;

/**
 * Name and message key of the rule "the last entry with level all stays": the record must keep one group
 * that holds all. acl only knows the question (AclStore::isLastFullEntry); the rejection (422) belongs to
 * the caller, who answers with these two values.
 */
final class AclRule
{
    public const NAME = 'LastFullEntryStays';
    public const MESSAGE_KEY = 'acl.lastFullEntry';

    private function __construct()
    {
    }
}
