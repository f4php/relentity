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
  Relation\OneToMany,
  Relation\OneToOne,
};

use MyApp\{
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
  public const array DEFAULT_REVEALING = [];
  public const array DEFAULT_ORDER = ['name' => 'asc'];
  public const array DEFAULT_RELATIONS = ['contractor'];       // joined unless a query opts out

  // define relations declaratively
  public const array RELATIONS = [
    'vacations' => Vacations::class,                           // shorthand for [Vacations::class, OneToMany::class]
    'contractor' => [Contractors::class, OneToOne::class,      // "belongs to": the key lives on the source
      'sourceKey' => 'contractorUUID',
      'targetKey' => 'contractorUUID',
    ],
    'skills' => [Skills::class, ManyToMany::class, 'through' => EmployeeSkills::class],  // link table as an entity
    'projects' => [Projects::class, ManyToMany::class, 'through' => 'employee_project'], // or just a table name
    'recentTimesheets' => [Timesheets::class, OneToMany::class,
      'modifyTarget' => static function (Timesheets $timesheets): Timesheets {
        return $timesheets->sortedBy(['date' => 'desc'])->limit(10);
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

`DEFAULT_SELECT`, `DEFAULT_WHERE` and `DEFAULT_LIMIT` shape every statement of the entity,
and each one can be overridden or extended per query:

```php
Employees::fetch();
// SELECT "employeeUUID", "name", "email", "passwordHash" FROM "employee" WHERE "deleted" = FALSE ORDER BY "name" ASC LIMIT 50

Employees::fetch('*')                     // fetch() arguments replace DEFAULT_SELECT
  ->matching(['enabled' => true])         // matching() adds to DEFAULT_WHERE
  ->limit(10);                            // limit() replaces DEFAULT_LIMIT, limit(null) removes it
// SELECT * FROM "employee" WHERE "deleted" = FALSE AND "enabled" = TRUE ORDER BY "name" ASC LIMIT 10
```

- `DEFAULT_SELECT` is `'*'` unless redefined, and accepts anything `select()` does.
- `DEFAULT_WHERE` also applies to `update()` and `delete()`, so with the definition above
  `Employees::delete()` never touches rows marked as deleted.
- `DEFAULT_LIMIT` is `100` unless redefined, set it to `null` for entities that should be
  fetched without a limit. It only applies to `fetch()`.

When the entity is the target of a relation, the same defaults apply to the related rows.

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

| Option | OneToOne | OneToMany | ManyToMany | Purpose |
|---|---|---|---|---|
| `sourceKey` | `source::PRIMARY_KEY` | `source::PRIMARY_KEY` | `source::PRIMARY_KEY` | column on the source table |
| `targetKey` | `source::PRIMARY_KEY` | `source::PRIMARY_KEY` | `target::PRIMARY_KEY` | column on the target table |
| `through` | — | — | **required** | link table name or Relentity class |
| `throughSourceKey` | — | — | `source::PRIMARY_KEY` | link-table column pointing to the source |
| `throughTargetKey` | — | — | `target::PRIMARY_KEY` | link-table column pointing to the target |
| `modifyTarget` | `null` | `null` | `null` | `Closure(target): target`, modifies the target query |
| `modifyThrough` | — | — | `null` | `Closure(through): through`, requires `through` to be a Relentity class |
| `count` | — | `false` | `false` | return the number of related rows instead of the rows |
| `select` | `null` | `null` | `null` | `Closure($query, $source, $alias): $query`, replaces the select expression |
| `join` | `null` | `null` | `null` | `Closure($query, $source, $alias): $query`, replaces the join |

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
      ->limit(10);
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
position 1 with its own named options. As a last resort, `select` and `join` replace the
generated SQL of a single relation.

### Fetching the data

```php
Employees::fetch()
    ->with('vacations')                   // relations are included on demand in addition to default ones
    ->matching(['enabled' => true])       // criteria that are accepted by where()
    ->sortedBy(['name' => 'asc'])
    ->limit(10, offset: 20)
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

