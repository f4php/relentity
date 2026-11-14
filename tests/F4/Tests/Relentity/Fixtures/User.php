<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\{
    AbstractRelentity,
    RelentityInterface,
};

class User extends AbstractRelentity implements RelentityInterface
{
    public const string TABLE_NAME = 'user';
    public const string PRIMARY_KEY = 'userUUID';
}
