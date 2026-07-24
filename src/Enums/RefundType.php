<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Enums;

enum RefundType: int
{
    case FULL = 1;
    case QUANTITY_BASED_PARTIAL = 2;
}
