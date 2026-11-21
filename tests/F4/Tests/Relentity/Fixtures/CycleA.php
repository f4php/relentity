<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;
use F4\Relentity\Relation\{One, RelationInterface};

/**
 * Lists a relation to CycleB in DEFAULT_RELATIONS, which lists one back
 */
class CycleA extends AbstractRelentity
{
    public const string TABLE_NAME = 'cycle_a';
    public const string PRIMARY_KEY = 'aUUID';
    public const ?int DEFAULT_LIMIT = null;
    public const array DEFAULT_RELATIONS = ['b'];
    public const array RELATIONS = [
        'b' => static function (): RelationInterface {
            return One::of(CycleB::class);
        },
    ];
}
