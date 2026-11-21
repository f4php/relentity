<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;

class Department extends AbstractRelentity
{
    public const string TABLE_NAME = 'department';
    public const string PRIMARY_KEY = 'departmentUUID';
}
