<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Tests\Unit;

use Breakpoint\GooglePlay\Enums\AcknowledgementState;
use Breakpoint\GooglePlay\Enums\CancelReason;
use Breakpoint\GooglePlay\Enums\ConsumptionState;
use Breakpoint\GooglePlay\Enums\PaymentState;
use Breakpoint\GooglePlay\Enums\PurchaseState;
use Breakpoint\GooglePlay\Responses\PurchaseResponse;
use Breakpoint\GooglePlay\Responses\SubscriptionResponse;
use Breakpoint\GooglePlay\Responses\SubscriptionV2Response;
use Breakpoint\GooglePlay\Tests\Support\Fixtures;
use Breakpoint\GooglePlay\Tests\TestCase;

/**
 * The fork's semantics are the spec: millis→UTC Carbon, nullable payment state, and the fields
 * the app used to reach through getRawResponse() for.
 */
class ResponseParsingTest extends TestCase
{
    public function test_a_subscription_parses_every_promoted_field(): void
    {
        $response = new SubscriptionResponse(Fixtures::subscriptionPurchase([
            'linkedPurchaseToken' => 'token-previous-999',
            'promotionCode' => 'SPRING26',
            'externalAccountId' => 'ext-42',
        ]));

        $this->assertTrue($response->autoRenewing);
        $this->assertSame('US', $response->countryCode);
        $this->assertSame('39990000', $response->priceAmountMicros);
        $this->assertSame('USD', $response->priceCurrencyCode);
        $this->assertSame('GPA.1111-2222-3333-44444', $response->orderId);
        $this->assertSame('token-previous-999', $response->linkedPurchaseToken);
        $this->assertSame('SPRING26', $response->promotionCode);
        $this->assertSame('ext-42', $response->externalAccountId);
        $this->assertSame(PaymentState::RECEIVED, $response->paymentState);
        $this->assertSame(AcknowledgementState::ACKNOWLEDGED, $response->acknowledgementState);
        $this->assertTrue($response->isAcknowledged());
    }

    public function test_subscription_dates_come_back_as_utc_carbon(): void
    {
        $response = new SubscriptionResponse(Fixtures::subscriptionPurchase([
            '_started_at' => '2026-01-15 12:00:00',
            '_expires_at' => '2027-01-15 12:00:00',
        ]));

        $this->assertSame('2026-01-15 12:00:00', $response->startedAt?->toDateTimeString());
        $this->assertSame('2027-01-15 12:00:00', $response->expiresAt?->toDateTimeString());
        $this->assertSame(0, $response->startedAt?->utcOffset());
    }

    public function test_a_user_cancellation_populates_the_cancellation_date(): void
    {
        $response = new SubscriptionResponse(Fixtures::subscriptionPurchase([
            'userCancellationTimeMillis' => (string) (strtotime('2026-06-01 09:00:00 UTC') * 1000),
            'cancelReason' => 0,
        ]));

        $this->assertSame('2026-06-01 09:00:00', $response->cancelledAt?->toDateTimeString());
        $this->assertSame(CancelReason::USER, $response->cancelReason);
    }

    /**
     * The fork's key fix: expired and canceled subscriptions omit paymentState entirely.
     */
    public function test_an_absent_payment_state_is_null_not_zero(): void
    {
        $response = new SubscriptionResponse(Fixtures::subscriptionPurchase(['paymentState' => null]));

        $this->assertNull($response->paymentState);
        $this->assertFalse($response->isInBillingGrace());
        $this->assertFalse($response->isInTrial());
    }

    public function test_pending_and_deferred_payment_states_are_distinguished(): void
    {
        $grace = new SubscriptionResponse(Fixtures::subscriptionPurchase(['paymentState' => 0]));
        $trial = new SubscriptionResponse(Fixtures::subscriptionPurchase(['paymentState' => 2]));

        $this->assertTrue($grace->isInBillingGrace());
        $this->assertFalse($grace->isInTrial());
        $this->assertTrue($trial->isInTrial());
        $this->assertFalse($trial->isInBillingGrace());
    }

    public function test_a_missing_expiry_does_not_blow_up(): void
    {
        $response = new SubscriptionResponse(Fixtures::subscriptionPurchase(['expiryTimeMillis' => null]));

        $this->assertNull($response->expiresAt);
    }

    /**
     * The v1 fork returned purchaseState as a string, which made the app's strict int comparison
     * unreachable and let canceled one-time purchases through. It is an enum here.
     */
    public function test_a_canceled_one_time_purchase_is_typed(): void
    {
        $canceled = new PurchaseResponse(Fixtures::productPurchase(['purchaseState' => 1]));
        $purchased = new PurchaseResponse(Fixtures::productPurchase(['purchaseState' => 0]));

        $this->assertSame(PurchaseState::CANCELED, $canceled->purchaseState);
        $this->assertTrue($canceled->isCanceled());
        $this->assertSame(PurchaseState::PURCHASED, $purchased->purchaseState);
        $this->assertFalse($purchased->isCanceled());
    }

    public function test_a_one_time_purchase_parses_its_fields(): void
    {
        $response = new PurchaseResponse(Fixtures::productPurchase(['_purchased_at' => '2026-03-04 08:00:00']));

        $this->assertSame('2026-03-04 08:00:00', $response->purchasedAt?->toDateTimeString());
        $this->assertSame(ConsumptionState::YET_TO_BE_CONSUMED, $response->consumptionState);
        $this->assertSame('GPA.1111-2222-3333-44444', $response->orderId);
    }

    public function test_a_json_developer_payload_is_decoded(): void
    {
        $response = new PurchaseResponse(Fixtures::productPurchase([
            'developerPayload' => json_encode(['user' => '42']),
        ]));

        $this->assertSame(['user' => '42'], $response->developerPayload);
        $this->assertSame('42', $response->developerPayloadValue('user'));
        $this->assertNull($response->developerPayloadValue('nope'));
    }

    public function test_a_non_json_developer_payload_survives_as_a_string(): void
    {
        $response = new PurchaseResponse(Fixtures::productPurchase(['developerPayload' => 'just-a-string']));

        $this->assertSame('just-a-string', $response->developerPayload);
        $this->assertNull($response->developerPayloadValue('user'));
    }

    public function test_an_empty_developer_payload_is_null(): void
    {
        $this->assertNull((new PurchaseResponse(Fixtures::productPurchase()))->developerPayload);
    }

    public function test_the_v2_response_exposes_lifecycle_context(): void
    {
        $response = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'linkedPurchaseToken' => 'token-previous-999',
            'outOfAppPurchaseContext' => [],
        ]));

        $this->assertSame('SUBSCRIPTION_STATE_ACTIVE', $response->subscriptionState);
        $this->assertSame('US', $response->regionCode);
        $this->assertSame('token-previous-999', $response->linkedPurchaseToken);
        $this->assertTrue($response->isPurchasedOutOfApp());
        $this->assertSame('2026-01-15 12:00:00', $response->startedAt?->toDateTimeString());
    }

    /**
     * v2 uses RFC 3339 where v1 used epoch millis; entitlement follows the latest line item.
     */
    public function test_the_v2_expiry_is_the_latest_line_item(): void
    {
        $response = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'lineItems' => [
                ['productId' => 'a', 'expiryTime' => '2026-06-01T00:00:00Z'],
                ['productId' => 'b', 'expiryTime' => '2027-01-15T12:00:00Z'],
            ],
        ]));

        $this->assertSame('2027-01-15 12:00:00', $response->expiresAt()?->toDateTimeString());
        $this->assertSame('b', $response->primaryLineItem()?->getProductId());
    }

    public function test_the_v2_response_survives_no_line_items(): void
    {
        $response = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2(['lineItems' => []]));

        $this->assertNull($response->expiresAt());
        $this->assertNull($response->primaryLineItem());
    }
}
