<?php

declare(strict_types=1);

namespace JardisSupport\Acl\Data;

/**
 * The answer of AclStore::check(): the caller branches on it and never compares levels itself.
 *
 * Granted: the groups hold at least the required level. NotVisible: no entry reaches the record for these
 * groups (no row, empty group list, other information kind). TooLow: the record is visible, but the highest
 * level the groups hold is below the required one.
 */
enum AccessDecision
{
    case Granted;
    case NotVisible;
    case TooLow;
}
