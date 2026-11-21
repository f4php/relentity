<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\DB;
use F4\DB\{ConditionCollection, Fragment};
use F4\Relentity\AbstractRelentity;
use F4\Relentity\Relation\{Many, ManyToMany, One, RelationInterface};

/**
 * An entity with defaults and relations of every kind, as in the README
 */
class Employee extends AbstractRelentity
{
    public const string TABLE_NAME = 'employee';
    public const string PRIMARY_KEY = 'employeeUUID';
    public const array DEFAULT_SELECT = ['employeeUUID', 'name'];
    public const array DEFAULT_WHERE = ['deleted' => false];
    public const array DEFAULT_ORDER = ['name' => 'asc'];
    public const ?int DEFAULT_LIMIT = 50;
    public const array DEFAULT_CONCEALING = ['passwordHash'];

    public const array RELATIONS = [
        'department' => static function (): RelationInterface {
            return One::of(Department::class);
        },
        'departmentById' => static function (): RelationInterface {
            return One::of(Department::class, 'departmentId');
        },
        'manager' => static function (): RelationInterface {
            return One::of(Employee::class, ['managerUUID' => 'employeeUUID']);
        },
        'reports' => static function (): RelationInterface {
            return Many::of(Employee::class, ['employeeUUID' => 'managerUUID']);
        },
        'timesheets' => static function (): RelationInterface {
            return Many::of(Timesheet::class);
        },
        'recentTimesheets' => static function (): RelationInterface {
            return Many::of(Timesheet::approved()->limitedTo(5));
        },
        'timesheetCount' => static function (): RelationInterface {
            return Many::of(Timesheet::count());
        },
        'approvedTimesheets' => Timesheet::ofEmployee(...),
        'projects' => static function (): RelationInterface {
            return ManyToMany::of(Project::class)->via('employee_project');
        },
        'projectCount' => static function (): RelationInterface {
            return Many::of(Project::count())->via('employee_project');
        },
        'projectsWithClients' => static function (): RelationInterface {
            return ManyToMany::of(Project::fetch()->with('client'))->via('employee_project');
        },
        'skills' => static function (): RelationInterface {
            return ManyToMany::of(Skill::class)->via('employee_skill', ['employeeUUID' => 'employeeId'], ['skillId' => 'skillUUID']);
        },
        'verifiedSkills' => static function (): RelationInterface {
            return ManyToMany::of(Skill::class)->via(
                EmployeeSkill::fetch()->matching(['verified' => true]),
                ['employeeUUID' => 'employeeId'],
                ['skillId' => 'skillUUID'],
            );
        },
        'colleagues' => static function (): RelationInterface {
            return Many::of(Employee::class, ConditionCollection::of(
                new Fragment('"employee"."departmentUUID" = {#::#}.{#::#}', [
                    DB::escapeIdentifier(Many::SOURCE_ALIAS),
                    DB::escapeIdentifier('departmentUUID'),
                ]),
                ['"employee"."enabled" = {#}' => true],
            ));
        },
        'linkedColleagues' => static function (): RelationInterface {
            return Many::of(Employee::class)->via(
                'employee_colleague',
                ConditionCollection::of(new Fragment('"employee_colleague"."a" = "___source___"."employeeUUID"')),
                ConditionCollection::of(new Fragment('"employee_colleague"."b" = "employee"."employeeUUID"')),
            );
        },
    ];

    public function enabled(): static
    {
        return $this->matching(['enabled' => true]);
    }
}
