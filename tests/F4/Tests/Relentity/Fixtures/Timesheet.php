<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;
use F4\Relentity\Relation\{Many, RelationInterface};

class Timesheet extends AbstractRelentity
{
    public const string TABLE_NAME = 'timesheet';
    public const string PRIMARY_KEY = 'timesheetUUID';
    public const array DEFAULT_ORDER = ['date' => 'desc'];

    public static function approved(): static
    {
        return static::fetch()->matching(['approved' => true]);
    }
    public static function ofEmployee(): RelationInterface
    {
        return Many::of(static::approved(), 'employeeUUID');
    }
}
