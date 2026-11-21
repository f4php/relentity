<?php

declare(strict_types=1);

namespace F4\Tests\Relentity;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use F4\DB;
use F4\DB\{ConditionCollection, Fragment};
use F4\Relentity\{ActionType, RelentityInterface};
use F4\Relentity\Exception\RelentityException;
use F4\Tests\DB\FixtureAdapter;
use F4\Tests\Relentity\Fixtures\{
    Employee,
    NoPrimaryKey,
    NoReturning,
    NoTableName,
    Note,
    Plain,
    UnknownDefaultRelation,
};

final class RelentityTest extends TestCase
{
    private const string EMPLOYEE_DEFAULTS = 'SELECT "employeeUUID", "name" FROM "employee" WHERE "deleted" = FALSE ORDER BY "name" ASC LIMIT 50';

    protected function setUp(): void
    {
        FixtureAdapter::reset();
    }

    public static function statementProvider(): array
    {
        return [
            'fetch without defaults selects all columns of the table' => [
                static fn() => Plain::fetch(),
                'SELECT "plain".* FROM "plain" LIMIT 100',
            ],
            'fetch with a wildcard' => [
                static fn() => Plain::fetch('*'),
                'SELECT * FROM "plain" LIMIT 100',
            ],
            'fetch with columns' => [
                static fn() => Plain::fetch(['a', 'b']),
                'SELECT "a", "b" FROM "plain" LIMIT 100',
            ],
            'fetch applies all defaults' => [
                static fn() => Employee::fetch(),
                self::EMPLOYEE_DEFAULTS,
            ],
            'fetch columns replace DEFAULT_SELECT' => [
                static fn() => Employee::fetch(['email']),
                'SELECT "email" FROM "employee" WHERE "deleted" = FALSE ORDER BY "name" ASC LIMIT 50',
            ],
            'count' => [
                static fn() => Plain::count(),
                'SELECT COUNT(*) AS "count" FROM "plain"',
            ],
            'count applies DEFAULT_WHERE only' => [
                static fn() => Employee::count(),
                'SELECT COUNT(*) AS "count" FROM "employee" WHERE "deleted" = FALSE',
            ],
            'count with conditions' => [
                static fn() => Employee::count()->matching(['enabled' => true]),
                'SELECT COUNT(*) AS "count" FROM "employee" WHERE "deleted" = FALSE AND "enabled" = TRUE',
            ],
            'add' => [
                static fn() => Plain::add(['a' => 'x', 'b' => 1]),
                'INSERT INTO "plain" ("a", "b") VALUES (\'x\', 1) RETURNING *',
            ],
            'add ignores DEFAULT_WHERE' => [
                static fn() => Employee::add(['name' => 'Eve']),
                'INSERT INTO "employee" ("name") VALUES (\'Eve\') RETURNING *',
            ],
            'change applies DEFAULT_WHERE' => [
                static fn() => Employee::change(['enabled' => false])->matching(['employeeUUID' => 'e1']),
                'UPDATE "employee" SET "enabled" = FALSE WHERE "deleted" = FALSE AND "employeeUUID" = \'e1\' RETURNING *',
            ],
            'remove applies DEFAULT_WHERE' => [
                static fn() => Employee::remove(),
                'DELETE FROM "employee" WHERE "deleted" = FALSE RETURNING *',
            ],
            'DEFAULT_RETURNING null omits RETURNING' => [
                static fn() => NoReturning::remove(),
                'DELETE FROM "no_returning"',
            ],
            'change and remove ignore ordering and limits' => [
                static fn() => Employee::change(['enabled' => false])->sortedBy(['name' => 'asc'])->limitedTo(1)->skipping(1),
                'UPDATE "employee" SET "enabled" = FALSE WHERE "deleted" = FALSE RETURNING *',
            ],
        ];
    }

    #[DataProvider('statementProvider')]
    public function testStatement(Closure $build, string $expected): void
    {
        $this->assertSame($expected, $build()->asSQL());
    }

    public static function fetchProvider(): array
    {
        return [
            'selecting replaces DEFAULT_SELECT' => [
                static fn() => Employee::fetch()->selecting('email'),
                'SELECT "email" FROM "employee" WHERE "deleted" = FALSE ORDER BY "name" ASC LIMIT 50',
            ],
            'selecting several columns' => [
                static fn() => Employee::fetch()->selecting('email', 'name'),
                'SELECT "email", "name" FROM "employee" WHERE "deleted" = FALSE ORDER BY "name" ASC LIMIT 50',
            ],
            'selecting replaces fetch columns' => [
                static fn() => Employee::fetch(['name'])->selecting(['email']),
                'SELECT "email" FROM "employee" WHERE "deleted" = FALSE ORDER BY "name" ASC LIMIT 50',
            ],
            'matching adds to DEFAULT_WHERE and to earlier calls' => [
                static fn() => Employee::fetch()->matching(['enabled' => true])->matching(['name' => 'Ann']),
                'SELECT "employeeUUID", "name" FROM "employee" WHERE "deleted" = FALSE AND "enabled" = TRUE AND "name" = \'Ann\' ORDER BY "name" ASC LIMIT 50',
            ],
            'matching several filters in one call' => [
                static fn() => Plain::fetch()->matching(['a' => 1], ['b' => 2]),
                'SELECT "plain".* FROM "plain" WHERE "a" = 1 AND "b" = 2 LIMIT 100',
            ],
            'matching a list' => [
                static fn() => Plain::fetch()->matching(['a' => [1, 2]]),
                'SELECT "plain".* FROM "plain" WHERE "a" IN (1,2) LIMIT 100',
            ],
            'matching null' => [
                static fn() => Plain::fetch()->matching(['a' => null]),
                'SELECT "plain".* FROM "plain" WHERE "a" IS NULL LIMIT 100',
            ],
            'matching an expression with a placeholder' => [
                static fn() => Plain::fetch()->matching(['"date" >= {#}' => '2026-01-01']),
                'SELECT "plain".* FROM "plain" WHERE "date" >= \'2026-01-01\' LIMIT 100',
            ],
            'matching a qualified column' => [
                static fn() => Plain::fetch()->matching(['other.a' => 1]),
                'SELECT "plain".* FROM "plain" WHERE "other"."a" = 1 LIMIT 100',
            ],
            'matching a ConditionCollection' => [
                static fn() => Plain::fetch()->matching(new ConditionCollection(new Fragment('"a" > "b"'))),
                'SELECT "plain".* FROM "plain" WHERE ("a" > "b") LIMIT 100',
            ],
            'a custom filter method' => [
                static fn() => Employee::fetch()->enabled(),
                'SELECT "employeeUUID", "name" FROM "employee" WHERE "deleted" = FALSE AND "enabled" = TRUE ORDER BY "name" ASC LIMIT 50',
            ],
            'sortedBy replaces DEFAULT_ORDER' => [
                static fn() => Employee::fetch()->sortedBy(['email' => 'desc']),
                'SELECT "employeeUUID", "name" FROM "employee" WHERE "deleted" = FALSE ORDER BY "email" DESC LIMIT 50',
            ],
            'sortedBy replaces earlier calls' => [
                static fn() => Employee::fetch()->sortedBy(['email' => 'desc'])->sortedBy(['name' => 'desc']),
                'SELECT "employeeUUID", "name" FROM "employee" WHERE "deleted" = FALSE ORDER BY "name" DESC LIMIT 50',
            ],
            'sortedBy with several criteria' => [
                static fn() => Employee::fetch()->sortedBy(['email' => 'desc', 'name' => 'asc']),
                'SELECT "employeeUUID", "name" FROM "employee" WHERE "deleted" = FALSE ORDER BY "email" DESC, "name" ASC LIMIT 50',
            ],
            'sortedBy without arguments removes the ordering' => [
                static fn() => Employee::fetch()->sortedBy(),
                'SELECT "employeeUUID", "name" FROM "employee" WHERE "deleted" = FALSE LIMIT 50',
            ],
        ];
    }

    #[DataProvider('fetchProvider')]
    public function testFetch(Closure $build, string $expected): void
    {
        $this->assertSame($expected, $build()->asSQL());
    }

    public static function limitProvider(): array
    {
        return [
            'DEFAULT_LIMIT' => [
                static fn(Plain $plain) => $plain,
                'SELECT "plain".* FROM "plain" LIMIT 100',
            ],
            'limitedTo replaces the limit' => [
                static fn(Plain $plain) => $plain->limitedTo(10),
                'SELECT "plain".* FROM "plain" LIMIT 10',
            ],
            'limitedTo with skipping' => [
                static fn(Plain $plain) => $plain->limitedTo(10, skipping: 20),
                'SELECT "plain".* FROM "plain" LIMIT 10 OFFSET 20',
            ],
            'limitedTo(null) removes the limit' => [
                static fn(Plain $plain) => $plain->limitedTo(null),
                'SELECT "plain".* FROM "plain"',
            ],
            'unlimited removes the limit' => [
                static fn(Plain $plain) => $plain->limitedTo(10)->unlimited(),
                'SELECT "plain".* FROM "plain"',
            ],
            'skipping keeps the limit' => [
                static fn(Plain $plain) => $plain->skipping(20),
                'SELECT "plain".* FROM "plain" LIMIT 100 OFFSET 20',
            ],
            'limitedTo without skipping keeps earlier skipping' => [
                static fn(Plain $plain) => $plain->skipping(20)->limitedTo(5),
                'SELECT "plain".* FROM "plain" LIMIT 5 OFFSET 20',
            ],
            'unlimited keeps skipping' => [
                static fn(Plain $plain) => $plain->skipping(5)->unlimited(),
                'SELECT "plain".* FROM "plain" OFFSET 5',
            ],
            'skipping(0) removes the offset' => [
                static fn(Plain $plain) => $plain->skipping(20)->skipping(0),
                'SELECT "plain".* FROM "plain" LIMIT 100',
            ],
        ];
    }

    #[DataProvider('limitProvider')]
    public function testLimit(Closure $modify, string $expected): void
    {
        $this->assertSame($expected, $modify(Plain::fetch())->asSQL());
    }

    public static function joinProvider(): array
    {
        return [
            'a string condition joins USING the column' => [
                static fn() => Employee::fetch()->joinedWith('employee_project', 'employeeUUID')->matching(['employee_project.projectUUID' => 'p2']),
                'SELECT "employeeUUID", "name" FROM "employee" JOIN "employee_project" USING ("employeeUUID") WHERE "deleted" = FALSE AND "employee_project"."projectUUID" = \'p2\' ORDER BY "name" ASC LIMIT 50',
            ],
            'an array condition joins ON it' => [
                static fn() => Plain::fetch()->joinedWith('other', [new Fragment('"other"."id" = "plain"."otherId"')]),
                'SELECT "plain".* FROM "plain" JOIN "other" ON "other"."id" = "plain"."otherId" LIMIT 100',
            ],
            'a ConditionCollection joins ON it' => [
                static fn() => Plain::fetch()->joinedWith('other', new ConditionCollection(new Fragment('"other"."id" = "plain"."otherId"'))),
                'SELECT "plain".* FROM "plain" JOIN "other" ON ("other"."id" = "plain"."otherId") LIMIT 100',
            ],
            'a join method' => [
                static fn() => Plain::fetch()->joinedWith('other', 'id', 'leftJoin'),
                'SELECT "plain".* FROM "plain" LEFT JOIN "other" USING ("id") LIMIT 100',
            ],
            'several joins in order' => [
                static fn() => Plain::fetch()->joinedWith('a', 'id')->joinedWith('b', 'id', 'innerJoin'),
                'SELECT "plain".* FROM "plain" JOIN "a" USING ("id") INNER JOIN "b" USING ("id") LIMIT 100',
            ],
            'a cross join without a condition' => [
                static fn() => Plain::fetch()->joinedWith('other', method: 'crossJoin'),
                'SELECT "plain".* FROM "plain" CROSS JOIN "other" LIMIT 100',
            ],
            'a natural join without a condition' => [
                static fn() => Plain::fetch()->joinedWith('other', method: 'naturalJoin'),
                'SELECT "plain".* FROM "plain" NATURAL JOIN "other" LIMIT 100',
            ],
            'a natural outer join without a condition' => [
                static fn() => Plain::fetch()->joinedWith('other', method: 'naturalLeftOuterJoin'),
                'SELECT "plain".* FROM "plain" NATURAL LEFT OUTER JOIN "other" LIMIT 100',
            ],
            'a lateral cross join of a subquery' => [
                static fn() => Plain::fetch()->joinedWith(
                    ['latest' => DB::select('*')->from('other')->where([new Fragment('"other"."plainId" = "plain"."plainUUID"')])->limit(1)],
                    method: 'crossJoinLateral',
                ),
                'SELECT "plain".* FROM "plain" CROSS JOIN LATERAL (SELECT * FROM "other" WHERE "other"."plainId" = "plain"."plainUUID" LIMIT 1) AS "latest" LIMIT 100',
            ],
        ];
    }

    #[DataProvider('joinProvider')]
    public function testJoinedWith(Closure $build, string $expected): void
    {
        $this->assertSame($expected, $build()->asSQL());
    }

    public function testAsQueryColumnsOverrideTheSelection(): void
    {
        $this->assertSame(
            'SELECT "email" FROM "employee" WHERE "deleted" = FALSE ORDER BY "name" ASC LIMIT 50',
            Employee::fetch()->asQuery(['email'])->asSQL(),
        );
    }

    public function testAsQueryReturningOverridesDefaultReturning(): void
    {
        $this->assertSame(
            'DELETE FROM "employee" WHERE "deleted" = FALSE RETURNING "employeeUUID"',
            Employee::remove()->asQuery(returning: 'employeeUUID')->asSQL(),
        );
    }

    public function testAsQueryDoesNotApplyReturningToFetch(): void
    {
        $this->assertSame(self::EMPLOYEE_DEFAULTS, Employee::fetch()->asQuery(returning: 'employeeUUID')->asSQL());
    }

    public function testChainedMethodsChangeTheStatement(): void
    {
        $employees = Employee::fetch();
        $employees->matching(['enabled' => true]);

        $this->assertStringContainsString('"enabled" = TRUE', $employees->asSQL());
    }

    public function testClonedStatementsAreIndependent(): void
    {
        $enabled = Employee::fetch()->matching(['enabled' => true])->with('manager');
        $newest = (clone $enabled)->sortedBy(['hiredAt' => 'desc'])->limitedTo(10)->without('manager');
        $named = (clone $enabled)->matching(['name' => 'Ann'])->concealing('email');

        $this->assertSame(Employee::fetch()->matching(['enabled' => true])->with('manager')->asSQL(), $enabled->asSQL());
        $this->assertSame(['passwordHash', '$.manager.passwordHash'], $enabled->getConcealedColumns());
        $this->assertSame(
            'SELECT "employeeUUID", "name" FROM "employee" WHERE "deleted" = FALSE AND "enabled" = TRUE ORDER BY "hiredAt" DESC LIMIT 10',
            $newest->asSQL(),
        );
        $this->assertStringContainsString('"enabled" = TRUE AND "name" = \'Ann\'', $named->asSQL());
        $this->assertSame(['passwordHash', '$.manager.passwordHash', 'email'], $named->getConcealedColumns());
    }

    public function testStatementsCanBeBuiltRepeatedly(): void
    {
        $employees = Employee::fetch()->matching(['enabled' => true])->with('manager');
        $this->assertSame($employees->asSQL(), $employees->asSQL());
    }

    public static function actionTypeProvider(): array
    {
        return [
            'fetch' => [static fn() => Plain::fetch(), ActionType::Fetch],
            'count' => [static fn() => Plain::count(), ActionType::Count],
            'add' => [static fn() => Plain::add(['a' => 1]), ActionType::Add],
            'change' => [static fn() => Plain::change(['a' => 1]), ActionType::Change],
            'remove' => [static fn() => Plain::remove(), ActionType::Remove],
        ];
    }

    #[DataProvider('actionTypeProvider')]
    public function testActionType(Closure $build, ActionType $expected): void
    {
        $this->assertSame($expected, $build()->getActionType());
    }

    public function testTableNameAndPrimaryKey(): void
    {
        $employees = Employee::fetch();
        $this->assertSame('employee', $employees->getTableName());
        $this->assertSame('employeeUUID', $employees->getPrimaryKey());
    }

    public static function invalidDefinitionProvider(): array
    {
        return [
            'missing TABLE_NAME' => [static fn() => NoTableName::fetch(), RelentityException::class, 'must define TABLE_NAME'],
            'missing PRIMARY_KEY' => [static fn() => NoPrimaryKey::fetch(), RelentityException::class, 'must define PRIMARY_KEY'],
            'unknown DEFAULT_RELATIONS entry' => [static fn() => UnknownDefaultRelation::fetch(), InvalidArgumentException::class, 'Relation missing undefined'],
            'add without values' => [static fn() => Plain::add([]), InvalidArgumentException::class, 'Missing data for add action'],
            'change without changes' => [static fn() => Plain::change([]), InvalidArgumentException::class, 'Missing data for change action'],
        ];
    }

    #[DataProvider('invalidDefinitionProvider')]
    public function testInvalidDefinition(Closure $build, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);
        $build();
    }

    public static function unsupportedActionProvider(): array
    {
        $statements = [
            'count' => static fn() => Plain::count(),
            'add' => static fn() => Plain::add(['a' => 1]),
            'change' => static fn() => Plain::change(['a' => 1]),
            'remove' => static fn() => Plain::remove(),
        ];
        $methods = [
            'selecting' => [static fn(RelentityInterface $entity) => $entity->selecting('a'), ['count', 'add', 'change', 'remove']],
            'with' => [static fn(RelentityInterface $entity) => $entity->with('a'), ['count', 'add', 'change', 'remove']],
            'without' => [static fn(RelentityInterface $entity) => $entity->without('a'), ['count', 'add', 'change', 'remove']],
            'joinedWith' => [static fn(RelentityInterface $entity) => $entity->joinedWith('a', 'id'), ['count', 'add', 'change', 'remove']],
            'sortedBy' => [static fn(RelentityInterface $entity) => $entity->sortedBy(['a' => 'asc']), ['count']],
            'limitedTo' => [static fn(RelentityInterface $entity) => $entity->limitedTo(1), ['count', 'remove']],
            'skipping' => [static fn(RelentityInterface $entity) => $entity->skipping(1), ['count', 'remove']],
            'asRow' => [static fn(RelentityInterface $entity) => $entity->asRow(), ['count']],
            'asTable' => [static fn(RelentityInterface $entity) => $entity->asTable(), ['count']],
        ];
        $cases = [];
        foreach ($methods as $method => [$call, $unsupported]) {
            foreach ($unsupported as $statement) {
                $cases["{$method}() for {$statement}()"] = [$statements[$statement], $call];
            }
        }
        return $cases;
    }

    #[DataProvider('unsupportedActionProvider')]
    public function testUnsupportedAction(Closure $build, Closure $call): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not supported for intended action');
        $call($build());
    }

    public function testUnsupportedJoinMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported join method crossApply');
        Plain::fetch()->joinedWith('other', 'id', 'crossApply');
    }

    public static function joinConditionProvider(): array
    {
        $cases = [];
        foreach (['crossJoin', 'crossJoinLateral', 'naturalJoin', 'naturalLeftOuterJoin', 'naturalRightOuterJoin'] as $method) {
            $cases["{$method}() with a condition"] = [$method, 'id', "{$method}() does not accept a condition"];
        }
        foreach ([
            'fullOuterJoin', 'innerJoin', 'innerJoinLateral', 'join', 'joinLateral',
            'leftJoin', 'leftJoinLateral', 'leftOuterJoin', 'rightJoin', 'rightOuterJoin',
        ] as $method) {
            $cases["{$method}() without a condition"] = [$method, null, "{$method}() requires a condition"];
        }
        return $cases;
    }

    #[DataProvider('joinConditionProvider')]
    public function testJoinCondition(string $method, ?string $condition, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        Plain::fetch()->joinedWith('other', $condition, $method);
    }

    public static function emptyJoinConditionProvider(): array
    {
        return [
            'an empty array' => [[]],
            'an empty string' => [''],
        ];
    }

    #[DataProvider('emptyJoinConditionProvider')]
    public function testEmptyJoinConditionIsMissing(array|string $condition): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('join() requires a condition');
        Plain::fetch()->joinedWith('other', $condition);
    }

    #[DataProvider('emptyJoinConditionProvider')]
    public function testEmptyJoinConditionIsAcceptedWhereNoneIsAllowed(array|string $condition): void
    {
        $this->assertSame(
            'SELECT "plain".* FROM "plain" CROSS JOIN "other" LIMIT 100',
            Plain::fetch()->joinedWith('other', $condition, 'crossJoin')->asSQL(),
        );
    }

    public static function namedArgumentProvider(): array
    {
        return [
            'matching' => [static fn() => Plain::fetch()->matching(status: ['a' => 1])],
            'selecting' => [static fn() => Plain::fetch()->selecting(column: 'a')],
            'sortedBy' => [static fn() => Plain::fetch()->sortedBy(order: ['a' => 'asc'])],
            'with' => [static fn() => Plain::fetch()->with(relation: 'a')],
            'without' => [static fn() => Plain::fetch()->without(relation: 'a')],
            'concealing' => [static fn() => Plain::fetch()->concealing(column: 'a')],
            'revealing' => [static fn() => Plain::fetch()->revealing(column: 'a')],
        ];
    }

    #[DataProvider('namedArgumentProvider')]
    public function testNamedArgumentsAreRejected(Closure $call): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not accept named arguments');
        $call();
    }

    public function testDefaultAdapterExecutesTheStatement(): void
    {
        FixtureAdapter::reset([['noteUUID' => 'n1'], ['noteUUID' => 'n2']]);

        $this->assertSame([['noteUUID' => 'n1'], ['noteUUID' => 'n2']], Note::fetch()->asTable());
        $this->assertCount(1, FixtureAdapter::$statements);
        $this->assertSame('SELECT "note".* FROM "note" LIMIT 100', FixtureAdapter::$statements[0]->query);
    }

    public function testDefaultAdapterReceivesBoundParameters(): void
    {
        Note::fetch()->matching(['noteUUID' => 'n1'])->asTable();

        $this->assertSame('SELECT "note".* FROM "note" WHERE "noteUUID" = $1 LIMIT 100', FixtureAdapter::$statements[0]->query);
        $this->assertSame(['n1'], FixtureAdapter::$statements[0]->parameters);
    }

    public function testAsRowReturnsTheFirstRow(): void
    {
        FixtureAdapter::reset([['noteUUID' => 'n1'], ['noteUUID' => 'n2']]);
        $this->assertSame(['noteUUID' => 'n1'], Note::fetch()->asRow());
    }

    public function testAsRowReturnsNullWithoutRows(): void
    {
        $this->assertNull(Note::fetch()->asRow());
    }

    public function testAsValueByNameAndByIndex(): void
    {
        FixtureAdapter::reset([['noteUUID' => 'n1', 'title' => 'First']]);
        $this->assertSame('First', Note::fetch()->asValue('title'));
        $this->assertSame('First', Note::fetch()->asValue(1));
    }

    public function testCountAsValueIsAnInteger(): void
    {
        FixtureAdapter::reset([['count' => '7']]);
        $this->assertSame(7, Note::count()->asValue());
    }

    public function testCountAsValueWithoutRowsIsZero(): void
    {
        $this->assertSame(0, Note::count()->asValue());
    }

    public function testExecuteRunsTheStatement(): void
    {
        Note::remove()->matching(['noteUUID' => 'n1'])->execute();

        $this->assertCount(1, FixtureAdapter::$statements);
        $this->assertSame('DELETE FROM "note" WHERE "noteUUID" = $1 RETURNING *', FixtureAdapter::$statements[0]->query);
    }

    public function testNothingIsExecutedUntilTheChainEnds(): void
    {
        Note::fetch()->matching(['noteUUID' => 'n1'])->asQuery();
        Note::fetch()->asSQL();

        $this->assertSame([], FixtureAdapter::$statements);
    }
}
