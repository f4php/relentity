<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\{
    AbstractRelentity,
    RelentityInterface,
};

class Account extends AbstractRelentity implements RelentityInterface
{
    public const string TABLE_NAME = 'account';
    public const string PRIMARY_KEY = 'accountUUID';
    public const array DEFAULT_CONCEALING = ['passwordHash', 'token'];
}
