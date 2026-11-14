<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\{
    AbstractRelentity,
    RelentityInterface,
};

class Label extends AbstractRelentity implements RelentityInterface
{
    public const string TABLE_NAME = 'label';
    public const string PRIMARY_KEY = 'labelUUID';
}
