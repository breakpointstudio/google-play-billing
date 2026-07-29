<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Events;

use Breakpoint\GooglePlay\Enums\NotificationType;
use Breakpoint\GooglePlay\Responses\SubscriptionV2Response;

/**
 * A subscription notification plus the purchase state re-fetched and verified against Google —
 * the notification itself is only a hint and is never trusted on its own.
 */
abstract class SubscriptionRtdnEvent extends RtdnEvent
{
    /**
     * @param  string  $subscriptionId  Google is removing this from the notification with no
     *                                  replacement; read `$subscription->productId()` instead.
     * @param  array<string, mixed>  $notification
     */
    public function __construct(
        array $notification,
        string $packageName,
        public readonly string $purchaseToken,
        public readonly string $subscriptionId,
        public readonly SubscriptionV2Response $subscription,
        public readonly NotificationType $type,
        ?string $messageId = null,
    ) {
        parent::__construct($notification, $packageName, $messageId);
    }
}
