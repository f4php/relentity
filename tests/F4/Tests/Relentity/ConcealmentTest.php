<?php

declare(strict_types=1);

namespace F4\Tests\Relentity;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use F4\DB\Exception\ConcealedColumnNotFoundException;
use F4\Relentity\Relation\One;
use F4\Tests\DB\FixtureAdapter;
use F4\Tests\Relentity\Fixtures\{
    Account,
    Employee,
    Plain,
};

final class ConcealmentTest extends TestCase
{
    protected function setUp(): void
    {
        FixtureAdapter::reset();
    }

    /**
     * A row as Account::DEFAULT_CONCEALING expects it to be returned, with $extra merged in
     */
    private static function account(string $uuid, array $extra = []): array
    {
        return [
            'accountUUID' => $uuid,
            'name' => "Account {$uuid}",
            'passwordHash' => "hash {$uuid}",
            'settings' => ['theme' => 'dark', 'token' => "token {$uuid}"],
            ...$extra,
        ];
    }

    /**
     * The same row after Account::DEFAULT_CONCEALING is applied
     */
    private static function concealedAccount(string $uuid, array $extra = []): array
    {
        return [
            'accountUUID' => $uuid,
            'name' => "Account {$uuid}",
            'settings' => ['theme' => 'dark'],
            ...$extra,
        ];
    }

    public static function concealedColumnsProvider(): array
    {
        return [
            'DEFAULT_CONCEALING' => [
                static fn() => Employee::fetch(),
                ['passwordHash'],
            ],
            'nothing to conceal' => [
                static fn() => Plain::fetch(),
                [],
            ],
            'concealing adds to DEFAULT_CONCEALING' => [
                static fn() => Employee::fetch()->concealing('email'),
                ['passwordHash', 'email'],
            ],
            'concealing several keys and arrays of keys' => [
                static fn() => Plain::fetch()->concealing('a', ['b', 'c'])->concealing('d'),
                ['a', 'b', 'c', 'd'],
            ],
            'revealing removes a default' => [
                static fn() => Employee::fetch()->revealing('passwordHash'),
                [],
            ],
            'revealing what is not concealed has no effect' => [
                static fn() => Employee::fetch()->revealing('email'),
                ['passwordHash'],
            ],
            'concealing wins over revealing, called before it' => [
                static fn() => Employee::fetch()->concealing('passwordHash')->revealing('passwordHash'),
                ['passwordHash'],
            ],
            'concealing wins over revealing, called after it' => [
                static fn() => Employee::fetch()->revealing('passwordHash')->concealing('passwordHash'),
                ['passwordHash'],
            ],
            'a key and its single-segment path are the same key' => [
                static fn() => Employee::fetch()->revealing('$.passwordHash'),
                [],
            ],
            'the same key is listed once' => [
                static fn() => Employee::fetch()->concealing('passwordHash', '$.passwordHash'),
                ['$.passwordHash'],
            ],
            'paths into JSON values' => [
                static fn() => Account::fetch()->revealing('passwordHash'),
                ['$.settings.token'],
            ],
            'related rows follow the target, at any depth' => [
                static fn() => Account::fetch()->with('owner', 'members'),
                [
                    'passwordHash',
                    '$.settings.token',
                    '$.owner.passwordHash',
                    '$.owner.settings.token',
                    '$.members.passwordHash',
                    '$.members.settings.token',
                    '$.members.owner.passwordHash',
                    '$.members.owner.settings.token',
                ],
            ],
            'a count() relation adds nothing' => [
                static fn() => Account::fetch()->with('memberCount'),
                ['passwordHash', '$.settings.token'],
            ],
            'a relation without concealment adds nothing' => [
                static fn() => Account::fetch()->with('plain'),
                ['passwordHash', '$.settings.token'],
            ],
            'a customized target keeps its own revealing' => [
                static fn() => Account::fetch()->with('revealedOwner'),
                ['passwordHash', '$.settings.token', '$.revealedOwner.settings.token'],
            ],
            'the source reveals and conceals keys of related rows' => [
                static fn() => Account::fetch()->with('owner', 'members')->revealing('$.owner.passwordHash', '$.members.owner.settings.token')->concealing('$.members.name'),
                [
                    'passwordHash',
                    '$.settings.token',
                    '$.owner.settings.token',
                    '$.members.passwordHash',
                    '$.members.settings.token',
                    '$.members.owner.passwordHash',
                    '$.members.name',
                ],
            ],
            'an alias that needs quoting' => [
                static fn() => Account::fetch()->with(['a.b' => fn() => One::of(Account::class, ['ownerUUID' => 'accountUUID'])]),
                ['passwordHash', '$.settings.token', '$["a.b"].passwordHash', '$["a.b"].settings.token'],
            ],
            'other statements have no relations' => [
                static fn() => Account::change(['name' => 'x'])->concealing('name'),
                ['passwordHash', '$.settings.token', 'name'],
            ],
        ];
    }

    #[DataProvider('concealedColumnsProvider')]
    public function testConcealedColumns(Closure $build, array $expected): void
    {
        $this->assertSame($expected, $build()->getConcealedColumns());
    }

    public function testConcealmentDoesNotChangeTheSql(): void
    {
        $this->assertSame(
            Account::fetch()->with('owner')->asSQL(),
            Account::fetch()->with('owner')->revealing('passwordHash')->concealing('name')->asSQL(),
        );
    }

    public function testFetchConcealsTheRows(): void
    {
        FixtureAdapter::reset([self::account('a1'), self::account('a2')]);

        $this->assertSame([self::concealedAccount('a1'), self::concealedAccount('a2')], Account::fetch()->asTable());
    }

    public function testFetchConcealsRelatedRowsAtAnyDepth(): void
    {
        FixtureAdapter::reset([
            self::account('a1', [
                'owner' => null,
                'members' => [
                    self::account('a2', ['owner' => self::account('a1')]),
                    self::account('a3', ['owner' => self::account('a1')]),
                ],
            ]),
            self::account('a2', [
                'owner' => self::account('a1'),
                'members' => null,
            ]),
        ]);

        $this->assertSame(
            [
                self::concealedAccount('a1', [
                    'owner' => null,
                    'members' => [
                        self::concealedAccount('a2', ['owner' => self::concealedAccount('a1')]),
                        self::concealedAccount('a3', ['owner' => self::concealedAccount('a1')]),
                    ],
                ]),
                self::concealedAccount('a2', [
                    'owner' => self::concealedAccount('a1'),
                    'members' => null,
                ]),
            ],
            Account::fetch()->with('owner', 'members')->asTable(),
        );
    }

    public function testEmptyListsOfRelatedRowsAreSkipped(): void
    {
        FixtureAdapter::reset([self::account('a1', ['members' => []])]);

        $this->assertSame([self::concealedAccount('a1', ['members' => []])], Account::fetch()->with('members')->asTable());
    }

    public function testSourceRevealsAndConcealsKeysOfRelatedRows(): void
    {
        FixtureAdapter::reset([
            self::account('a2', ['owner' => self::account('a1')]),
        ]);

        $this->assertSame(
            [
                self::concealedAccount('a2', [
                    'owner' => [
                        'accountUUID' => 'a1',
                        'passwordHash' => 'hash a1',
                        'settings' => ['theme' => 'dark'],
                    ],
                ]),
            ],
            Account::fetch()->with('owner')->revealing('$.owner.passwordHash')->concealing('$.owner.name')->asTable(),
        );
    }

    public function testConcealmentAppliesBeforeAsRowAndAsValue(): void
    {
        FixtureAdapter::reset([self::account('a1')]);

        $this->assertSame(self::concealedAccount('a1'), Account::fetch()->asRow());
        $this->assertNull(Account::fetch()->asValue('passwordHash'));
        $this->assertSame('Account a1', Account::fetch()->asValue('name'));
    }

    public static function returningProvider(): array
    {
        return [
            'add' => [static fn() => Account::add(['name' => 'x'])],
            'change' => [static fn() => Account::change(['name' => 'x'])],
            'remove' => [static fn() => Account::remove()],
        ];
    }

    #[DataProvider('returningProvider')]
    public function testReturningIsConcealed(Closure $build): void
    {
        FixtureAdapter::reset([self::account('a1')]);

        $this->assertSame(self::concealedAccount('a1'), $build()->asRow());
    }

    public function testCountIsNotConcealed(): void
    {
        FixtureAdapter::reset([['count' => 3]]);

        $this->assertSame(3, Account::count()->asValue());
    }

    public function testStrictModeRejectsAMissingConcealedKey(): void
    {
        FixtureAdapter::reset([['accountUUID' => 'a1', 'settings' => ['token' => 't']]]);

        $this->expectException(ConcealedColumnNotFoundException::class);
        $this->expectExceptionMessage('Concealed key "passwordHash" not found');
        Account::fetch()->selecting('accountUUID', 'settings')->asTable();
    }

    public function testStrictModeRejectsAMissingConcealedKeyOfRelatedRows(): void
    {
        FixtureAdapter::reset([self::account('a1', ['owner' => ['accountUUID' => 'a0']])]);

        $this->expectException(ConcealedColumnNotFoundException::class);
        Account::fetch()->with('owner')->asTable();
    }

    public function testRevealingAKeyThatIsNotSelectedSatisfiesStrictMode(): void
    {
        FixtureAdapter::reset([['accountUUID' => 'a1']]);

        $this->assertSame(
            [['accountUUID' => 'a1']],
            Account::fetch()->selecting('accountUUID')->revealing('passwordHash', '$.settings.token')->asTable(),
        );
    }
}
