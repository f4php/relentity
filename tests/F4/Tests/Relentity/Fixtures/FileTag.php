<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\{
    AbstractRelentity,
    RelentityInterface,
};

class FileTag extends AbstractRelentity implements RelentityInterface
{
    public const string TABLE_NAME = 'file_tag';
    public const string PRIMARY_KEY = 'fileTagUUID';
    public const array DEFAULT_WHERE = ['deleted' => false];
}
