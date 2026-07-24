<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Enums;

enum AcknowledgementState: int
{
    case YET_TO_BE_ACKNOWLEDGED = 0;
    case ACKNOWLEDGED = 1;
}
