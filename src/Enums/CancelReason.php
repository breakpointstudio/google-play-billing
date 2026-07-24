<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Enums;

enum CancelReason: int
{
    case USER = 0;
    case SYSTEM = 1;
    case REPLACED = 2;
    case DEVELOPER = 3;
}
