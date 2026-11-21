<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;

class Skill extends AbstractRelentity
{
    public const string TABLE_NAME = 'skill';
    public const string PRIMARY_KEY = 'skillUUID';
}
