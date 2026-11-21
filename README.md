# Overview

**Relentity** is an entity-focused layer on top of the [f4php/db](https://github.com/f4php/db) query builder, and a core package of [F4](https://github.com/f4php/f4), a lightweight web development framework.

An entity class describes a table: its primary key, default filters, ordering and limit, and its relations to other entities. Every statement built from it compiles to an ordinary f4php/db `QueryBuilderInterface`, so you can execute it, inspect its SQL, extend it, or add it to a transaction.

Relations are compiled into `LEFT JOIN LATERAL` subqueries that return related rows as JSON, so a single query returns each row together with its related data, and joining relations never multiplies the rows of the main entity.

- [Quick start](#quick-start)
- [Entity defaults](#entity-defaults)
- [Relations](#relations)
  - [Declaring relations](#declaring-relations)
  - [Join conditions](#join-conditions)
  - [Link tables](#link-tables)
  - [Customizing the target](#customizing-the-target)
  - [Choosing relations per query](#choosing-relations-per-query)
  - [Relations of related rows](#relations-of-related-rows)
  - [Completely custom conditions](#completely-custom-conditions)
- [Joining tables](#joining-tables)
- [Concealing columns](#concealing-columns)
- [Reference](#reference)

## Quick start

An entity needs a table name and a primary key:

```php
use F4\Relentity\AbstractRelentity;

class Employees extends AbstractRelentity
{
    public const string TABLE_NAME = 'employee';
    public const string PRIMARY_KEY = 'employeeUUID';
}
```

Every statement starts with a static method, is shaped by chained methods, and ends with a method that executes it:

```php
Employees::fetch()->matching(['email' => $email])->asRow();     // ?array, the first row
Employees::fetch()->matching(['enabled' => true])->asTable();   // array of rows
Employees::count()->matching(['enabled' => true])->asValue();   // int

Employees::add(['name' => 'Eve', 'email' => 'eve@example.com'])->asRow();        // the inserted row
Employees::change(['enabled' => false])->matching(['employeeUUID' => $uuid])->execute();
Employees::remove()->matching(['employeeUUID' => $uuid])->execute();
```

Nothing is executed until the last call, and `asQuery()` or `asSQL()` return the statement instead:

```php
Employees::fetch()->matching(['email' => $email])->asSQL();
// SELECT "employee".* FROM "employee" WHERE "email" = '...' LIMIT 100
```

`matching()` accepts anything that `where()` of f4php/db accepts: `['column' => $value]`, a list for `IN`, `null` for `IS NULL`, an expression with a placeholder such as `['"date" >= {#}' => $since]`, or a `ConditionCollection`. Each call adds to the previous ones with `AND`.

## Entity defaults

Optional constants define how every statement of an entity is built:

```php
class Employees extends AbstractRelentity
{
    public const string TABLE_NAME = 'employee';
    public const string PRIMARY_KEY = 'employeeUUID';

    public const array DEFAULT_SELECT = ['employeeUUID', 'name', 'email', 'departmentUUID', 'managerUUID'];
    public const array DEFAULT_WHERE = ['deleted' => false];
    public const array DEFAULT_ORDER = ['name' => 'asc'];
    public const ?int DEFAULT_LIMIT = 50;

    // a custom filter, reusable in any chain
    public function enabled(): static
    {
        return $this->matching(['enabled' => true]);
    }
}

Employees::fetch()->enabled()->asSQL();
// SELECT "employeeUUID", "name", "email", "departmentUUID", "managerUUID" FROM "employee"
//   WHERE "deleted" = FALSE AND "enabled" = TRUE ORDER BY "name" ASC LIMIT 50
```

| Constant | Default | Applies to | Overridden by |
|---|---|---|---|
| `DEFAULT_SELECT` | all columns of the table | `fetch()` | `fetch($columns)`, `selecting()` |
| `DEFAULT_WHERE` | none | `fetch()`, `count()`, `change()`, `remove()` | extended by `matching()` |
| `DEFAULT_ORDER` | none | `fetch()` | `sortedBy()` replaces it, `sortedBy()` without arguments removes it |
| `DEFAULT_LIMIT` | `100` | `fetch()` | `limitedTo()`, `unlimited()`; `null` means no limit |
| `DEFAULT_RETURNING` | `'*'` | `add()`, `change()`, `remove()` | `asQuery(returning: ...)`; `null` means no `RETURNING` |
| `DEFAULT_RELATIONS` | none | `fetch()` | extended by `with()`, reduced by `without()` |
| `DEFAULT_CONCEALING` | none | `fetch()`, and `RETURNING` of `add()`, `change()`, `remove()` | extended by `concealing()`, reduced by `revealing()`, see [Concealing columns](#concealing-columns) |
| `DEFAULT_ADAPTER` | the configured adapter | all | a class name implementing `F4\DB\Adapter\AdapterInterface` |

`DEFAULT_WHERE` also applies to `change()` and `remove()`, so with the definition above `Employees::remove()` never touches rows that are marked as deleted.

When an entity is the target of a relation, its defaults apply to the related rows as well, except `DEFAULT_RELATIONS`, see [Relations of related rows](#relations-of-related-rows).

## Relations

### Declaring relations

Relations are declared in `RELATIONS`, keyed by an alias, which is also the name of the result column that holds the related data. Each value is a closure returning a relation:

- `One::of($target)` returns a single related row, or `null`.
- `Many::of($target)` returns a list of related rows, or `null` when there are none.
- `ManyToMany::of($target)` is an alias of `Many`, for readability of relations [through a link table](#link-tables).

```php
use F4\Relentity\AbstractRelentity;
use F4\Relentity\Relation\{Many, One, RelationInterface};

class Employees extends AbstractRelentity
{
    public const string TABLE_NAME = 'employee';
    public const string PRIMARY_KEY = 'employeeUUID';

    public const array RELATIONS = [
        // employee.departmentUUID = department.departmentUUID
        'department' => static function (): RelationInterface {
            return One::of(Departments::class);
        },
        // employee.employeeUUID = timesheet.employeeUUID
        'timesheets' => static function (): RelationInterface {
            return Many::of(Timesheets::class);
        },
        // through the employee_project table, see "Link tables" below
        'projects' => static function (): RelationInterface {
            return Many::of(Projects::class)->via('employee_project');
        },
    ];
}

$employees = Employees::fetch()->with('department', 'timesheets', 'projects')->asTable();
```

Every row then contains one additional key per relation, already decoded from JSON:

```php
[
    'employeeUUID' => 'e2',
    'name' => 'Bob',
    // ...
    'department' => ['departmentUUID' => 'd1', 'name' => 'R&D'],
    'timesheets' => [
        ['timesheetUUID' => 't2', 'date' => '2026-11-02', 'hours' => 6, /* ... */],
        ['timesheetUUID' => 't1', 'date' => '2026-11-01', 'hours' => 8, /* ... */],
    ],
    'projects' => [
        ['projectUUID' => 'p1', 'name' => 'Apollo', /* ... */],
        ['projectUUID' => 'p2', 'name' => 'Gemini', /* ... */],
    ],
]
```

The entity that declares a relation is its **source**, the related entity is its **target**. The closure is called with the source instance whenever the relation is used, so a relation is built anew for every query. PHP does not allow arrow functions (`fn`) in constants, so use `static function` or a [first-class callable](#customizing-the-target).

### Join conditions

Without a condition, a relation follows the naming convention of the primary keys:

| Relation | Default condition | Typical meaning |
|---|---|---|
| `One::of(Target::class)` | `source.<target key> = target.<target key>` | the source "belongs to" the target |
| `Many::of(Target::class)` | `source.<source key> = target.<source key>` | the source "has many" targets |

The second argument of `of()` changes the columns:

```php
// a column of the same name on both sides
'department' => static function (): RelationInterface {
    return One::of(Departments::class, 'departmentId');      // employee.departmentId = department.departmentId
},

// differently named columns: ['source column' => 'target column']
'manager' => static function (): RelationInterface {
    return One::of(Employees::class, ['managerUUID' => 'employeeUUID']);   // employee.managerUUID = manager.employeeUUID
},
'reports' => static function (): RelationInterface {
    return Many::of(Employees::class, ['employeeUUID' => 'managerUUID']);  // employee.employeeUUID = report.managerUUID
},
```

In the array form the key is always the column of the source and the value is the column of the target. As `manager` and `reports` show, the source and the target may be the same table.

A [`ConditionCollection`](#completely-custom-conditions) replaces the condition completely.

### Link tables

`via()` relates the source to the target through a link table, and works the same for `Many` and `ManyToMany`:

```php
// employee.employeeUUID = employee_project.employeeUUID AND employee_project.projectUUID = project.projectUUID
'projects' => static function (): RelationInterface {
    return ManyToMany::of(Projects::class)->via('employee_project');
},
```

The second and the third arguments are the conditions for each side of the link table, in the same forms as [above](#join-conditions). In each array, the key is the column of the side that is closer to the source:

```php
// employee.employeeUUID = employee_skill.employeeId AND employee_skill.skillId = skill.skillUUID
'skills' => static function (): RelationInterface {
    return ManyToMany::of(Skills::class)->via(
        'employee_skill',
        ['employeeUUID' => 'employeeId'],    // source => link table
        ['skillId' => 'skillUUID'],          // link table => target
    );
},
```

If the link table has columns of its own, pass an entity instead of a table name: its `DEFAULT_WHERE` and its `matching()` conditions then filter the links.

```php
'verifiedSkills' => static function (): RelationInterface {
    return ManyToMany::of(Skills::class)->via(
        EmployeeSkills::fetch()->matching(['verified' => true]),
        ['employeeUUID' => 'employeeId'],
        ['skillId' => 'skillUUID'],
    );
},
```

A link table is used in an `EXISTS` condition, not joined, so duplicate links never duplicate the related rows.

### Customizing the target

`of()` accepts an entity class, or a statement started with `fetch()` or `count()`, which can be shaped by the same methods as any other query:

```php
class Timesheets extends AbstractRelentity
{
    public const string TABLE_NAME = 'timesheet';
    public const string PRIMARY_KEY = 'timesheetUUID';
    public const array DEFAULT_ORDER = ['date' => 'desc'];

    public static function approved(): static
    {
        return static::fetch()->matching(['approved' => true]);
    }
}

class Employees extends AbstractRelentity
{
    // ...
    public const array RELATIONS = [
        // filtered, sorted by DEFAULT_ORDER of Timesheets, and limited
        'recentTimesheets' => static function (): RelationInterface {
            return Many::of(Timesheets::approved()->limitedTo(5));
        },
        // an integer instead of the rows
        'timesheetCount' => static function (): RelationInterface {
            return Many::of(Timesheets::count());
        },
        // counts work through link tables, too
        'projectCount' => static function (): RelationInterface {
            return Many::of(Projects::count())->via('employee_project');
        },
        // relations of the target are included with with(), at any depth
        'projectsWithClients' => static function (): RelationInterface {
            return ManyToMany::of(Projects::fetch()->with('client'))->via('employee_project');
        },
    ];
}
```

A relation can also be a first-class callable to a static method, which is convenient when the same relation is declared by several entities:

```php
class Timesheets extends AbstractRelentity
{
    // ...
    public static function ofEmployee(): RelationInterface
    {
        return Many::of(static::approved(), 'employeeUUID');
    }
}

class Employees extends AbstractRelentity
{
    // ...
    public const array RELATIONS = [
        'approvedTimesheets' => Timesheets::ofEmployee(...),
    ];
}
```

`One` supports `fetch()` targets only, and returns the first row according to the ordering of the target.

### Choosing relations per query

Relations are included with `with()`, in addition to those listed in `DEFAULT_RELATIONS`, and excluded with `without()`:

```php
class Employees extends AbstractRelentity
{
    // ...
    public const array DEFAULT_RELATIONS = ['department'];
}

Employees::fetch()->asTable();                                   // with department
Employees::fetch()->with('timesheets', 'projects')->asTable();   // with department, timesheets and projects
Employees::fetch()->without('department')->asTable();            // without any relations
```

`with()` also accepts an array of aliases and closures, which defines a relation for a single query. A known alias is replaced, a new alias is added. Arrow functions are allowed here:

```php
// replace the declared timesheets relation for this query only
Employees::fetch()->with(['timesheets' => fn() => Many::of(Timesheets::approved())]);

// add a relation that is not declared in RELATIONS
Employees::fetch()->with([
    'timesheetsSince' => fn() => Many::of(Timesheets::fetch()->matching(['"date" >= {#}' => $since])),
]);
```

### Relations of related rows

`DEFAULT_RELATIONS` apply to the statements you start, not to related rows. A relation whose target is a class, such as `One::of(Employees::class)`, returns related rows without the default relations of that class, so a relation to the entity's own table can be listed in `DEFAULT_RELATIONS`:

```php
class Employees extends AbstractRelentity
{
    // ...
    public const array DEFAULT_RELATIONS = ['manager'];
    public const array RELATIONS = [
        'manager' => static function (): RelationInterface {
            return One::of(Employees::class, ['managerUUID' => 'employeeUUID']);
        },
    ];
}

Employees::fetch()->asTable();   // each employee with their manager, the manager without a manager of their own
```

To include relations in related rows, start the target with `fetch()`: it then has its default relations, and any relations added with `with()`:

```php
'projectsWithClients' => static function (): RelationInterface {
    return ManyToMany::of(Projects::fetch()->with('client'))->via('employee_project');
},
```

Such targets are built as declared, so planning them is up to you: a target started with `fetch()` whose default relations lead back to the same relation recurses until PHP runs out of stack. Exclude the relation on the target to stop it, e.g. `One::of(Employees::fetch()->without('manager'), ...)`.

### Completely custom conditions

Any `ConditionCollection` can serve as the condition of a relation, or as either condition of `via()`. It replaces the generated condition completely, and may contain any SQL that is valid in a `WHERE` clause.

Inside a relation, the current row of the source is available under the alias `Many::SOURCE_ALIAS` (the same as `One::SOURCE_ALIAS`), and it contains **all** columns of the source table, not only those that are selected. The target is referred to by its table name. Always qualify source columns with the alias: unqualified columns refer to the target.

```php
use F4\DB;
use F4\DB\{ConditionCollection, Fragment};

class Employees extends AbstractRelentity
{
    // ...
    public const array RELATIONS = [
        // timesheets within the employment period only
        'contractTimesheets' => static function (): RelationInterface {
            return Many::of(Timesheets::class, ConditionCollection::of(
                new Fragment('{#::#}.{#::#} = "timesheet"."employeeUUID"', [
                    DB::escapeIdentifier(Many::SOURCE_ALIAS),
                    DB::escapeIdentifier('employeeUUID'),
                ]),
                new Fragment('"timesheet"."date" BETWEEN "___source___"."hiredAt" AND COALESCE("___source___"."leftAt", CURRENT_DATE)'),
            ));
        },
        // other employees of the same department, which is the same table as the source
        'colleagues' => static function (): RelationInterface {
            return Many::of(Employees::class, ConditionCollection::of(
                new Fragment('"employee"."departmentUUID" = "___source___"."departmentUUID"'),
                new Fragment('"employee"."employeeUUID" <> "___source___"."employeeUUID"'),
            ));
        },
    ];
}
```

The first condition builds the alias with `DB::escapeIdentifier()`, which is also safe for adapters that quote identifiers differently. The others spell out `"___source___"`, the value of the constant, which is shorter in hand-written SQL.

Values are bound with placeholders, never interpolated:

```php
ConditionCollection::of(
    new Fragment('"___source___"."employeeUUID" = "timesheet"."employeeUUID"'),
    ['"timesheet"."hours" >= {#}' => $minimumHours],
);
```

When a condition is needed for a single query only, combine it with `with()`:

```php
Employees::fetch()->with([
    'longDays' => fn() => Many::of(Timesheets::class, ConditionCollection::of(
        new Fragment('"___source___"."employeeUUID" = "timesheet"."employeeUUID"'),
        ['"timesheet"."hours" >= {#}' => $minimumHours],
    )),
]);
```

## Joining tables

Relations add data to each row without filtering the rows. To filter the rows of an entity by another table, join it with `joinedWith()`, which is supported by `fetch()` only. A string condition is a column of the same name in both tables (`USING`), anything else is an `ON` condition:

```php
// employees assigned to a project
Employees::fetch()
    ->joinedWith('employee_project', 'employeeUUID')
    ->matching(['employee_project.projectUUID' => $projectUUID]);
// SELECT ... FROM "employee" JOIN "employee_project" USING ("employeeUUID")
//   WHERE "deleted" = FALSE AND "employee_project"."projectUUID" = '...' ...

// employees of a department, by its name
Employees::fetch()
    ->selecting('employee.*')
    ->joinedWith('department', [new Fragment('"department"."departmentUUID" = "employee"."departmentUUID"')])
    ->matching(['department.name' => 'R&D']);
```

The third argument is the name of the join method of f4php/db, `join` by default. The methods differ in whether they take a condition, and `joinedWith()` throws an `InvalidArgumentException` when a condition is missing or not allowed:

| Methods | Condition |
|---|---|
| `join`, `innerJoin`, `leftJoin`, `leftOuterJoin`, `rightJoin`, `rightOuterJoin`, `fullOuterJoin`, `joinLateral`, `innerJoinLateral`, `leftJoinLateral` | required |
| `crossJoin`, `crossJoinLateral`, `naturalJoin`, `naturalLeftOuterJoin`, `naturalRightOuterJoin` | not allowed |

Pass the method by name when there is no condition:

```php
Employees::fetch()->joinedWith('employee_project', method: 'naturalJoin');
// SELECT ... FROM "employee" NATURAL JOIN "employee_project" ...
```

A natural join uses every column that both tables have in common, so it silently changes when a column is added to either table. Prefer an explicit condition.

The lateral methods join a subquery, which can refer to the columns of the entity. Pass it with its alias as the key:

```php
// employees with at least one timesheet, with the date of the latest one
Employees::fetch()
    ->selecting('employee.*', 'latest.date')
    ->joinedWith([
        'latest' => DB::select('date')
            ->from('timesheet')
            ->where([new Fragment('"timesheet"."employeeUUID" = "employee"."employeeUUID"')])
            ->order(['date' => 'desc'])
            ->limit(1),
    ], method: 'crossJoinLateral');
// SELECT employee.*, "latest"."date" FROM "employee" CROSS JOIN LATERAL (
//   SELECT "date" FROM "timesheet" WHERE "timesheet"."employeeUUID" = "employee"."employeeUUID" ORDER BY "date" DESC LIMIT 1
// ) AS "latest" ...
```

Joined tables share the namespace of the query, so a column that exists in several tables must be qualified, in `selecting()`, `matching()` and `sortedBy()` as well as in `DEFAULT_SELECT` and `DEFAULT_WHERE`. That is why the second example selects `employee.*`: both tables have a `name` column. Joined rows multiply the rows of the entity when they are not unique for each of them.

## Concealing columns

`DEFAULT_CONCEALING` lists keys that are removed from every result row, `concealing()` removes more keys, and `revealing()` restores keys that are concealed by default. A key is either a column name, or a path into a JSON value such as `$.settings.secret`.

```php
class Employees extends AbstractRelentity
{
    // ...
    public const array DEFAULT_CONCEALING = ['passwordHash'];
}

Employees::fetch()->asTable();                              // without passwordHash
Employees::fetch()->revealing('passwordHash')->asTable();   // with passwordHash
Employees::fetch()->concealing('email')->asTable();         // without passwordHash and email
```

Each of the following overrides the ones before it, whatever the order of the calls: `DEFAULT_CONCEALING`, `revealing()`, `concealing()`. So `revealing()` undoes a default, and `concealing()` always wins.

Concealment applies to the rows returned by `fetch()`, and to the rows returned by `add()`, `change()` and `remove()` through `RETURNING`. It does not apply to `count()`, which returns a number.

### Concealing related rows

The concealment of an entity applies wherever its rows are returned, including the rows of relations at any depth:

```php
Employees::fetch()->with('manager', 'reports')->asTable();
// passwordHash is removed from each employee, from their manager, and from each of their reports
```

The source of a relation can override the concealment of the related rows for a single query, using paths that start with the relation alias:

```php
Employees::fetch()
    ->with('manager', 'reports')
    ->revealing('$.manager.passwordHash')     // revealed for the manager only
    ->concealing('$.reports.email');          // concealed for each of the reports
```

A path into a list of related rows, such as `$.reports.email`, applies to each of them. Relations declared with a customized target follow that target, so `Many::of(Employees::fetch()->revealing('passwordHash'))` returns related rows with `passwordHash`.

`getConcealedColumns()` returns the resulting list, relation paths included:

```php
Employees::fetch()->with('manager', 'reports')->getConcealedColumns();
// ['passwordHash', '$.manager.passwordHash', '$.reports.passwordHash']
```

### How concealment works

Concealed keys are removed by f4php/db after the statement is executed, before any hook observes the result. Concealed values are therefore still selected and transferred from the database, but they never reach the code that receives the result.

With `DB_STRICT_CONCEAL` enabled, every concealed key must be present in every row, so a misspelled key throws a `ConcealedColumnNotFoundException` instead of silently exposing the column. Related rows that are `null`, and empty lists of related rows, are skipped. If a statement selects only some of the columns, with `selecting()`, `DEFAULT_SELECT` or a custom `RETURNING`, include the concealed columns or reveal them:

```php
Employees::fetch()->selecting(['employeeUUID', 'name'])->revealing('passwordHash')->asTable();
```

Concealed keys are removed before `asValue()` picks its value, so pass it a column name rather than an index: with an index, the position of every column after a concealed one shifts by one.

## Reference

### Starting a statement

| Method | Statement |
|---|---|
| `fetch($columns = null)` | `SELECT`; `$columns` replace `DEFAULT_SELECT` |
| `count()` | `SELECT COUNT(*)` |
| `add(array $values)` | `INSERT` |
| `change(array $changes)` | `UPDATE` |
| `remove()` | `DELETE` |

### Shaping a statement

All of them return the entity.

| Method | Takes effect for | Description |
|---|---|---|
| `matching(...$filters)` | all except `add()` | adds conditions to `DEFAULT_WHERE` and to earlier calls |
| `selecting(...$columns)` | `fetch()` | replaces the selected columns |
| `sortedBy(...$criteria)` | `fetch()` | replaces the ordering; removes it without arguments |
| `limitedTo(?int $rows, ?int $skipping = null)` | `fetch()` | replaces the limit, `null` removes it; optionally sets the offset |
| `unlimited()` | `fetch()` | removes the limit |
| `skipping(int $rows)` | `fetch()` | sets the offset |
| `with(...$relations)` | `fetch()` | includes relations by alias, or `['alias' => callable]` |
| `without(...$relations)` | `fetch()` | excludes relations by alias, default ones included |
| `joinedWith($table, $condition = null, $method = 'join')` | `fetch()` | joins a table or a subquery, see [Joining tables](#joining-tables) |
| `concealing(...$columns)` | all except `count()` | conceals result keys or paths, `$.alias.column` for related rows |
| `revealing(...$columns)` | all except `count()` | reveals keys or paths concealed by default |

`selecting()`, `with()`, `without()` and `joinedWith()` throw an `InvalidArgumentException` for any other statement, and so do `sortedBy()`, `limitedTo()` and `skipping()` for `count()`, and `limitedTo()` and `skipping()` for `remove()`.

`matching()`, `selecting()`, `sortedBy()`, `with()`, `without()`, `concealing()` and `revealing()` reject named arguments, since PHP would otherwise accept any name: `matching(status: [...])` would silently filter by a `status` column.

### Ending a statement

| Method | Returns | Description |
|---|---|---|
| `asTable()` | `array` | executes the statement and returns all rows; not available for `count()` |
| `asRow()` | `?array` | executes the statement and returns the first row; not available for `count()` |
| `asValue(string\|int $column = 0)` | `mixed` | executes the statement and returns one value of the first row; an `int` for `count()` |
| `execute()` | `void` | executes the statement and discards the result |
| `asQuery($columns = null, ?string $returning = null)` | `QueryBuilderInterface` | builds the statement without executing it |
| `asSQL()` | `string` | builds the statement and returns its SQL |
| `getConcealedColumns()` | `list<string>` | the keys and paths that will be concealed, relation paths included |

### Relations

| Method | Description |
|---|---|
| `One::of($target, $condition = null)` | a single related row; `$target` is a class or a `fetch()` statement |
| `Many::of($target, $condition = null)` | a list of related rows, or their number for a `count()` target |
| `ManyToMany::of($target, $condition = null)` | same as `Many` |
| `->via($table, $sourceCondition = null, $targetCondition = null)` | `Many` and `ManyToMany` only: relates through a link table name, or a `fetch()` statement of a link entity |

A condition is one of:

| Condition | Meaning |
|---|---|
| `null` | the [default](#join-conditions) |
| `'column'` | the same column on both sides |
| `['sourceColumn' => 'targetColumn']` | differently named columns, the source side first |
| `ConditionCollection` | a [custom condition](#completely-custom-conditions), used as is |
