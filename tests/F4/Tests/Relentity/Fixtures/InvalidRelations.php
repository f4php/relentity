<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;

class InvalidRelations extends AbstractRelentity
{
    public const string TABLE_NAME = 'invalid_relations';
    public const string PRIMARY_KEY = 'id';
    public const array RELATIONS = [
        'notCallable' => Plain::class,
        'notARelation' => static function (): string {
            return 'not a relation';
        },
    ];
}
