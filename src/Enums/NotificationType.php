<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Enums;

/**
 * RTDN subscriptionNotification codes. Always resolve with tryFrom(): Google adds codes without
 * notice, and an unknown one must degrade to a logged no-op, never an error.
 *
 * @see https://developer.android.com/google/play/billing/rtdn-reference
 */
enum NotificationType: int
{
    case RECOVERED = 1;
    case RENEWED = 2;
    case CANCELED = 3;
    case PURCHASED = 4;
    case ON_HOLD = 5;
    case IN_GRACE_PERIOD = 6;
    case RESTARTED = 7;
    case PRICE_CHANGE_CONFIRMED = 8;
    case DEFERRED = 9;
    case PAUSED = 10;
    case PAUSE_SCHEDULE_CHANGED = 11;
    case REVOKED = 12;
    case EXPIRED = 13;
    case ITEMS_CHANGED = 17;
    case CANCELLATION_SCHEDULED = 18;
    case PRICE_CHANGE_UPDATED = 19;
    case PENDING_PURCHASE_CANCELED = 20;
    case PRICE_STEP_UP_CONSENT_UPDATED = 22;
}
