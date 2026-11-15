<?php

declare(strict_types=1);

namespace F4\Tests\Relentity\Fixtures;

class OwnedFile extends File
{
    public const array DEFAULT_RELATIONS = ['owner'];
}
