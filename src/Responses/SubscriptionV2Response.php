<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Responses;

use Carbon\Carbon;
use Google\Service\AndroidPublisher\CanceledStateContext;
use Google\Service\AndroidPublisher\OutOfAppPurchaseContext;
use Google\Service\AndroidPublisher\PausedStateContext;
use Google\Service\AndroidPublisher\SubscriptionPurchaseLineItem;
use Google\Service\AndroidPublisher\SubscriptionPurchaseV2;

/**
 * purchases.subscriptionsv2 — the only resource carrying the lifecycle context fields
 * (subscriptionState, cancellation/pause reasons, out-of-app purchase context, deferred
 * replacement). v1 stays the workhorse; this is consumed where those fields are needed.
 */
class SubscriptionV2Response extends AbstractResponse
{
    /** e.g. SUBSCRIPTION_STATE_ACTIVE, _IN_GRACE_PERIOD, _ON_HOLD, _CANCELED, _EXPIRED, _PAUSED. */
    public readonly ?string $subscriptionState;

    public readonly ?string $regionCode;

    public readonly ?Carbon $startedAt;

    public readonly ?string $latestOrderId;

    public readonly ?string $linkedPurchaseToken;

    public readonly ?CanceledStateContext $canceledStateContext;

    public readonly ?PausedStateContext $pausedStateContext;

    public readonly ?OutOfAppPurchaseContext $outOfAppPurchaseContext;

    public readonly bool $isTestPurchase;

    /** @var list<SubscriptionPurchaseLineItem> */
    public readonly array $lineItems;

    public function __construct(SubscriptionPurchaseV2 $raw)
    {
        parent::__construct($raw);

        $this->subscriptionState = self::toStringOrNull(self::field($raw, 'subscriptionState'));
        $this->regionCode = self::toStringOrNull(self::field($raw, 'regionCode'));
        $this->startedAt = self::toDateFromRfc3339(self::field($raw, 'startTime'));
        $this->latestOrderId = self::toStringOrNull(self::field($raw, 'latestOrderId'));
        $this->linkedPurchaseToken = self::toStringOrNull(self::field($raw, 'linkedPurchaseToken'));
        $this->canceledStateContext = self::field($raw, 'canceledStateContext');
        $this->pausedStateContext = self::field($raw, 'pausedStateContext');
        $this->outOfAppPurchaseContext = self::field($raw, 'outOfAppPurchaseContext');
        $this->isTestPurchase = self::field($raw, 'testPurchase') !== null;
        $this->lineItems = array_values(self::field($raw, 'lineItems') ?? []);
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
        $item = $this->primaryLineItem();

        return $item === null ? null : self::toDateFromRfc3339(self::field($item, 'expiryTime'));
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
