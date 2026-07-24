<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Events;

use Breakpoint\GooglePlay\Enums\ProductType;
use Breakpoint\GooglePlay\Enums\RefundType;

/**
 * Console refunds and chargebacks arrive here; the legacy controller 422'd them.
 */
class VoidedPurchase extends RtdnEvent
{
    /**
     * @param  array<string, mixed>  $notification
     */
    public function __construct(
        array $notification,
        string $packageName,
        public readonly string $purchaseToken,
        public readonly ?string $orderId,
        public readonly ?ProductType $productType,
        public readonly ?RefundType $refundType,
        ?string $messageId = null,
    ) {
        parent::__construct($notification, $packageName, $messageId);
    }

    public function isPartialRefund(): bool
    {
        return $this->refundType === RefundType::QUANTITY_BASED_PARTIAL;
    }
}
