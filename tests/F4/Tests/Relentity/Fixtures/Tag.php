<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\{
    AbstractRelentity,
    RelentityInterface,
};

class Tag extends AbstractRelentity implements RelentityInterface
{
    public const string TABLE_NAME = 'tag';
    public const string PRIMARY_KEY = 'tagUUID';
    public const array DEFAULT_ORDER = ['name' => 'asc'];
}
