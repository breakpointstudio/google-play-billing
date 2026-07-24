<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Responses;

use Breakpoint\GooglePlay\Enums\CancelReason;
use Breakpoint\GooglePlay\Enums\PaymentState;
use Carbon\Carbon;
use Google\Service\AndroidPublisher\IntroductoryPriceInfo;
use Google\Service\AndroidPublisher\SubscriptionPurchase;

/**
 * purchases.subscriptions (v1) — still the workhorse for the upload path.
 */
class SubscriptionResponse extends AbstractResponse
{
    public readonly bool $autoRenewing;

    public readonly ?CancelReason $cancelReason;

    public readonly ?string $countryCode;

    public readonly ?string $priceAmountMicros;

    public readonly ?string $priceCurrencyCode;

    public readonly ?Carbon $startedAt;

    public readonly ?Carbon $expiresAt;

    /** Set only when the *user* cancelled; a system cancellation leaves this null. */
    public readonly ?Carbon $cancelledAt;

    /** Absent on expired and canceled subscriptions. */
    public readonly ?PaymentState $paymentState;

    public readonly ?string $orderId;

    /** The token this purchase replaced — the chain Google expects us to invalidate. */
    public readonly ?string $linkedPurchaseToken;

    public readonly ?string $promotionCode;

    public readonly ?string $externalAccountId;

    public readonly ?IntroductoryPriceInfo $introductoryPriceInfo;

    public function __construct(SubscriptionPurchase $raw)
    {
        parent::__construct($raw);

        $this->autoRenewing = self::toBool($raw->getAutoRenewing());
        $this->cancelReason = self::toEnumOrNull(CancelReason::class, $raw->getCancelReason());
        $this->countryCode = self::toStringOrNull($raw->getCountryCode());
        $this->priceAmountMicros = self::toStringOrNull($raw->getPriceAmountMicros());
        $this->priceCurrencyCode = self::toStringOrNull($raw->getPriceCurrencyCode());
        $this->startedAt = self::toDateFromMs($raw->getStartTimeMillis());
        $this->expiresAt = self::toDateFromMs($raw->getExpiryTimeMillis());
        $this->cancelledAt = self::toDateFromMs($raw->getUserCancellationTimeMillis());
        $this->paymentState = self::toEnumOrNull(PaymentState::class, $raw->getPaymentState());
        $this->orderId = self::toStringOrNull($raw->getOrderId());
        $this->linkedPurchaseToken = self::toStringOrNull($raw->getLinkedPurchaseToken());
        $this->promotionCode = self::toStringOrNull($raw->getPromotionCode());
        $this->externalAccountId = self::toStringOrNull($raw->getExternalAccountId());
        $this->introductoryPriceInfo = $raw->getIntroductoryPriceInfo();
    }

    public function isInTrial(): bool
    {
        return $this->paymentState === PaymentState::DEFERRED;
    }

    public function isInBillingGrace(): bool
    {
        return $this->paymentState === PaymentState::PENDING;
    }
}
