<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\AbstractRelentity;

/**
 * An entity without any defaults
 */
class Plain extends AbstractRelentity
{
    public const string TABLE_NAME = 'plain';
    public const string PRIMARY_KEY = 'plainUUID';
}
