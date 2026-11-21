<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;
use F4\Relentity\Relation\{One, RelationInterface};

class Project extends AbstractRelentity
{
    public const string TABLE_NAME = 'project';
    public const string PRIMARY_KEY = 'projectUUID';
    public const array RELATIONS = [
        'client' => static function (): RelationInterface {
            return One::of(Client::class);
        },
    ];
}
