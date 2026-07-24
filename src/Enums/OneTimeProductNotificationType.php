<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Enums;

enum OneTimeProductNotificationType: int
{
    case PURCHASED = 1;
    case CANCELED = 2;
}
