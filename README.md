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
  RelationType,
  RelentityInterface,
};

use MyApp\{
  Contractors,
  Timesheets,
  Vacations,
};

class Employees extends AbstractRelentity implements RelentityInterface {

  public const string TABLE_NAME = 'employee';                 // define primary entity table
  public const string PRIMARY_KEY = 'employeeUUID';            // and primary key
  public const array DEFAULT_CONCEALING = ['passwordHash'];    // columns hidden unless revealed
  public const array DEFAULT_REVEALING = [];
  public const array DEFAULT_ORDER = ['name' => 'asc'];
  public const array DEFAULT_RELATIONS = ['contractor'];       // joined unless a query opts out

  // define relations declaratively
  public const array RELATIONS = [
    'vacations' => Vacations::class, // simple relation, assumes RelationType::OneToMany by default and uses primary keys for both entities
    'contractor' => [Contractors::class, RelationType::OneToOne],
    'timesheets' => [Timesheets::class, static function () {
      //  custom relation handling
    }],
  ];

  public function enabled(): static {
    return $this->matching([
      'enabled' => true,
    ]);
  }
};
```

### Fetching the data

```php
Employees::fetch()
    ->with('vacations')                   // relations are included on demand in addition to default ones
    ->matching(['enabled' => true])       // criteria that are accepted by where()
    ->sortedBy(['name' => 'asc'])
    ->limit(10, offset: 20)
    ->asTable();

Employees::fetch()
  ->with('timesheets')                    // add non-default relation on demand
  ->without('contractor')                 // omit default relation on demand
  ->enabled()                             // custom shorthand filter
  ->asCount();                            // COUNT(*) projection

$statement = Employees::fetch()
  ->revealing('passwordHash')             // reveal columns concealed by default
  ->asQuery();                            // QueryBuilderInterface, nothing executed
```

