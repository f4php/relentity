<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\DB\{
    Fragment,
    QueryBuilderInterface,
};
use F4\Relentity\{
    AbstractRelentity,
    RelentityInterface,
    Relation\ManyToMany,
    Relation\ManyToOne,
    Relation\OneToMany,
    Relation\OneToOne,
};

class File extends AbstractRelentity implements RelentityInterface
{
    public const string TABLE_NAME = 'file';
    public const string PRIMARY_KEY = 'fileUUID';

    public const array RELATIONS = [
        'attributes' => [FileAttribute::class, OneToMany::class],
        'attributesShorthand' => FileAttribute::class,
        'attributesDefaultType' => [FileAttribute::class],
        'visibleAttributes' => [FileAttribute::class, OneToMany::class,
            'modifyTarget' => FileAttribute::visible(...),
        ],
        'recentAttributes' => [FileAttribute::class, OneToMany::class,
            'modifyTarget' => static function (FileAttribute $attributes): FileAttribute {
                return $attributes
                    ->matching(['visible' => true])
                    ->sortedBy(['created' => 'desc'])
                    ->limitedTo(5);
            },
        ],
        'attributeCount' => [FileAttribute::class, OneToMany::class, 'count' => true],
        'attributeCountDefaultType' => [FileAttribute::class, 'count' => true],
        'ownedAttributesDefaultType' => [FileAttribute::class, 'sourceKey' => 'ownerUUID', 'targetKey' => 'ownerUUID'],
        'owner' => [User::class, ManyToOne::class, 'sourceKey' => 'ownerUUID'],
        'user' => [User::class, ManyToOne::class],
        'primaryAttribute' => [FileAttribute::class, OneToOne::class],
        'ownedAttribute' => [FileAttribute::class, OneToOne::class, 'sourceKey' => 'ownerUUID', 'targetKey' => 'ownerUUID'],
        'tags' => [Tag::class, ManyToMany::class, 'through' => FileTag::class],
        'activeTags' => [Tag::class, ManyToMany::class,
            'through' => FileTag::class,
            'modifyThrough' => static function (FileTag $links): FileTag {
                return $links->matching(['active' => true]);
            },
        ],
        'tagCount' => [Tag::class, ManyToMany::class, 'through' => FileTag::class, 'count' => true],
        'labels' => [Label::class, ManyToMany::class, 'through' => 'file_label'],
        'linkedLabels' => [Label::class, ManyToMany::class,
            'through' => 'file_label_link',
            'throughSourceKey' => 'fileId',
            'throughTargetKey' => 'labelId',
        ],
        'account' => [Account::class, ManyToOne::class],
        'accountProfile' => [Account::class, OneToOne::class],
        'accounts' => [Account::class, OneToMany::class],
        'linkedAccounts' => [Account::class, ManyToMany::class, 'through' => 'file_account'],
        'accountCount' => [Account::class, OneToMany::class, 'count' => true],
        'revealedAccount' => [Account::class, ManyToOne::class,
            'modifyTarget' => static function (Account $accounts): Account {
                return $accounts->revealing('passwordHash', 'token');
            },
        ],
        'partlyRevealedAccount' => [Account::class, ManyToOne::class,
            'modifyTarget' => static function (Account $accounts): Account {
                return $accounts->revealing('token')->concealing('email');
            },
        ],
        'deepConcealedAccount' => [Account::class, ManyToOne::class,
            'modifyTarget' => static function (Account $accounts): Account {
                return $accounts->concealing('$.settings.secret', '$.settings["api.key"]');
            },
        ],
        'custom' => [Label::class, OneToMany::class,
            'customSelectCallback' => static function (QueryBuilderInterface $query, RelentityInterface $source, string $alias): QueryBuilderInterface {
                return $query->select(new Fragment("'custom' AS {#::#}", [$query->escapeIdentifier($alias)]));
            },
            'customJoinCallback' => static function (QueryBuilderInterface $query): QueryBuilderInterface {
                return $query;
            },
        ],
    ];
}
