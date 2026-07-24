<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Enums;

enum ConsumptionState: int
{
    case YET_TO_BE_CONSUMED = 0;
    case CONSUMED = 1;
}
