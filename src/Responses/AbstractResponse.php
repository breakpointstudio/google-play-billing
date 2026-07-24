<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Responses;

use Breakpoint\GooglePlay\Enums\AcknowledgementState;
use Breakpoint\GooglePlay\Support\ValueCasting;
use Google\Service\AndroidPublisher\ProductPurchase;
use Google\Service\AndroidPublisher\SubscriptionPurchase;
use Google\Service\AndroidPublisher\SubscriptionPurchaseV2;

abstract class AbstractResponse
{
    use ValueCasting;

    public readonly ?string $kind;

    public readonly ?AcknowledgementState $acknowledgementState;

    public function __construct(
        public readonly ProductPurchase|SubscriptionPurchase|SubscriptionPurchaseV2 $raw,
    ) {
        $this->kind = self::toStringOrNull($raw->kind ?? null);
        $this->acknowledgementState = self::toEnumOrNull(AcknowledgementState::class, $raw->acknowledgementState ?? null);
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledgementState === AcknowledgementState::ACKNOWLEDGED;
    }

    /**
     * Escape hatch for fields this package has not promoted yet.
     */
    public function getRawResponse(): ProductPurchase|SubscriptionPurchase|SubscriptionPurchaseV2
    {
        return $this->raw;
    }
}
