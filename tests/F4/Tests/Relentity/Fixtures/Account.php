<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;
use F4\Relentity\Relation\{Many, One, RelationInterface};
use F4\Tests\DB\FixtureAdapter;

/**
 * Executed through FixtureAdapter, which returns preset rows
 */
class Account extends AbstractRelentity
{
    public const string TABLE_NAME = 'account';
    public const string PRIMARY_KEY = 'accountUUID';
    public const ?string DEFAULT_ADAPTER = FixtureAdapter::class;
    public const array DEFAULT_CONCEALING = ['passwordHash', '$.settings.token'];
    public const array RELATIONS = [
        'owner' => static function (): RelationInterface {
            return One::of(Account::class, ['ownerUUID' => 'accountUUID']);
        },
        'members' => static function (): RelationInterface {
            return Many::of(Account::fetch()->with('owner'), ['accountUUID' => 'ownerUUID']);
        },
        'memberCount' => static function (): RelationInterface {
            return Many::of(Account::count(), ['accountUUID' => 'ownerUUID']);
        },
        'revealedOwner' => static function (): RelationInterface {
            return One::of(Account::fetch()->revealing('passwordHash'), ['ownerUUID' => 'accountUUID']);
        },
        'plain' => static function (): RelationInterface {
            return Many::of(Plain::class, 'accountUUID');
        },
    ];
}
