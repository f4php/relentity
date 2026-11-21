<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;

class NoPrimaryKey extends AbstractRelentity
{
    public const string TABLE_NAME = 'no_primary_key';
}
