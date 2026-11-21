<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

/**
 * Same relations as Employee, without defaults of its own, so that tests of relations
 * only show the relation
 */
class BareEmployee extends Employee
{
    public const array DEFAULT_SELECT = ['employeeUUID'];
    public const array DEFAULT_WHERE = [];
    public const array DEFAULT_ORDER = [];
    public const ?int DEFAULT_LIMIT = null;
}
