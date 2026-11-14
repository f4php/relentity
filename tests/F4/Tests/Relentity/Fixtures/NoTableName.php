<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\{
    AbstractRelentity,
};

// Deliberately not re-implementing RelentityInterface: with it, a missing constant
// is already a compile-time "ambiguous constant" error
class NoTableName extends AbstractRelentity
{
    public const string PRIMARY_KEY = 'id';
}
