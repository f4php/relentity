# Overview

**Relentity** is an entity-focused layer on top of the [f4php/db](https://github.com/f4php/db) query builder, and a core package of [F4](https://github.com/f4php/f4), a lightweight web development framework.

An entity class describes a table: its primary key, default filters, ordering and limit, and its relations to other entities. Every statement built from it compiles to an ordinary f4php/db `QueryBuilderInterface`, so you can execute it, inspect its SQL, extend it, or add it to a transaction.

Relations are compiled into `LEFT JOIN LATERAL` subqueries that return related rows as JSON, so a single query returns each row together with its related data, and joining relations never multiplies the rows of the main entity.

- [Overview](#overview)
  - [Installation](#installation)
  - [Quick start](#quick-start)
  - [Entity defaults](#entity-defaults)
  - [Relations](#relations)
    - [Declaring relations](#declaring-relations)
    - [Values of related rows](#values-of-related-rows)
    - [Join conditions](#join-conditions)
    - [Link tables](#link-tables)
    - [Customizing the target](#customizing-the-target)
    - [Choosing relations per query](#choosing-relations-per-query)
    - [Relations that depend on the source](#relations-that-depend-on-the-source)
    - [Relations of related rows](#relations-of-related-rows)
    - [Completely custom conditions](#completely-custom-conditions)
  - [Joining tables](#joining-tables)
  - [Concealing columns](#concealing-columns)
    - [Concealing related rows](#concealing-related-rows)
    - [How concealment works](#how-concealment-works)
  - [Limitations](#limitations)
  - [Reference](#reference)
    - [Starting a statement](#starting-a-statement)
    - [Shaping a statement](#shaping-a-statement)
    - [Ending a statement](#ending-a-statement)
    - [Relations](#relations-1)

## Installation

```bash
composer require f4php/relentity
```

Relentity requires PHP 8.5.13 or later, and PostgreSQL: relations rely on `LATERAL` joins and `jsonb`. The connection is configured by the constants of an `F4\Config` class, as described in the [configuration of f4php/db](https://github.com/f4php/db#configuration). Within the F4 framework that class already exists. In a standalone project, define it, or make it autoloadable, before the first statement is built: some statements, such as `count()` and `fetch()->asQuery()`, need it before they are executed.

```php
namespace F4;

class Config
{
    public const string DB_ADAPTER_CLASS = \F4\DB\Adapter\PostgresqlAdapter::class;
    public const string DB_HOST = 'localhost';
    public const string DB_PORT = '5432';
    public const string DB_NAME = 'application';
    public const string DB_USERNAME = 'app';
    public const string DB_PASSWORD = 'secret';
    public const string DB_CHARSET = 'UTF8';
    public const string DB_SCHEMA = '';
    public const ?string DB_APP_NAME = null;
    public const bool DB_PERSIST = true;
    public const bool DB_OVERWRITE_DUPLICATE_RESPONSE_COLUMNS = false;
    public const bool DB_STRICT_CONCEAL = true;
    public const bool DEBUG_MODE = false;
    public const string TIMEZONE = '';
}
```

Within the F4 framework, the password can additionally be marked with the framework's `F4\Config\SensitiveParameter` attribute (`use F4\Config\SensitiveParameter;`, then `#[SensitiveParameter]` above the constant). The attribute is not part of Relentity or its dependencies.

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

`change()` ignores `limitedTo()`, `skipping()` and `sortedBy()`: it changes **every** row that matches its conditions, so narrow the rows with `matching()`, e.g. by the primary key. See [Shaping a statement](#shaping-a-statement) for the other combinations.

Nothing is executed until the last call, and `asQuery()` or `asSQL()` return the statement instead. When a statement is executed, values are bound as parameters. `asSQL()` renders them, escaped, into the SQL for inspection, and the PostgreSQL adapter may open a connection to escape them. To see the SQL with its placeholders, use the prepared statement:

```php
Employees::fetch()->matching(['email' => $email])->asSQL();
// SELECT "employee".* FROM "employee" WHERE "email" = '...' LIMIT 100

Employees::fetch()->matching(['email' => $email])->asQuery()->getPreparedStatement()->query;
// SELECT "employee".* FROM "employee" WHERE "email" = $1 LIMIT 100
```

`asQuery()` returns the f4php/db query builder, which can be extended before it is executed. Relentity's method names, such as `matching()` and `sortedBy()`, intentionally differ from those of the query builder, such as `where()` and `orderBy()`, so it is clear which one a call belongs to; the [reference](#shaping-a-statement) lists where similar methods behave differently. Note that Relentity's `with()` includes a relation, while the builder's `with()` introduces a common table expression (`WITH ... AS`).

Prefer `matching()` for entity filters. After `asQuery()`, `where()` can extend an existing `WHERE`, including one that comes from `matching()` or `DEFAULT_WHERE`. Otherwise, a new `WHERE` is appended in call order, which is invalid SQL after `ORDER BY` or `LIMIT`:

```php
Employees::fetch()->matching(['enabled' => true])->asQuery()->where(['"name" ILIKE {#}' => $pattern])->asTable();
// SELECT "employee".* FROM "employee" WHERE "enabled" = TRUE AND "name" ILIKE '...' LIMIT 100
```

`matching()` accepts filter arrays and `ConditionCollection` objects: `['column' => $value]`, a list for `IN`, `null` for `IS NULL`, an expression with a placeholder such as `['"date" >= {#}' => $since]`. A `Fragment` is wrapped in a collection, e.g. `matching(ConditionCollection::of(new Fragment('"hiredAt" > now() - interval \'1 year\'')))`. Each call adds to the previous ones with `AND`.

Chained methods change the statement they are called on and return it, so a statement that is reused must be cloned before it is changed further:

```php
$enabled = Employees::fetch()->matching(['enabled' => true]);

$newest = (clone $enabled)->sortedBy(['hiredAt' => 'desc'])->limitedTo(10);   // $enabled is unchanged
$named = (clone $enabled)->matching(['name' => $name]);

$enabled->limitedTo(10);   // without clone: changes $enabled itself
```

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
| `DEFAULT_RETURNING` | `'*'` | `add()`, `change()`, `remove()` | `asQuery(returning: ...)`; a `null` constant means no `RETURNING`, while `asQuery(returning: null)` keeps the constant |
| `DEFAULT_RELATIONS` | none | `fetch()` | extended by `with()`, reduced by `without()` |
| `DEFAULT_CONCEALING` | none | `fetch()`, and `RETURNING` of `add()`, `change()`, `remove()` | extended by `concealing()`, reduced by `revealing()`, see [Concealing columns](#concealing-columns) |
| `DEFAULT_ADAPTER` | `DB_ADAPTER_CLASS` of `F4\Config` | all | a class name implementing `F4\DB\Adapter\AdapterInterface`, instantiated without arguments for every statement |

`DEFAULT_WHERE` also applies to `change()` and `remove()`, so with the definition above `Employees::remove()` never touches rows that are marked as deleted.

When an entity is the target of a relation, its defaults apply to the related rows as well, with an exception for `DEFAULT_RELATIONS`, see [Relations of related rows](#relations-of-related-rows).

## Relations

### Declaring relations

Relations are declared in `RELATIONS`, keyed by an alias, which is also the name of the result column that holds the related data. Each value is a closure returning a relation:

| Relation | Value | Without related rows |
|---|---|---|
| `One::of($target)` | the related row | `null` |
| `Many::of($target)` | a list of related rows | `null`, not an empty list: iterate over `$row['timesheets'] ?? []` |
| `Many::of(Target::count())` | the number of related rows, see [Customizing the target](#customizing-the-target) | `0` |

`ManyToMany` is an alias of `Many`, for readability of relations [through a link table](#link-tables).

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

Each relation adds one column, named after its alias, to the selected columns. Without `DEFAULT_SELECT`, an entity selects all columns of its own table, `"employee".*`. Avoid a bare `*` together with relations: it also returns an internal `___relation_rows___` column of every relation.

The entity that declares a relation is its **source**, the related entity is its **target**. The closure is called with the source statement whenever the relation is used, so a relation is built anew for every query, and can [depend on the source](#relations-that-depend-on-the-source). PHP does not allow arrow functions (`fn`) in constants, so use `static function` or a [first-class callable](#customizing-the-target).

The `DEFAULT_LIMIT` of the target, `100` unless redefined, limits **each** list of related rows. An employee with 150 timesheets therefore gets up to 100 of them, while a [`count()` relation](#customizing-the-target) of the same timesheets returns 150. Which 100 depends on the ordering of the target, its `DEFAULT_ORDER` or `sortedBy()`; without one, PostgreSQL picks any. Lift or change the limit on the target, and sort it when the subset matters:

```php
'timesheets' => static function (): RelationInterface {
    return Many::of(Timesheets::fetch()->unlimited());   // all of them
},
'latestTimesheets' => static function (): RelationInterface {
    return Many::of(Timesheets::fetch()->sortedBy(['date' => 'desc'])->limitedTo(10));
},
```

### Values of related rows

Related rows are built by PostgreSQL as JSON and decoded by PHP, so some values have a different type or format than the same column fetched directly:

| Column type | Fetched directly | In related rows |
|---|---|---|
| `integer`, `bigint` | `int` | `int` |
| `numeric` | exact `string`, e.g. `'1234567890.123456789'` | `int` for an integer that fits PHP's integer range, such as `5`; otherwise `float`, which may be rounded: `5.0`, `1234567890.1234567`, and `9223372036854775808` on 64-bit PHP |
| `boolean` | `bool` | `bool` |
| `timestamp` | `'2026-11-01 10:00:00'` | `'2026-11-01T10:00:00'` |
| `timestamptz` | `'2026-11-01 10:00:00+02'` | `'2026-11-01T10:00:00+02:00'` |
| `bytea` | binary `string` | hexadecimal `string`, e.g. `'\x0102'` |
| `json`, `jsonb` | decoded value: `array`, scalar or `null` | decoded value: `array`, scalar or `null` |

When exact decimals matter in related rows, select the needed columns in the target and cast the decimal to text under the name that the code reads:

```php
'timesheets' => static function (): RelationInterface {
    return Many::of(Timesheets::fetch()->selecting(['timesheetUUID', 'date', '"amount"::text AS "amount"']));
},
// 'amount' => '1234567890.123456789'
```

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

With the common schema convention of an `id` primary key and `<table>_id` foreign keys, every relation names its columns:

```php
class Departments extends AbstractRelentity
{
    public const string TABLE_NAME = 'department';
    public const string PRIMARY_KEY = 'id';
    public const array RELATIONS = [
        'employees' => static function (): RelationInterface {
            return Many::of(Employees::class, ['id' => 'department_id']);   // department.id = employee.department_id
        },
    ];
}

class Employees extends AbstractRelentity
{
    public const string TABLE_NAME = 'employee';
    public const string PRIMARY_KEY = 'id';
    public const array RELATIONS = [
        'department' => static function (): RelationInterface {
            return One::of(Departments::class, ['department_id' => 'id']);  // employee.department_id = department.id
        },
        'projects' => static function (): RelationInterface {
            return ManyToMany::of(Projects::class)->via(
                'employee_project',
                ['id' => 'employee_id'],                                     // employee.id = employee_project.employee_id
                ['project_id' => 'id'],                                      // employee_project.project_id = project.id
            );
        },
    ];
}
```

A [`ConditionCollection`](#completely-custom-conditions) replaces the condition completely.

### Link tables

`via()` relates the source to the target through a link table, and works the same for `Many` and `ManyToMany`:

```php
// employee.employeeUUID = employee_project.employeeUUID AND employee_project.projectUUID = project.projectUUID
'projects' => static function (): RelationInterface {
    return ManyToMany::of(Projects::class)->via('employee_project');
},
```

With `via()`, the condition passed to `of()` is ignored: the second and the third arguments of `via()` are the conditions for each side of the link table, in the same forms as [above](#join-conditions). In each array, the key is the column of the side that is closer to the source:

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

`One` supports `fetch()` targets only, and returns the first row according to the ordering of the target. A target can have relations of its own, see [Relations of related rows](#relations-of-related-rows).

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

The closure also receives the source statement, see [Relations that depend on the source](#relations-that-depend-on-the-source).

### Relations that depend on the source

Every relation closure, declared in `RELATIONS` or passed to `with()`, receives the source statement as its argument. The target can therefore use public properties and methods of the source, such as a period chosen for the query:

```php
class Employees extends AbstractRelentity
{
    // ...
    public private(set) ?string $from = null;
    public private(set) ?string $to = null;

    public const array RELATIONS = [
        'timesheets' => static function (Employees $employees): RelationInterface {
            $timesheets = Timesheets::fetch();
            if ($employees->from !== null && $employees->to !== null) {
                $timesheets->matching(
                    ['"date" >= {#}::date' => $employees->from],
                    ['"date" <= {#}::date' => $employees->to],
                );
            }
            return Many::of($timesheets);
        },
    ];

    public function inPeriod(string $from, string $to): static
    {
        $this->from = $from;
        $this->to = $to;
        return $this;
    }
}

Employees::fetch()->with('timesheets')->inPeriod('2026-11-01', '2026-11-30')->asTable();
// each employee with their timesheets of November only
```

The closure is called when the statement is built, by `asQuery()` or by the method that executes it, so the order of `with()` and `inPeriod()` does not matter, and a [cloned](#quick-start) statement keeps the property. Relations of a target statement, such as `Many::of(Employees::fetch()->with('timesheets'))`, receive that target statement, or a copy of it with the same properties, as their source. Their closures may be called more than once while a statement is built, so they should only read the source, not change it.

The source statement is the same for all rows, so values taken from it apply to every row alike. To compare with the columns of each source row, use the [source alias](#completely-custom-conditions) in a condition instead.

### Relations of related rows

`DEFAULT_RELATIONS` apply to every statement started with `fetch()`, including a target started with `fetch()`, but not to a target given as a class. A relation such as `One::of(Employees::class)` therefore returns related rows without the default relations of that class, so a relation to the entity's own table can be listed in `DEFAULT_RELATIONS`:

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

Inside a relation, the current row of the source is available under the alias `Many::SOURCE_ALIAS` (the same as `One::SOURCE_ALIAS`), and it contains **all** columns of the source table, not only those that are selected. Columns of tables added with [`joinedWith()`](#joining-tables) are not part of it, and are referred to by their table name. The target is referred to by its table name, and so is a link table in the conditions of `via()`. Qualify every column on both sides, with the alias or a table name: an unqualified column may bind to the target, or, in the conditions of `via()`, to the link table.

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

A condition for a single query is passed to `with()`. Values go into placeholders, so they are bound as parameters when the statement is executed:

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

For methods that require a condition, `null`, an empty array, or an empty string counts as missing and throws `InvalidArgumentException`. Methods that take no condition accept those values:

| Methods | Condition |
|---|---|
| `join`, `innerJoin`, `leftJoin`, `leftOuterJoin`, `rightJoin`, `rightOuterJoin`, `fullOuterJoin`, `joinLateral`, `innerJoinLateral`, `leftJoinLateral` | required |
| `crossJoin`, `crossJoinLateral`, `naturalJoin`, `naturalLeftOuterJoin`, `naturalRightOuterJoin` | not allowed |

An empty `ConditionCollection` is not detected, and produces a join without `ON` or `USING`. Pass the method by name when there is no condition:

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

`crossJoinLateral` drops the employees for whom the subquery returns no row. To keep them, use `leftJoinLateral`, which requires a condition: `[new Fragment('true')]` joins every row, as `ON true`.

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

`getConcealedColumns()` returns the resolved configuration, relation paths included. It returns it for any statement, `count()` included, although only executed `fetch()` and write statements have rows to conceal:

```php
Employees::fetch()->with('manager', 'reports')->getConcealedColumns();
// ['passwordHash', '$.manager.passwordHash', '$.reports.passwordHash']
```

### How concealment works

Concealed keys are removed by f4php/db from the results of executed `fetch()` statements, and from the `RETURNING` rows of executed writes, before any hook observes the result. Concealed values are therefore still selected and transferred from the database, but they never reach the code that receives the result.

This includes statements executed in a `DBTransaction`, where each statement keeps its own concealment. All statements of a transaction run through the adapter of the transaction, not through the `DEFAULT_ADAPTER` of their entities:

```php
use F4\DBTransaction;

[, $changed] = DBTransaction::add(
    Employees::change(['enabled' => false])->matching(['employeeUUID' => $uuid])->asQuery(),
)->commit();
// $changed holds the RETURNING rows, without passwordHash
```

With `DB_STRICT_CONCEAL` enabled, every concealed key must be present in every row, so a misspelled key throws a `ConcealedColumnNotFoundException` instead of silently exposing the column. Related rows that are `null`, and empty lists of related rows, are skipped. A statement that returns only some of the columns, with `selecting()`, `DEFAULT_SELECT` or a custom `RETURNING`, must therefore reveal the concealed columns it does not return:

```php
Employees::fetch()->selecting(['employeeUUID', 'name'])->revealing('passwordHash')->asTable();
```

For `add()`, `change()` and `remove()`, the exception is thrown after the statement is executed. Outside of a transaction, the change is then already saved, and only its result is lost. In a `DBTransaction`, the exception rolls the whole transaction back:

```php
// passwordHash is concealed by default, but not returned: the row is updated, then the exception is thrown
Employees::change(['enabled' => false])->matching(['employeeUUID' => $uuid])->asQuery(returning: 'employeeUUID')->asRow();
```

Concealed keys are removed before `asValue()` picks its value, so pass it a column name rather than an index: with an index, the position of every column after a concealed one shifts by one.

## Limitations

Relations refer to tables by their names, so a few combinations of names produce invalid or wrong SQL:

- **Schema-qualified table names** are not supported: `TABLE_NAME = 'hr.employee'` is quoted as a single name. Select the schema with `DB_SCHEMA` of `F4\Config` instead.
- **A relation alias** must differ from the table name of the entity, from tables added with `joinedWith()`, and from the selected columns. Otherwise PostgreSQL rejects the query (`table name "employee" specified more than once`), or f4php/db rejects the result (`Duplicate result column name`).
- **A link table** of `via()` must differ from the table of the target. A table that links its own rows, such as a `node` table with a `parentId` column, is related with a plain condition, e.g. `Many::of(Nodes::class, ['nodeId' => 'parentId'])`.

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

All of them mutate and return the entity. Remember to create cloned copies of Relentity objects if used as a template.

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

The other combinations are accepted, but have no effect: `change()` ignores `sortedBy()`, `limitedTo()` and `skipping()`, `remove()` ignores `sortedBy()`, `add()` ignores `matching()`, `sortedBy()`, `limitedTo()` and `skipping()`, and `unlimited()` has no effect on any statement other than `fetch()`. See the [warning about `change()`](#quick-start).

Methods of the query builder returned by `asQuery()` that resemble these behave differently in places: `select()` adds to the selected columns while `selecting()` replaces them, `where()` also accepts a bare `Fragment` while `matching()` does not, and the builder applies `concealing()` and `revealing()` in the order of the calls, while for an entity `concealing()` always wins.

`matching()`, `selecting()`, `sortedBy()`, `with()`, `without()`, `concealing()` and `revealing()` reject named arguments, since PHP would otherwise accept any name: `matching(status: [...])` would silently filter by a `status` column.

### Ending a statement

| Method | Returns | Description |
|---|---|---|
| `asTable()` | `array` | executes the statement and returns all rows; not available for `count()` |
| `asRow()` | `?array` | executes the statement and returns the first row; not available for `count()` |
| `asValue(string\|int $column = 0)` | `mixed` | executes the statement and returns one value of the first row; an `int` for `count()` |
| `execute()` | `void` | executes the statement and discards the result |
| `asQuery($columns = null, ?string $returning = null)` | `QueryBuilderInterface` | builds the statement without executing it; `$columns` replace the selected columns of `fetch()`, `$returning` replaces `DEFAULT_RETURNING` of `add()`, `change()` and `remove()`, and `null` keeps either |
| `asSQL()` | `string` | builds the statement and returns its SQL |
| `getConcealedColumns()` | `list<string>` | the resolved concealment configuration, relation paths included; applied only to the rows of executed `fetch()` and write statements |

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
| `'column'` | the same column on both sides, a non-empty name |
| `['sourceColumn' => 'targetColumn']` | differently named columns, the source side first, both non-empty names |
| `ConditionCollection` | a [custom condition](#completely-custom-conditions), used as is |
