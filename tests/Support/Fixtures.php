<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Tests\Support;

use Carbon\Carbon;
use Google\Service\AndroidPublisher\ProductPurchase;
use Google\Service\AndroidPublisher\SubscriptionPurchase;
use Google\Service\AndroidPublisher\SubscriptionPurchaseV2;

class Fixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function subscriptionPurchase(array $overrides = []): SubscriptionPurchase
    {
        $startedAt = Carbon::parse($overrides['_started_at'] ?? '2026-01-15 12:00:00');
        $expiresAt = Carbon::parse($overrides['_expires_at'] ?? $startedAt->copy()->addYear()->toDateTimeString());
        unset($overrides['_started_at'], $overrides['_expires_at']);

        return new SubscriptionPurchase(array_merge([
            'kind' => 'androidpublisher#subscriptionPurchase',
            'startTimeMillis' => (string) ($startedAt->getTimestamp() * 1000),
            'expiryTimeMillis' => (string) ($expiresAt->getTimestamp() * 1000),
            'autoRenewing' => true,
            'priceCurrencyCode' => 'USD',
            'priceAmountMicros' => '39990000',
            'countryCode' => 'US',
            'paymentState' => 1,
            'acknowledgementState' => 1,
            'orderId' => 'GPA.1111-2222-3333-44444',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function productPurchase(array $overrides = []): ProductPurchase
    {
        $purchasedAt = Carbon::parse($overrides['_purchased_at'] ?? '2026-01-15 12:00:00');
        unset($overrides['_purchased_at']);

        return new ProductPurchase(array_merge([
            'kind' => 'androidpublisher#productPurchase',
            'purchaseTimeMillis' => (string) ($purchasedAt->getTimestamp() * 1000),
            'purchaseState' => 0,
            'consumptionState' => 0,
            'acknowledgementState' => 1,
            'orderId' => 'GPA.1111-2222-3333-44444',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function subscriptionPurchaseV2(array $overrides = []): SubscriptionPurchaseV2
    {
        return new SubscriptionPurchaseV2(array_merge([
            'kind' => 'androidpublisher#subscriptionPurchaseV2',
            'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
            'regionCode' => 'US',
            'startTime' => '2026-01-15T12:00:00Z',
            'latestOrderId' => 'GPA.1111-2222-3333-44444',
            'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
            'lineItems' => [
                ['productId' => 'com.consumedbycode.slopes.seasonpass', 'expiryTime' => '2027-01-15T12:00:00Z'],
            ],
        ], $overrides));
    }

    /**
     * The Pub/Sub push envelope, with both the camelCase and snake_case message fields Google sends.
     *
     * @param  array<string, mixed>  $notification
     * @return array<string, mixed>
     */
    public static function envelope(array $notification, string $messageId = '9876543210'): array
    {
        return [
            'message' => [
                'data' => base64_encode((string) json_encode(array_merge([
                    'version' => '1.0',
                    'packageName' => 'com.consumedbycode.slopes',
                    'eventTimeMillis' => '1784898061891',
                ], $notification))),
                'messageId' => $messageId,
                'message_id' => $messageId,
                'publishTime' => '2026-02-15T12:00:01.000Z',
                'publish_time' => '2026-02-15T12:00:01.000Z',
            ],
            'subscription' => 'projects/slopes-ba98d/subscriptions/billing_api_sub',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function subscriptionEnvelope(int $notificationType, string $messageId = '9876543210'): array
    {
        return self::envelope([
            'subscriptionNotification' => [
                'version' => '1.0',
                'notificationType' => $notificationType,
                'purchaseToken' => 'token-abc-123',
                'subscriptionId' => 'com.consumedbycode.slopes.seasonpass',
            ],
        ], $messageId);
    }
}
