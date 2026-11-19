<?php

declare(strict_types=1);

namespace F4\Relentity\Relation;

use F4\Relentity\{
    ActionType,
    Relation\Many,
};

// This is just a syntax sugar
class ManyToMany extends Many {
    protected const array SUPPORTED_ACTIONS = [
        ActionType::Fetch,
    ];
}