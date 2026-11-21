<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;
use F4\Relentity\Relation\{One, RelationInterface};

/**
 * A relation to the entity's own table in DEFAULT_RELATIONS
 */
class SelfReferencingDefault extends AbstractRelentity
{
    public const string TABLE_NAME = 'person';
    public const string PRIMARY_KEY = 'personUUID';
    public const ?int DEFAULT_LIMIT = null;
    public const array DEFAULT_RELATIONS = ['manager'];
    public const array RELATIONS = [
        'manager' => static function (): RelationInterface {
            return One::of(SelfReferencingDefault::class, ['managerUUID' => 'personUUID']);
        },
    ];
}
