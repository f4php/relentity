<?php

declare(strict_types=1);

namespace F4\Tests\Relentity;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use F4\DB;
use F4\Relentity\RelentityInterface;
use F4\Tests\DB\BacktickAdapter;
use F4\Relentity\Relation\{Many, ManyToMany, One, RelationInterface};
use F4\Tests\Relentity\Fixtures\{
    BareEmployee,
    CycleA,
    DefaultRelationEmployee,
    Employee,
    InvalidRelations,
    PeriodEmployee,
    Plain,
    SelfReferencingDefault,
    Timesheet,
};

final class RelationTest extends TestCase
{
    /**
     * The SQL of a single relation, joined to a source table under an alias
     */
    private static function join(string $rows, string $alias, string $sourceTable, string $targetQuery): string
    {
        return sprintf(
            'LEFT JOIN LATERAL (SELECT %s AS "___relation_rows___" FROM (SELECT "%s".*) AS "___source___" CROSS JOIN LATERAL (%s) AS "___subquery___") AS "%s" ON true',
            $rows,
            $sourceTable,
            $targetQuery,
            $alias,
        );
    }
    private static function one(string $alias, string $sourceTable, string $targetQuery): string
    {
        return self::join('to_jsonb("___subquery___".*)', $alias, $sourceTable, $targetQuery);
    }
    private static function many(string $alias, string $sourceTable, string $targetQuery): string
    {
        return self::join('jsonb_agg(to_jsonb("___subquery___".*))', $alias, $sourceTable, $targetQuery);
    }
    private static function counted(string $alias, string $sourceTable, string $targetQuery): string
    {
        return self::join('"___subquery___"."count"', $alias, $sourceTable, $targetQuery);
    }
    /**
     * A BareEmployee query with a single relation
     */
    private static function employeeWith(string $alias, string $join): string
    {
        return sprintf('SELECT "employeeUUID", "%1$s"."___relation_rows___" AS "%1$s" FROM "employee" %2$s', $alias, $join);
    }

    private const string EMPLOYEE_TARGET = 'SELECT "employeeUUID", "name" FROM "employee" WHERE "deleted" = FALSE AND %s ORDER BY "name" ASC LIMIT %d';

    public static function relationProvider(): array
    {
        return [
            'One by default links the primary key of the target' => [
                'department',
                self::one('department', 'employee', 'SELECT "department".* FROM "department" WHERE ("___source___"."departmentUUID" = "department"."departmentUUID") LIMIT 1'),
            ],
            'One with a column of the same name on both sides' => [
                'departmentById',
                self::one('departmentById', 'employee', 'SELECT "department".* FROM "department" WHERE ("___source___"."departmentId" = "department"."departmentId") LIMIT 1'),
            ],
            'One to the same table, with differently named columns and the defaults of the target' => [
                'manager',
                self::one('manager', 'employee', sprintf(self::EMPLOYEE_TARGET, '("___source___"."managerUUID" = "employee"."employeeUUID")', 1)),
            ],
            'Many by default links the primary key of the source' => [
                'timesheets',
                self::many('timesheets', 'employee', 'SELECT "timesheet".* FROM "timesheet" WHERE ("___source___"."employeeUUID" = "timesheet"."employeeUUID") ORDER BY "date" DESC LIMIT 100'),
            ],
            'Many to the same table' => [
                'reports',
                self::many('reports', 'employee', sprintf(self::EMPLOYEE_TARGET, '("___source___"."employeeUUID" = "employee"."managerUUID")', 50)),
            ],
            'Many with a customized target' => [
                'recentTimesheets',
                self::many('recentTimesheets', 'employee', 'SELECT "timesheet".* FROM "timesheet" WHERE "approved" = TRUE AND ("___source___"."employeeUUID" = "timesheet"."employeeUUID") ORDER BY "date" DESC LIMIT 5'),
            ],
            'Many declared as a first-class callable' => [
                'approvedTimesheets',
                self::many('approvedTimesheets', 'employee', 'SELECT "timesheet".* FROM "timesheet" WHERE "approved" = TRUE AND ("___source___"."employeeUUID" = "timesheet"."employeeUUID") ORDER BY "date" DESC LIMIT 100'),
            ],
            'Many of a count() target' => [
                'timesheetCount',
                self::counted('timesheetCount', 'employee', 'SELECT COUNT(*) AS "count" FROM "timesheet" WHERE ("___source___"."employeeUUID" = "timesheet"."employeeUUID")'),
            ],
            'Many with a custom ConditionCollection, used as is' => [
                'colleagues',
                self::many('colleagues', 'employee', sprintf(self::EMPLOYEE_TARGET, '("employee"."departmentUUID" = "___source___"."departmentUUID" AND "employee"."enabled" = TRUE)', 50)),
            ],
            'via a link table with the default columns' => [
                'projects',
                self::many('projects', 'employee', 'SELECT "project".* FROM "project" WHERE EXISTS(SELECT 1 FROM "employee_project" WHERE (("___source___"."employeeUUID" = "employee_project"."employeeUUID") AND ("employee_project"."projectUUID" = "project"."projectUUID"))) LIMIT 100'),
            ],
            'via a link table with differently named columns' => [
                'skills',
                self::many('skills', 'employee', 'SELECT "skill".* FROM "skill" WHERE EXISTS(SELECT 1 FROM "employee_skill" WHERE (("___source___"."employeeUUID" = "employee_skill"."employeeId") AND ("employee_skill"."skillId" = "skill"."skillUUID"))) LIMIT 100'),
            ],
            'via a link entity applies its conditions and drops its limit' => [
                'verifiedSkills',
                self::many('verifiedSkills', 'employee', 'SELECT "skill".* FROM "skill" WHERE EXISTS(SELECT 1 FROM "employee_skill" WHERE "revoked" = FALSE AND "verified" = TRUE AND (("___source___"."employeeUUID" = "employee_skill"."employeeId") AND ("employee_skill"."skillId" = "skill"."skillUUID"))) LIMIT 100'),
            ],
            'via with custom ConditionCollections' => [
                'linkedColleagues',
                self::many('linkedColleagues', 'employee', sprintf(self::EMPLOYEE_TARGET, 'EXISTS(SELECT 1 FROM "employee_colleague" WHERE (("employee_colleague"."a" = "___source___"."employeeUUID") AND ("employee_colleague"."b" = "employee"."employeeUUID")))', 50)),
            ],
            'via of a count() target' => [
                'projectCount',
                self::counted('projectCount', 'employee', 'SELECT COUNT(*) AS "count" FROM "project" WHERE EXISTS(SELECT 1 FROM "employee_project" WHERE (("___source___"."employeeUUID" = "employee_project"."employeeUUID") AND ("employee_project"."projectUUID" = "project"."projectUUID")))'),
            ],
            'nested relations of the target' => [
                'projectsWithClients',
                self::many('projectsWithClients', 'employee', sprintf(
                    'SELECT "project".*, "client"."___relation_rows___" AS "client" FROM "project" %s WHERE EXISTS(SELECT 1 FROM "employee_project" WHERE (("___source___"."employeeUUID" = "employee_project"."employeeUUID") AND ("employee_project"."projectUUID" = "project"."projectUUID"))) LIMIT 100',
                    self::one('client', 'project', 'SELECT "client".* FROM "client" WHERE ("___source___"."clientUUID" = "client"."clientUUID") LIMIT 1'),
                )),
            ],
        ];
    }

    #[DataProvider('relationProvider')]
    public function testRelation(string $alias, string $join): void
    {
        $this->assertSame(self::employeeWith($alias, $join), BareEmployee::fetch()->with($alias)->asSQL());
    }

    public function testRelationsAreAddedAfterTheColumnsAndBeforeTheConditions(): void
    {
        $this->assertSame(
            sprintf(
                'SELECT "employeeUUID", "name", "department"."___relation_rows___" AS "department", "timesheetCount"."___relation_rows___" AS "timesheetCount" FROM "employee" %s %s WHERE "deleted" = FALSE ORDER BY "name" ASC LIMIT 50',
                self::one('department', 'employee', 'SELECT "department".* FROM "department" WHERE ("___source___"."departmentUUID" = "department"."departmentUUID") LIMIT 1'),
                self::counted('timesheetCount', 'employee', 'SELECT COUNT(*) AS "count" FROM "timesheet" WHERE ("___source___"."employeeUUID" = "timesheet"."employeeUUID")'),
            ),
            Employee::fetch()->with('department', 'timesheetCount')->asSQL(),
        );
    }

    public function testRelationsFollowJoinedTables(): void
    {
        $this->assertSame(
            sprintf(
                'SELECT "employeeUUID", "department"."___relation_rows___" AS "department" FROM "employee" JOIN "employee_project" USING ("employeeUUID") %s',
                self::one('department', 'employee', 'SELECT "department".* FROM "department" WHERE ("___source___"."departmentUUID" = "department"."departmentUUID") LIMIT 1'),
            ),
            BareEmployee::fetch()->joinedWith('employee_project', 'employeeUUID')->with('department')->asSQL(),
        );
    }

    public function testManyToManyIsMany(): void
    {
        $this->assertSame(
            BareEmployee::fetch()->with(['x' => fn() => Many::of(Plain::class)->via('link')])->asSQL(),
            BareEmployee::fetch()->with(['x' => fn() => ManyToMany::of(Plain::class)->via('link')])->asSQL(),
        );
    }

    public function testDefaultRelations(): void
    {
        $sql = DefaultRelationEmployee::fetch()->asSQL();
        $this->assertStringContainsString('"department"."___relation_rows___" AS "department"', $sql);
        $this->assertStringContainsString('"timesheetCount"."___relation_rows___" AS "timesheetCount"', $sql);
    }

    public function testTargetGivenAsAClassExcludesItsDefaultRelations(): void
    {
        $this->assertSame(
            self::employeeWith('x', self::many('x', 'employee', 'SELECT "employeeUUID" FROM "employee" WHERE ("___source___"."employeeUUID" = "employee"."employeeUUID")')),
            BareEmployee::fetch()->with(['x' => fn() => Many::of(DefaultRelationEmployee::class)])->asSQL(),
        );
    }

    public function testTargetGivenAsAStatementKeepsItsDefaultRelations(): void
    {
        $sql = BareEmployee::fetch()->with(['x' => fn() => Many::of(DefaultRelationEmployee::fetch())])->asSQL();

        $this->assertStringContainsString('"department"."___relation_rows___" AS "department"', $sql);
        $this->assertStringContainsString('"timesheetCount"."___relation_rows___" AS "timesheetCount"', $sql);
    }

    public function testTargetGivenAsAStatementKeepsRelationsAddedWithWith(): void
    {
        $this->assertStringContainsString(
            '"manager"."___relation_rows___" AS "manager"',
            BareEmployee::fetch()->with(['x' => fn() => Many::of(Employee::fetch()->with('manager'))])->asSQL(),
        );
    }

    public function testDefaultRelationToTheSameTableIsIncludedOnce(): void
    {
        $this->assertSame(
            sprintf(
                'SELECT "person".*, "manager"."___relation_rows___" AS "manager" FROM "person" %s',
                self::one('manager', 'person', 'SELECT "person".* FROM "person" WHERE ("___source___"."managerUUID" = "person"."personUUID") LIMIT 1'),
            ),
            SelfReferencingDefault::fetch()->asSQL(),
        );
    }

    public function testDefaultRelationsOfEntitiesReferringToEachOtherAreIncludedOnce(): void
    {
        $this->assertSame(
            sprintf(
                'SELECT "cycle_a".*, "b"."___relation_rows___" AS "b" FROM "cycle_a" %s',
                self::one('b', 'cycle_a', 'SELECT "cycle_b".* FROM "cycle_b" WHERE ("___source___"."bUUID" = "cycle_b"."bUUID") LIMIT 1'),
            ),
            CycleA::fetch()->asSQL(),
        );
    }

    public function testConcealedColumnsOfADefaultRelationToTheSameTable(): void
    {
        $this->assertSame([], SelfReferencingDefault::fetch()->getConcealedColumns());
    }

    public function testWithoutExcludesDefaultRelations(): void
    {
        $this->assertSame(
            self::employeeWith('timesheetCount', self::counted('timesheetCount', 'employee', 'SELECT COUNT(*) AS "count" FROM "timesheet" WHERE ("___source___"."employeeUUID" = "timesheet"."employeeUUID")')),
            DefaultRelationEmployee::fetch()->without('department')->asSQL(),
        );
        $this->assertSame('SELECT "employeeUUID" FROM "employee"', DefaultRelationEmployee::fetch()->without('department', 'timesheetCount')->asSQL());
    }

    public function testWithoutExcludesRelationsAddedWithWith(): void
    {
        $this->assertSame('SELECT "employeeUUID" FROM "employee"', BareEmployee::fetch()->with('manager')->without('manager')->asSQL());
    }

    public function testWithoutAnUnknownRelationIsIgnored(): void
    {
        $this->assertSame('SELECT "employeeUUID" FROM "employee"', BareEmployee::fetch()->without('unknown')->asSQL());
    }

    public function testWithTheSameRelationTwiceIncludesItOnce(): void
    {
        $this->assertSame(BareEmployee::fetch()->with('manager')->asSQL(), BareEmployee::fetch()->with('manager')->with('manager')->asSQL());
    }

    public function testWithReplacesADeclaredRelation(): void
    {
        $this->assertSame(
            self::employeeWith('timesheets', self::many('timesheets', 'employee', 'SELECT "timesheet".* FROM "timesheet" WHERE "approved" = TRUE AND ("___source___"."employeeUUID" = "timesheet"."employeeUUID") ORDER BY "date" DESC LIMIT 100')),
            BareEmployee::fetch()->with(['timesheets' => fn() => Many::of(Timesheet::approved())])->asSQL(),
        );
    }

    public function testWithAddsAnUndeclaredRelation(): void
    {
        $this->assertSame(
            self::employeeWith('since', self::many('since', 'employee', 'SELECT "timesheet".* FROM "timesheet" WHERE "date" >= \'2026-11-01\' AND ("___source___"."employeeUUID" = "timesheet"."employeeUUID") ORDER BY "date" DESC LIMIT 100')),
            BareEmployee::fetch()->with(['since' => fn() => Many::of(Timesheet::fetch()->matching(['"date" >= {#}' => '2026-11-01']))])->asSQL(),
        );
    }

    private const string PERIOD_TIMESHEETS = 'SELECT "timesheet".* FROM "timesheet" WHERE "date" >= \'2026-11-01\' AND "date" <= \'2026-11-30\' AND ("___source___"."employeeUUID" = "timesheet"."employeeUUID") ORDER BY "date" DESC LIMIT 100';

    public function testRelationDependsOnThePropertiesOfTheSource(): void
    {
        $this->assertSame(
            self::employeeWith('timesheets', self::many('timesheets', 'employee', self::PERIOD_TIMESHEETS)),
            PeriodEmployee::fetch()->inPeriod('2026-11-01', '2026-11-30')->with('timesheets')->asSQL(),
        );
    }

    public function testSourcePropertiesSetAfterWithApply(): void
    {
        $this->assertSame(
            PeriodEmployee::fetch()->inPeriod('2026-11-01', '2026-11-30')->with('timesheets')->asSQL(),
            PeriodEmployee::fetch()->with('timesheets')->inPeriod('2026-11-01', '2026-11-30')->asSQL(),
        );
    }

    public function testRelationWithoutSourcePropertiesIsUnfiltered(): void
    {
        $this->assertSame(
            self::employeeWith('timesheets', self::many('timesheets', 'employee', 'SELECT "timesheet".* FROM "timesheet" WHERE ("___source___"."employeeUUID" = "timesheet"."employeeUUID") ORDER BY "date" DESC LIMIT 100')),
            PeriodEmployee::fetch()->with('timesheets')->asSQL(),
        );
    }

    public function testClonedSourceKeepsItsProperties(): void
    {
        $employees = PeriodEmployee::fetch()->with('timesheets')->inPeriod('2026-11-01', '2026-11-30');

        $this->assertStringContainsString(self::PERIOD_TIMESHEETS, (clone $employees)->limitedTo(10)->asSQL());
    }

    public function testNestedRelationReceivesItsOwnSource(): void
    {
        $received = [];
        $target = PeriodEmployee::fetch()->inPeriod('2026-11-01', '2026-11-30')->with(['nested' => function (RelentityInterface $source) use (&$received): RelationInterface {
            $received[] = $source;
            return Many::of(Plain::class);
        }]);
        $top = BareEmployee::fetch()->with(['x' => fn() => Many::of($target)]);
        $top->asQuery();

        $this->assertNotEmpty($received);
        foreach ($received as $source) {
            $this->assertInstanceOf(PeriodEmployee::class, $source);
            $this->assertNotSame($top, $source);
            $this->assertSame(['2026-11-01', '2026-11-30'], [$source->from, $source->to]);
        }
    }

    public function testRelationDefinitionReceivesTheSource(): void
    {
        $received = null;
        $employees = BareEmployee::fetch();
        $employees->with(['x' => function (RelentityInterface $source) use (&$received): RelationInterface {
            $received = $source;
            return Many::of(Plain::class);
        }])->asQuery();

        $this->assertSame($employees, $received);
    }

    public function testRelationDefinitionsAreCalledOncePerQuery(): void
    {
        $calls = 0;
        BareEmployee::fetch()->with(['x' => function () use (&$calls): RelationInterface {
            $calls++;
            return Many::of(Plain::class);
        }])->asQuery();

        $this->assertSame(1, $calls);
    }

    public function testRelationsAreBuiltAnewForEveryQuery(): void
    {
        $calls = 0;
        $employees = BareEmployee::fetch()->with(['x' => function () use (&$calls): RelationInterface {
            $calls++;
            return Many::of(Plain::class);
        }]);
        $employees->asQuery();
        $employees->asQuery();

        $this->assertSame(2, $calls);
    }

    public function testRelationsAreNotBuiltForOtherStatements(): void
    {
        $this->assertSame('SELECT COUNT(*) AS "count" FROM "employee"', DefaultRelationEmployee::count()->asSQL());
    }

    public function testRelationUsesTheAdapterOfTheQuery(): void
    {
        $query = BareEmployee::fetch()->with('department')->asQuery();
        $query->useAdapter(new BacktickAdapter());

        $this->assertSame(
            'SELECT `employeeUUID`, `department`.`___relation_rows___` AS `department` FROM `employee` LEFT JOIN LATERAL (SELECT to_jsonb(`___subquery___`.*) AS `___relation_rows___` FROM (SELECT `employee`.*) AS `___source___` CROSS JOIN LATERAL (SELECT `department`.* FROM `department` WHERE (`___source___`.`departmentUUID` = `department`.`departmentUUID`) LIMIT 1) AS `___subquery___`) AS `department` ON true',
            $query->asSQL(),
        );
    }

    public static function invalidRelationProvider(): array
    {
        return [
            'One of a count() target' => [
                static fn() => One::of(Timesheet::count()),
                InvalidArgumentException::class,
                'F4\Relentity\Relation\One does not support Count action',
            ],
            'Many of an add() target' => [
                static fn() => Many::of(Plain::add(['a' => 1])),
                InvalidArgumentException::class,
                'F4\Relentity\Relation\Many does not support Add action',
            ],
            'Many of a remove() target' => [
                static fn() => Many::of(Plain::remove()),
                InvalidArgumentException::class,
                'F4\Relentity\Relation\Many does not support Remove action',
            ],
            'a class that is not an entity' => [
                static fn() => Many::of(stdClass::class),
                InvalidArgumentException::class,
                'Relation must be a F4\Relentity\RelentityInterface class',
            ],
            'a class that does not exist' => [
                static fn() => One::of('Missing\Entity'),
                InvalidArgumentException::class,
                'Relation must be a F4\Relentity\RelentityInterface class',
            ],
            'via a count() entity' => [
                static fn() => Many::of(Plain::class)->via(Plain::count()),
                InvalidArgumentException::class,
                'via() requires Relentity objects to have Fetch action type',
            ],
        ];
    }

    #[DataProvider('invalidRelationProvider')]
    public function testInvalidRelation(Closure $build, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);
        $build();
    }

    public static function invalidConditionProvider(): array
    {
        return [
            'a list' => [['a', 'b']],
            'more than one pair' => [['a' => 'b', 'c' => 'd']],
            'an empty array' => [[]],
            'a value that is not a string' => [['a' => 1]],
            'a numeric key' => [[2 => 'id']],
            'an empty key' => [['' => 'id']],
            'an empty value' => [['id' => '']],
        ];
    }

    public function testEmptyStringCondition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Condition as string must be a non-empty column name');
        BareEmployee::fetch()->with(['x' => fn() => Many::of(Plain::class, '')])->asQuery();
    }

    public function testViaAcceptsTheTableAsANamedArgument(): void
    {
        $this->assertSame(
            BareEmployee::fetch()->with(['x' => fn() => Many::of(Plain::class)->via('link')])->asSQL(),
            BareEmployee::fetch()->with(['x' => fn() => Many::of(Plain::class)->via(table: 'link')])->asSQL(),
        );
    }

    #[DataProvider('invalidConditionProvider')]
    public function testInvalidCondition(array $condition): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Condition as array must contain exactly one array key with one value');
        BareEmployee::fetch()->with(['x' => fn() => Many::of(Plain::class, $condition)])->asQuery();
    }

    #[DataProvider('invalidConditionProvider')]
    public function testInvalidViaCondition(array $condition): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Condition as array must contain exactly one array key with one value');
        BareEmployee::fetch()->with(['x' => fn() => Many::of(Plain::class)->via('link', null, $condition)])->asQuery();
    }

    public static function invalidDeclarationProvider(): array
    {
        return [
            'an unknown alias' => [
                static fn() => BareEmployee::fetch()->with('unknown'),
                InvalidArgumentException::class,
                'Unknown relation unknown',
            ],
            'a definition that is not callable' => [
                static fn() => InvalidRelations::fetch()->with('notCallable')->asQuery(),
                InvalidArgumentException::class,
                'Relation definition must be callable',
            ],
            'a definition that does not return a relation' => [
                static fn() => InvalidRelations::fetch()->with('notARelation')->asQuery(),
                RuntimeException::class,
                'Relation definition must return instance of F4\Relentity\Relation\RelationInterface',
            ],
            'a customization that is not callable' => [
                static fn() => BareEmployee::fetch()->with(['x' => 'not callable']),
                InvalidArgumentException::class,
                'Relation customization must be a callable',
            ],
            'a customization without an alias' => [
                static fn() => BareEmployee::fetch()->with([fn() => Many::of(Plain::class)]),
                InvalidArgumentException::class,
                'Relation customization must have an alias',
            ],
            'a customization with a numeric alias' => [
                static fn() => BareEmployee::fetch()->with(['1' => fn() => Many::of(Plain::class)]),
                InvalidArgumentException::class,
                'Relation customization must have an alias',
            ],
            'a customization with an empty alias' => [
                static fn() => BareEmployee::fetch()->with(['' => fn() => Many::of(Plain::class)]),
                InvalidArgumentException::class,
                'Relation customization must have an alias',
            ],
        ];
    }

    #[DataProvider('invalidDeclarationProvider')]
    public function testInvalidDeclaration(Closure $build, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);
        $build();
    }

    public function testJoinRequiresASource(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot determine source relentity');
        Many::of(Plain::class)->appendJoinExpression(DB::select('*')->from('plain'), 'x');
    }

    public function testSelectExpression(): void
    {
        $this->assertSame(
            'SELECT *, "alias"."___relation_rows___" AS "alias" FROM "plain"',
            One::of(Plain::class)->appendSelectExpression(DB::select('*'), 'alias')->from('plain')->asSQL(),
        );
    }
}
