<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;

class NoReturning extends AbstractRelentity
{
    public const string TABLE_NAME = 'no_returning';
    public const string PRIMARY_KEY = 'id';
    public const ?string DEFAULT_RETURNING = null;
}
