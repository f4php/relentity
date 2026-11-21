<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;

/**
 * A link table with columns of its own
 */
class EmployeeSkill extends AbstractRelentity
{
    public const string TABLE_NAME = 'employee_skill';
    public const string PRIMARY_KEY = 'employeeSkillUUID';
    public const array DEFAULT_WHERE = ['revoked' => false];
}
