<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;

class UnknownDefaultRelation extends AbstractRelentity
{
    public const string TABLE_NAME = 'unknown_default_relation';
    public const string PRIMARY_KEY = 'id';
    public const array DEFAULT_RELATIONS = ['missing'];
}
