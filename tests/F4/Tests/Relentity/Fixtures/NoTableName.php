<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;

class NoTableName extends AbstractRelentity
{
    public const string PRIMARY_KEY = 'id';
}
