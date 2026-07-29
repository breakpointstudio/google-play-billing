<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Enums;

/**
 * subscriptionsv2's replacement for v1's `paymentState`. Deliberately string-backed: the API sends
 * these as strings, and an int backing would silently cast every one of them to 0.
 *
 * No entitlement semantics live here — which states grant access is the consuming app's decision.
 */
enum SubscriptionState: string
{
    case UNSPECIFIED = 'SUBSCRIPTION_STATE_UNSPECIFIED';
    case PENDING = 'SUBSCRIPTION_STATE_PENDING';
    case ACTIVE = 'SUBSCRIPTION_STATE_ACTIVE';
    case PAUSED = 'SUBSCRIPTION_STATE_PAUSED';
    case IN_GRACE_PERIOD = 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD';
    case ON_HOLD = 'SUBSCRIPTION_STATE_ON_HOLD';
    case CANCELED = 'SUBSCRIPTION_STATE_CANCELED';
    case EXPIRED = 'SUBSCRIPTION_STATE_EXPIRED';
    case PENDING_PURCHASE_CANCELED = 'SUBSCRIPTION_STATE_PENDING_PURCHASE_CANCELED';
}
