<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

use F4\Relentity\{
    AbstractRelentity,
    RelentityInterface,
};

class FileAttribute extends AbstractRelentity implements RelentityInterface
{
    public const string TABLE_NAME = 'attribute';
    public const string PRIMARY_KEY = 'attributeUUID';

    public static function visible(self $attributes): self
    {
        return $attributes->matching(['visible' => true]);
    }
}
