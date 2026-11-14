<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\{
    AbstractRelentity,
    RelentityInterface,
    Relation\ManyToMany,
    Relation\OneToMany,
};

class InvalidRelations extends AbstractRelentity implements RelentityInterface
{
    public const string TABLE_NAME = 'invalid';
    public const string PRIMARY_KEY = 'invalidUUID';

    public const array RELATIONS = [
        'unknownOption' => [FileAttribute::class, OneToMany::class, 'tragetKey' => 'x'],
        'notARelation' => [FileAttribute::class, FileAttribute::class],
        'notAnEntity' => [\stdClass::class, OneToMany::class],
        'wrongType' => [FileAttribute::class, OneToMany::class, 'count' => 'yes'],
        'missingThrough' => [Tag::class, ManyToMany::class],
        'missingThroughWithOptions' => [Tag::class, ManyToMany::class, 'count' => true],
        'modifyThroughWithTable' => [Tag::class, ManyToMany::class,
            'through' => 'file_tag',
            'modifyThrough' => static function (RelentityInterface $links): RelentityInterface {
                return $links;
            },
        ],
    ];
}
