<?php

declare(strict_types=1);

namespace F4\Relentity;

enum ActionType {
    case Fetch;
    case Add;
    case Change;
    case Remove;
}