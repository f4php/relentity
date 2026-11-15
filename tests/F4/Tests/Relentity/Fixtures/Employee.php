<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\{
    AbstractRelentity,
    RelentityInterface,
    Relation\ManyToMany,
    Relation\ManyToOne,
    Relation\OneToMany,
};

/**
 * Every relation targets the entity's own table.
 */
class Employee extends AbstractRelentity implements RelentityInterface
{
    public const string TABLE_NAME = 'employee';
    public const string PRIMARY_KEY = 'employeeUUID';
    public const array RELATIONS = [
        'manager' => [Employee::class, ManyToOne::class,
            'sourceKey' => 'managerUUID',
        ],
        'reports' => [Employee::class, OneToMany::class,
            'targetKey' => 'managerUUID',
        ],
        'reportCount' => [Employee::class, OneToMany::class,
            'targetKey' => 'managerUUID',
            'count' => true,
        ],
        'mentors' => [Employee::class, ManyToMany::class,
            'through' => 'employee_mentor',
            'throughTargetKey' => 'mentorUUID',
        ],
        'managerWithManager' => [Employee::class, ManyToOne::class,
            'sourceKey' => 'managerUUID',
            'modifyTarget' => static function (Employee $employees): Employee {
                return $employees->with('manager');
            },
        ],
    ];
}
