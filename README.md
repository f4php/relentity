# Overview

**Relentity** is an entity-focused layer on top of [f4php/db](https://github.com/f4php/db), and a core package of [F4](https://github.com/f4php/f4), a lightweight web development framework.

Relentity repositories do not replace the query builder. They describe entities (table, key, sensitive columns, relations) and compile entity-aware statements into ordinary `QueryBuilderInterface` instances. You can inspect those statements, execute them, extend them or add them to a `DBTransaction`.

## Quick start

Boilerplate code is minimal, consider this simple example of a single entity `Files` class that has no complex features or relations:

```php

use F4\Relentity\{
  AbstractRelentity,
  RelentityInterface,
};

class Files extends AbstractRelentity implements RelentityInterface {
  public const string TABLE_NAME = 'file';      // define primary table name
  public const string PRIMARY_KEY = 'fileUUID'; // and primary key
};

$files = Files::fetch()->matching(['name' => $name]);    // returns RelentityInterface-compatible instance

$files->asTable();  // shortcut for QueryBuilderInterface tail method, effectively same as ->asQuery()->asTable()
$files->asCount();  // for example, this executes the query and returns an integer

$files->asQuery();  // returns QueryBuilderInterface, nothing executed
```

## Advanced use

Some entities are more complex and may have relations to other entities, here's an example of `Employees` class:

```php

use F4\Relentity\{
  AbstractRelentity,
  RelentityInterface,
  Relation\ManyToMany,
  Relation\ManyToOne,
  Relation\OneToMany,
  Relation\OneToOne,
};

use MyApp\{
  Badges,
  Contractors,
  EmployeeSkills,
  Projects,
  Skills,
  Timesheets,
  Vacations,
};

class Employees extends AbstractRelentity implements RelentityInterface {

  public const string TABLE_NAME = 'employee';                 // define primary entity table
  public const string PRIMARY_KEY = 'employeeUUID';            // and primary key
  public const string|array DEFAULT_SELECT = ['employeeUUID', 'name', 'email', 'passwordHash']; // instead of *
  public const array DEFAULT_WHERE = ['deleted' => false];     // applied to every statement
  public const ?int DEFAULT_LIMIT = 50;                        // instead of 100, null for no limit
  public const array DEFAULT_CONCEALING = ['passwordHash'];    // columns hidden unless revealed
  public const array DEFAULT_ORDER = ['name' => 'asc'];
  public const array DEFAULT_RELATIONS = ['contractor'];       // joined unless a query opts out

  // define relations declaratively
  public const array RELATIONS = [
    'vacations' => Vacations::class,                           // shorthand for [Vacations::class, OneToMany::class]
    'contractor' => [Contractors::class, ManyToOne::class],    // "belongs to": employee.contractorUUID
    'manager' => [Employees::class, ManyToOne::class,          // same, with a differently named column
      'sourceKey' => 'managerUUID',
    ],
    'badge' => [Badges::class, OneToOne::class],               // "has one": badge.employeeUUID
    'skills' => [Skills::class, ManyToMany::class, 'through' => EmployeeSkills::class],  // link table as an entity
    'projects' => [Projects::class, ManyToMany::class, 'through' => 'employee_project'], // or just a table name
    'recentTimesheets' => [Timesheets::class, OneToMany::class,
      'modifyTarget' => static function (Timesheets $timesheets): Timesheets {
        return $timesheets->sortedBy(['date' => 'desc'])->limitedTo(10);
      },
    ],
    'timesheetCount' => [Timesheets::class, OneToMany::class, 'count' => true],
  ];

  public function enabled(): static {
    return $this->matching([
      'enabled' => true,
    ]);
  }
};
```

### Query defaults

`DEFAULT_SELECT`, `DEFAULT_WHERE`, `DEFAULT_ORDER` and `DEFAULT_LIMIT` shape every statement
of the entity, and each one can be overridden or extended per query:

```php
Employees::fetch();
// SELECT "employeeUUID", "name", "email", "passwordHash" FROM "employee" WHERE "deleted" = FALSE ORDER BY "name" ASC LIMIT 50

Employees::fetch('*')                     // fetch() arguments replace DEFAULT_SELECT
  ->selecting(['name', 'email'])          // selecting() replaces them again
  ->matching(['enabled' => true])         // matching() adds to DEFAULT_WHERE
  ->sortedBy(['email' => 'asc'])          // sortedBy() replaces DEFAULT_ORDER
  ->limitedTo(10);                        // limitedTo() replaces DEFAULT_LIMIT
// SELECT "name", "email" FROM "employee" WHERE "deleted" = FALSE AND "enabled" = TRUE ORDER BY "email" ASC LIMIT 10

Employees::fetch()
  ->sortedBy()                            // no arguments: removes the ordering
  ->unlimited();                          // removes the limit, same as limitedTo(null)
// SELECT "employeeUUID", "name", "email", "passwordHash" FROM "employee" WHERE "deleted" = FALSE
```

- `DEFAULT_SELECT` is `'*'` unless redefined, and accepts anything `select()` does.
  `fetch()` arguments and `selecting()` both replace it, so the columns can be chosen
  at any point of the chain without dropping to `asQuery()`. It only applies to `fetch()`.
  When relations are joined, `*` is emitted as `"table".*`, so it covers the entity's own
  columns only and every relation adds exactly one column named after its alias.
- `DEFAULT_WHERE` also applies to `update()` and `delete()`, so with the definition above
  `Employees::delete()` never touches rows marked as deleted.
- `DEFAULT_ORDER` is empty unless redefined. Unlike `matching()`, `with()`, `concealing()`
  and `revealing()`, which add to what is already set, `sortedBy()` replaces the ordering,
  so pass all criteria in a single call. It only applies to `fetch()`.
- `DEFAULT_LIMIT` is `100` unless redefined, set it to `null` for entities that should be
  fetched without a limit. It only applies to `fetch()`.

- `DEFAULT_CONCEALING` lists result keys, or paths such as `$.settings.secret`, that are
  removed from every row. Each of the following overrides the ones before it, whatever the
  order of the calls: `DEFAULT_CONCEALING`, `revealing()`, `concealing()`. So `revealing()`
  undoes a default and `concealing()` always wins.
  `getConcealedColumns()` returns the resulting list.

When the entity is the target of a relation, the same defaults apply to the related rows.
Concealed keys are then removed in SQL, as the related rows are a subquery that is never
executed on its own:

```php
Employees::fetch()->with('manager')->asSQL();
// SELECT ..., "manager"."manager" FROM "employee" LEFT JOIN LATERAL (
//   SELECT to_jsonb("relation_rows".*) - ARRAY['passwordHash']::text[] AS "manager" FROM (...) AS "relation_rows"
// ) AS "manager" ON true ...
```

Use `modifyTarget` to conceal or reveal keys for a single relation. Two things to keep in mind:

- A path is removed with `#-`, which does not descend into JSON lists. To hide keys of a
  nested to-many relation, conceal them on the entity that is its target instead.
- A key removed this way is absent from the result, so with `DB_STRICT_CONCEAL` enabled the
  source must not conceal it again, e.g. as `$.manager.passwordHash`.

### Declaring relations

Every `RELATIONS` entry is keyed by the relation alias, which is also the name of the
result column holding the related data. The entity declaring the relation is the **source**,
the related entity is the **target**.

An entry is either a target class, or an array of the target class, the relation class
(`OneToMany` when omitted) and any named options:

```php
'alias' => [TargetClass::class, RelationClass::class, 'option' => $value, ...],
```

Options are named arguments of the relation class constructor, so a misspelled or
mistyped option fails with a clear error naming the relation, e.g.
`Invalid declaration of Employees::RELATIONS['skills']: Unknown named parameter $tragetKey`.
Every option except `through` of a `ManyToMany` relation has a default, so most
relations need none:

| Option | OneToOne | ManyToOne | OneToMany | ManyToMany | Purpose |
|---|---|---|---|---|---|
| `sourceKey` | `source::PRIMARY_KEY` | `target::PRIMARY_KEY` | `source::PRIMARY_KEY` | `source::PRIMARY_KEY` | column on the source table |
| `targetKey` | `source::PRIMARY_KEY` | `target::PRIMARY_KEY` | `source::PRIMARY_KEY` | `target::PRIMARY_KEY` | column on the target table |
| `through` | — | — | — | **required** | link table name or Relentity class |
| `throughSourceKey` | — | — | — | `source::PRIMARY_KEY` | link-table column pointing to the source |
| `throughTargetKey` | — | — | — | `target::PRIMARY_KEY` | link-table column pointing to the target |
| `modifyTarget` | `null` | `null` | `null` | `null` | `Closure(target): target`, modifies the target query |
| `modifyThrough` | — | — | — | `null` | `Closure(through): through`, requires `through` to be a Relentity class |
| `count` | — | — | `false` | `false` | return the number of related rows instead of the rows |
| `customSelectCallback` | `null` | `null` | `null` | `null` | last resort: `Closure($query, $source, $alias): $query`, replaces the select expression |
| `customJoinCallback` | `null` | `null` | `null` | `null` | last resort: `Closure($query, $source, $alias): $query`, replaces the join |

`OneToOne` and `ManyToOne` both return a single related row and differ only in where the
key lives, which decides their default keys: use `OneToOne` when the target table points
to the source ("has one"), and `ManyToOne` when the source table points to the target
("belongs to").

#### Modifying the target

`modifyTarget` receives the target Relentity and returns it modified, so filtering,
sorting, limits and nested relations use the same methods as any other query. PHP allows
two closure forms in a class constant.

An inline `static function`, for a modification used by one relation (arrow functions
`fn` are not allowed in constants):

```php
'recentTimesheets' => [Timesheets::class, OneToMany::class,
  'modifyTarget' => static function (Timesheets $timesheets): Timesheets {
    return $timesheets
      ->matching(['approved' => true])
      ->sortedBy(['date' => 'desc'])
      ->limitedTo(10);
  },
],
```

A first-class callable to a **static** method, for a modification that is reused:

```php
class Timesheets extends AbstractRelentity implements RelentityInterface {
  // ...
  public static function approved(self $timesheets): self {
    return $timesheets->matching(['approved' => true]);
  }
}

'approvedTimesheets' => [Timesheets::class, OneToMany::class,
  'modifyTarget' => Timesheets::approved(...),
],
```

#### Link tables

A `ManyToMany` relation always names its link table in `through`. A plain table name is
enough when the table only holds the two keys:

```php
'projects' => [Projects::class, ManyToMany::class, 'through' => 'employee_project'],

// with key columns that are not named after the primary keys
'projects' => [Projects::class, ManyToMany::class,
  'through' => 'employee_project',
  'throughSourceKey' => 'employeeId',
  'throughTargetKey' => 'projectId',
],
```

A link table declared as a Relentity class applies its own `DEFAULT_WHERE`, and
`modifyThrough` modifies it the same way `modifyTarget` modifies the target.

#### Custom relations

For anything the options can't express, write a relation class implementing
`RelationInterface` (extending `AbstractRelation` gives you the defaults) and use it in
position 1 with its own named options. As a last resort, `customSelectCallback` and `customJoinCallback` replace the
generated SQL of a single relation.

### Fetching the data

```php
Employees::fetch()
    ->with('vacations')                   // relations are included on demand in addition to default ones
    ->matching(['enabled' => true])       // criteria that are accepted by where()
    ->sortedBy(['name' => 'asc'])
    ->limitedTo(10, skipping: 20)         // or ->limitedTo(10)->skipping(20)
    ->asTable();

Employees::fetch()
  ->with('recentTimesheets')              // add non-default relation on demand
  ->without('contractor')                 // omit default relation on demand
  ->enabled()                             // custom shorthand filter
  ->asCount();                            // COUNT(*) projection

$statement = Employees::fetch()
  ->revealing('passwordHash')             // reveal columns concealed by default
  ->asQuery();                            // QueryBuilderInterface, nothing executed
```

`matching()`, `sortedBy()`, `with()`, `without()`, `concealing()` and `revealing()` take any
number of positional arguments and reject named ones with an `InvalidArgumentException`:
PHP would otherwise accept any name, so `matching(status: [...])` would silently filter by
a `status` column.

