<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;
use F4\Tests\DB\FixtureAdapter;

/**
 * Executed through FixtureAdapter, without concealment
 */
class Note extends AbstractRelentity
{
    public const string TABLE_NAME = 'note';
    public const string PRIMARY_KEY = 'noteUUID';
    public const ?string DEFAULT_ADAPTER = FixtureAdapter::class;
}
