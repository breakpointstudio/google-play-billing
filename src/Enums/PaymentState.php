<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Enums;

/**
 * Absent entirely on expired and canceled subscriptions.
 */
enum PaymentState: int
{
    case PENDING = 0;
    case RECEIVED = 1;
    case DEFERRED = 2;
    case PENDING_DEFERRED_UPGRADE_OR_DOWNGRADE = 3;
}
