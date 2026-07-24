<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Enums;

/**
 * As delivered on voidedPurchaseNotification.
 */
enum ProductType: int
{
    case SUBSCRIPTION = 1;
    case ONE_TIME = 2;
}
