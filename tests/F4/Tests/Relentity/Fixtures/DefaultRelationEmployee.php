<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

class DefaultRelationEmployee extends BareEmployee
{
    public const array DEFAULT_RELATIONS = ['department', 'timesheetCount'];
}
