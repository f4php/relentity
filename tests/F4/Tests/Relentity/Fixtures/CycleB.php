<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;
use F4\Relentity\Relation\{One, RelationInterface};

class CycleB extends AbstractRelentity
{
    public const string TABLE_NAME = 'cycle_b';
    public const string PRIMARY_KEY = 'bUUID';
    public const ?int DEFAULT_LIMIT = null;
    public const array DEFAULT_RELATIONS = ['a'];
    public const array RELATIONS = [
        'a' => static function (): RelationInterface {
            return One::of(CycleA::class);
        },
    ];
}
