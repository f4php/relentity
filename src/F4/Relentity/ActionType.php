<?php

declare(strict_types=1);

namespace F4\Relentity;

enum ActionType {
    case Count;
    case Add;
    case Fetch;
    case Change;
    case Remove;
}