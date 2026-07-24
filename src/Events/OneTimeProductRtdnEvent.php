<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Events;

use Breakpoint\GooglePlay\Enums\OneTimeProductNotificationType;

abstract class OneTimeProductRtdnEvent extends RtdnEvent
{
    /**
     * @param  array<string, mixed>  $notification
     */
    public function __construct(
        array $notification,
        string $packageName,
        public readonly string $purchaseToken,
        public readonly string $sku,
        public readonly OneTimeProductNotificationType $type,
        ?string $messageId = null,
    ) {
        parent::__construct($notification, $packageName, $messageId);
    }
}
