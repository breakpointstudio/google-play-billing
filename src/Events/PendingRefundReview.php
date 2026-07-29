<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Events;

/**
 * A refund Google is asking us to accept or decline within a review window. Previously fell through
 * to `UnknownNotification`, which meant every one of these was declined by default — a money
 * decision made by not making it.
 */
class PendingRefundReview extends RtdnEvent
{
    /**
     * @param  int|null  $refundReason  Google's enum; 7 is a chargeback.
     * @param  array<string, mixed>  $notification
     */
    public function __construct(
        array $notification,
        string $packageName,
        public readonly string $pendingRefundToken,
        public readonly ?string $orderId,
        public readonly ?int $refundReason,
        public readonly ?string $obfuscatedAccountId,
        public readonly ?string $obfuscatedProfileId,
        ?string $messageId = null,
    ) {
        parent::__construct($notification, $packageName, $messageId);
    }

    public function isChargeback(): bool
    {
        return $this->refundReason === 7;
    }
}
