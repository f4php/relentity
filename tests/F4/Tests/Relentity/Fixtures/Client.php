<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;

class Client extends AbstractRelentity
{
    public const string TABLE_NAME = 'client';
    public const string PRIMARY_KEY = 'clientUUID';
}
