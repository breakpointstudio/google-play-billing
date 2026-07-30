<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Responses;

use Breakpoint\GooglePlay\Enums\CancellationInitiator;
use Breakpoint\GooglePlay\Enums\SubscriptionState;
use Carbon\Carbon;
use Google\Service\AndroidPublisher\CanceledStateContext;
use Google\Service\AndroidPublisher\InGracePeriodStateContext;
use Google\Service\AndroidPublisher\Money;
use Google\Service\AndroidPublisher\OfferPhase;
use Google\Service\AndroidPublisher\OnHoldStateContext;
use Google\Service\AndroidPublisher\OutOfAppPurchaseContext;
use Google\Service\AndroidPublisher\PausedStateContext;
use Google\Service\AndroidPublisher\SubscriptionPurchaseLineItem;
use Google\Service\AndroidPublisher\SubscriptionPurchaseV2;

/**
 * purchases.subscriptionsv2 — the only subscription resource we call. v1 `purchases.subscriptions`
 * is deprecated (shutdown 2027-08-31) and dropped from client libraries released after 2026-07-01.
 *
 * Deliberately does not expose top-level `latestOrderId`: it is absent from the discovery schema
 * despite the API still emitting it, and it names the *declined* order on an outstanding payment.
 */
class SubscriptionV2Response extends AbstractResponse
{
    public readonly ?SubscriptionState $subscriptionState;

    /** Kept so an unrecognized future state can be logged rather than silently becoming null. */
    public readonly ?string $subscriptionStateRaw;

    public readonly ?string $regionCode;

    /** Absent while a signup payment is still pending. */
    public readonly ?Carbon $startedAt;

    public readonly ?string $linkedPurchaseToken;

    public readonly ?CanceledStateContext $canceledStateContext;

    public readonly ?PausedStateContext $pausedStateContext;

    public readonly ?InGracePeriodStateContext $inGracePeriodStateContext;

    public readonly ?OnHoldStateContext $onHoldStateContext;

    public readonly ?OutOfAppPurchaseContext $outOfAppPurchaseContext;

    /** Only set when the client passed it to `setObfuscatedAccountId()` at purchase time. */
    public readonly ?string $obfuscatedExternalAccountId;

    public readonly ?string $obfuscatedExternalProfileId;

    public readonly bool $isTestPurchase;

    /** @var list<SubscriptionPurchaseLineItem> */
    public readonly array $lineItems;

    public function __construct(SubscriptionPurchaseV2 $raw)
    {
        parent::__construct($raw);

        $this->subscriptionStateRaw = self::toStringOrNull(self::field($raw, 'subscriptionState'));
        $this->subscriptionState = $this->subscriptionStateRaw === null
            ? null
            : SubscriptionState::tryFrom($this->subscriptionStateRaw);
        $this->regionCode = self::toStringOrNull(self::field($raw, 'regionCode'));
        $this->startedAt = self::toDateFromRfc3339(self::field($raw, 'startTime'));
        $this->linkedPurchaseToken = self::toStringOrNull(self::field($raw, 'linkedPurchaseToken'));
        $this->canceledStateContext = self::field($raw, 'canceledStateContext');
        $this->pausedStateContext = self::field($raw, 'pausedStateContext');
        $this->inGracePeriodStateContext = self::field($raw, 'inGracePeriodStateContext');
        $this->onHoldStateContext = self::field($raw, 'onHoldStateContext');
        $this->outOfAppPurchaseContext = self::field($raw, 'outOfAppPurchaseContext');
        $this->isTestPurchase = self::field($raw, 'testPurchase') !== null;
        $this->lineItems = array_values(self::field($raw, 'lineItems') ?? []);

        $identifiers = self::field($raw, 'externalAccountIdentifiers');
        $this->obfuscatedExternalAccountId = $identifiers === null
            ? null
            : self::toStringOrNull(self::field($identifiers, 'obfuscatedExternalAccountId'));
        $this->obfuscatedExternalProfileId = $identifiers === null
            ? null
            : self::toStringOrNull(self::field($identifiers, 'obfuscatedExternalProfileId'));
    }

    public function isPurchasedOutOfApp(): bool
    {
        return $this->outOfAppPurchaseContext !== null;
    }

    /**
     * The line item that expires last — the one that determines entitlement.
     */
    public function primaryLineItem(): ?SubscriptionPurchaseLineItem
    {
        $latest = null;
        $latestExpiry = null;

        foreach ($this->lineItems as $item) {
            $expiry = self::toDateFromRfc3339(self::field($item, 'expiryTime'));
            if ($expiry !== null && ($latestExpiry === null || $expiry->greaterThan($latestExpiry))) {
                $latest = $item;
                $latestExpiry = $expiry;
            }
        }

        return $latest ?? ($this->lineItems[0] ?? null);
    }

    public function expiresAt(): ?Carbon
    {
        return self::toDateFromRfc3339($this->lineItemField('expiryTime'));
    }

    public function productId(): ?string
    {
        return self::toStringOrNull($this->lineItemField('productId'));
    }

    /**
     * Absent when no order has succeeded yet — a pending signup, or an item being deferred-replaced to.
     */
    public function latestSuccessfulOrderId(): ?string
    {
        return self::toStringOrNull($this->lineItemField('latestSuccessfulOrderId'));
    }

    /**
     * Nullable on purpose: absent must never read as "the customer turned auto-renew off", which is a
     * customer-visible write. Grace, hold and pause all report true.
     */
    public function autoRenewEnabled(): ?bool
    {
        $plan = $this->lineItemField('autoRenewingPlan');

        if ($plan === null) {
            return null;
        }

        $enabled = self::field($plan, 'autoRenewEnabled');

        return $enabled === null ? null : (bool) $enabled;
    }

    public function isPrepaid(): bool
    {
        return $this->lineItemField('prepaidPlan') !== null;
    }

    public function recurringPrice(): ?Money
    {
        $plan = $this->lineItemField('autoRenewingPlan');

        return $plan === null ? null : self::field($plan, 'recurringPrice');
    }

    /**
     * v1 reported `priceAmountMicros`; v2 splits it into units + nanos. Converted here so callers
     * keep consuming one number.
     */
    public function recurringPriceMicros(): ?string
    {
        $price = $this->recurringPrice();

        if ($price === null) {
            return null;
        }

        $units = self::toIntOrNull(self::field($price, 'units'));
        $nanos = self::toIntOrNull(self::field($price, 'nanos'));

        if ($units === null && $nanos === null) {
            return null;
        }

        return (string) (($units ?? 0) * 1000000 + intdiv($nanos ?? 0, 1000));
    }

    public function priceCurrencyCode(): ?string
    {
        $price = $this->recurringPrice();

        return $price === null ? null : self::toStringOrNull(self::field($price, 'currencyCode'));
    }

    public function offerPhase(): ?OfferPhase
    {
        return $this->lineItemField('offerPhase');
    }

    /**
     * The v2 replacement for v1's `paymentState === 2`. `basePrice` and `prorationPeriod` are the
     * other two phases and both are paid, so this must key on `freeTrial` alone.
     */
    public function isInFreeTrial(): bool
    {
        $phase = $this->offerPhase();

        return $phase !== null && self::field($phase, 'freeTrial') !== null;
    }

    public function isInIntroductoryPrice(): bool
    {
        $phase = $this->offerPhase();

        return $phase !== null && self::field($phase, 'introductoryPrice') !== null;
    }

    public function basePlanId(): ?string
    {
        $details = $this->lineItemField('offerDetails');

        return $details === null ? null : self::toStringOrNull(self::field($details, 'basePlanId'));
    }

    /** Only present for discounted offers. */
    public function offerId(): ?string
    {
        $details = $this->lineItemField('offerDetails');

        return $details === null ? null : self::toStringOrNull(self::field($details, 'offerId'));
    }

    /**
     * @return list<string>
     */
    public function offerTags(): array
    {
        $details = $this->lineItemField('offerDetails');
        $tags = $details === null ? null : self::field($details, 'offerTags');

        return array_values(array_map('strval', $tags ?? []));
    }

    /**
     * Only a vanity code carries a value. `oneTimeCode` is a fieldless marker, so a signup promotion
     * can be present with no code to report — use {@see hasSignupPromotion()} for presence.
     */
    public function signupPromotionCode(): ?string
    {
        $vanity = $this->signupPromotionField('vanityCode');

        return $vanity === null ? null : self::toStringOrNull(self::field($vanity, 'promotionCode'));
    }

    public function hasSignupPromotion(): bool
    {
        return $this->signupPromotionField('vanityCode') !== null
            || $this->signupPromotionField('oneTimeCode') !== null;
    }

    private function signupPromotionField(string $field): mixed
    {
        $promotion = $this->lineItemField('signupPromotion');

        return $promotion === null ? null : self::field($promotion, $field);
    }

    /**
     * Which of the four cancellation contexts is present. Three carry no fields at all, so this is a
     * presence test — an empty object is a real answer, not a missing one.
     */
    public function cancellationInitiator(): ?CancellationInitiator
    {
        if ($this->canceledStateContext === null) {
            return null;
        }

        return match (true) {
            self::field($this->canceledStateContext, 'userInitiatedCancellation') !== null => CancellationInitiator::USER,
            self::field($this->canceledStateContext, 'systemInitiatedCancellation') !== null => CancellationInitiator::SYSTEM,
            self::field($this->canceledStateContext, 'developerInitiatedCancellation') !== null => CancellationInitiator::DEVELOPER,
            self::field($this->canceledStateContext, 'replacementCancellation') !== null => CancellationInitiator::REPLACEMENT,
            default => null,
        };
    }

    /**
     * When the user hit cancel — never when access ends. Only the user-initiated context carries it.
     */
    public function cancelledAt(): ?Carbon
    {
        if ($this->canceledStateContext === null) {
            return null;
        }

        $cancellation = self::field($this->canceledStateContext, 'userInitiatedCancellation');

        return $cancellation === null ? null : self::toDateFromRfc3339(self::field($cancellation, 'cancelTime'));
    }

    /**
     * The order whose payment was declined, from whichever of the grace/hold contexts is present.
     * Never a transaction id — the payment failed.
     */
    public function declinedOrderId(): ?string
    {
        $context = $this->inGracePeriodStateContext ?? $this->onHoldStateContext;

        if ($context === null) {
            return null;
        }

        $declined = self::field($context, 'renewalDeclined');

        return $declined === null ? null : self::toStringOrNull(self::field($declined, 'pendingOrderId'));
    }

    public function autoResumeAt(): ?Carbon
    {
        return $this->pausedStateContext === null
            ? null
            : self::toDateFromRfc3339(self::field($this->pausedStateContext, 'autoResumeTime'));
    }

    private function lineItemField(string $property): mixed
    {
        $item = $this->primaryLineItem();

        return $item === null ? null : self::field($item, $property);
    }

    /**
     * v2 uses RFC 3339 timestamps where v1 used epoch millis.
     */
    protected static function toDateFromRfc3339(mixed $value): ?Carbon
    {
        $value = self::toStringOrNull($value);

        return $value === null ? null : Carbon::parse($value)->utc();
    }
}
