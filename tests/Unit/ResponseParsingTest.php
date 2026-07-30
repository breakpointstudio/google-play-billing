<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Tests\Unit;

use Breakpoint\GooglePlay\Enums\AcknowledgementState;
use Breakpoint\GooglePlay\Enums\CancellationInitiator;
use Breakpoint\GooglePlay\Enums\ConsumptionState;
use Breakpoint\GooglePlay\Enums\PurchaseState;
use Breakpoint\GooglePlay\Enums\SubscriptionState;
use Breakpoint\GooglePlay\Responses\PurchaseResponse;
use Breakpoint\GooglePlay\Responses\SubscriptionV2Response;
use Breakpoint\GooglePlay\Tests\Support\Fixtures;
use Breakpoint\GooglePlay\Tests\TestCase;

/**
 * The fork's semantics are the spec: millis→UTC Carbon, nullable payment state, and the fields
 * the app used to reach through getRawResponse() for.
 */
class ResponseParsingTest extends TestCase
{
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

        $this->assertSame(SubscriptionState::ACTIVE, $response->subscriptionState);
        $this->assertSame('SUBSCRIPTION_STATE_ACTIVE', $response->subscriptionStateRaw);
        $this->assertSame('US', $response->regionCode);
        $this->assertSame('token-previous-999', $response->linkedPurchaseToken);
        $this->assertTrue($response->isPurchasedOutOfApp());
        $this->assertSame('2026-01-15 12:00:00', $response->startedAt?->toDateTimeString());
        $this->assertSame('GPA.1111-2222-3333-44444', $response->latestSuccessfulOrderId());
        $this->assertSame('com.consumedbycode.slopes.seasonpass', $response->productId());
        $this->assertSame('seasonpass', $response->basePlanId());
        $this->assertSame(['tier-one'], $response->offerTags());
    }

    /**
     * v1 sends an int and v2 a string; the int cast that handled v1 read every v2 string as 0, which
     * meant "unacknowledged" forever and a redundant acknowledge on every notification.
     */
    public function test_the_v2_acknowledgement_state_parses_from_its_string_form(): void
    {
        $acknowledged = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2());
        $this->assertSame(AcknowledgementState::ACKNOWLEDGED, $acknowledged->acknowledgementState);
        $this->assertTrue($acknowledged->isAcknowledged());

        $pending = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_PENDING',
        ]));
        $this->assertSame(AcknowledgementState::YET_TO_BE_ACKNOWLEDGED, $pending->acknowledgementState);
        $this->assertFalse($pending->isAcknowledged());

        $unspecified = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_UNSPECIFIED',
        ]));
        $this->assertNull($unspecified->acknowledgementState);
        $this->assertFalse($unspecified->isAcknowledged());
    }

    /**
     * The dangerous default: a false here mass-writes "the customer turned auto-renew off" and mails
     * them about it. Absent must stay absent, including when the plan object itself is present.
     */
    public function test_auto_renew_enabled_distinguishes_absent_from_false(): void
    {
        $this->assertTrue((new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2()))->autoRenewEnabled());

        $planPresentFlagNull = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'lineItems' => [['productId' => 'a', 'expiryTime' => '2027-01-15T12:00:00Z', 'autoRenewingPlan' => []]],
        ]));
        $this->assertNull($planPresentFlagNull->autoRenewEnabled());

        $planAbsent = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'lineItems' => [['productId' => 'a', 'expiryTime' => '2027-01-15T12:00:00Z']],
        ]));
        $this->assertNull($planAbsent->autoRenewEnabled());

        $off = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'lineItems' => [[
                'productId' => 'a',
                'expiryTime' => '2027-01-15T12:00:00Z',
                'autoRenewingPlan' => ['autoRenewEnabled' => false],
            ]],
        ]));
        $this->assertFalse($off->autoRenewEnabled());
    }

    /**
     * `oneTimeCode` is a fieldless marker object, so presence and code have to be separate questions —
     * asking for a code returned the object itself and crashed on stringification.
     */
    public function test_a_signup_promotion_reports_a_code_only_when_it_has_one(): void
    {
        $item = fn (array $promotion): array => [
            ['productId' => 'a', 'expiryTime' => '2027-01-15T12:00:00Z', 'signupPromotion' => $promotion],
        ];

        $vanity = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'lineItems' => $item(['vanityCode' => ['promotionCode' => 'SKIFREE']]),
        ]));
        $this->assertSame('SKIFREE', $vanity->signupPromotionCode());
        $this->assertTrue($vanity->hasSignupPromotion());

        $oneTime = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'lineItems' => $item(['oneTimeCode' => []]),
        ]));
        $this->assertNull($oneTime->signupPromotionCode());
        $this->assertTrue($oneTime->hasSignupPromotion());

        $none = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2());
        $this->assertNull($none->signupPromotionCode());
        $this->assertFalse($none->hasSignupPromotion());
    }

    /**
     * Four offer phases exist and only one is a trial; `basePrice` and `prorationPeriod` are paid.
     */
    public function test_only_the_free_trial_offer_phase_counts_as_a_trial(): void
    {
        $item = fn (array $phase): array => [
            ['productId' => 'a', 'expiryTime' => '2027-01-15T12:00:00Z', 'offerPhase' => $phase],
        ];

        $trial = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2(['lineItems' => $item(['freeTrial' => []])]));
        $this->assertTrue($trial->isInFreeTrial());
        $this->assertFalse($trial->isInIntroductoryPrice());

        $intro = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2(['lineItems' => $item(['introductoryPrice' => []])]));
        $this->assertFalse($intro->isInFreeTrial());
        $this->assertTrue($intro->isInIntroductoryPrice());

        foreach (['basePrice', 'prorationPeriod'] as $paidPhase) {
            $paid = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2(['lineItems' => $item([$paidPhase => []])]));
            $this->assertFalse($paid->isInFreeTrial(), "{$paidPhase} is a paid phase, not a trial");
            $this->assertFalse($paid->isInIntroductoryPrice());
        }

        $none = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2());
        $this->assertFalse($none->isInFreeTrial());
    }

    /**
     * Three of the four cancellation contexts carry no fields, so an empty object is a real answer.
     */
    public function test_every_cancellation_variant_is_identified_by_presence(): void
    {
        $variants = [
            'userInitiatedCancellation' => CancellationInitiator::USER,
            'systemInitiatedCancellation' => CancellationInitiator::SYSTEM,
            'developerInitiatedCancellation' => CancellationInitiator::DEVELOPER,
            'replacementCancellation' => CancellationInitiator::REPLACEMENT,
        ];

        foreach ($variants as $key => $expected) {
            $response = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
                'subscriptionState' => 'SUBSCRIPTION_STATE_CANCELED',
                'canceledStateContext' => [$key => []],
            ]));

            $this->assertSame($expected, $response->cancellationInitiator(), "{$key} with an empty body");
        }

        $withTime = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'canceledStateContext' => ['userInitiatedCancellation' => ['cancelTime' => '2026-07-01T09:30:00Z']],
        ]));
        $this->assertSame('2026-07-01 09:30:00', $withTime->cancelledAt()?->toDateTimeString());

        // A system cancellation has no timestamp to report — don't invent one.
        $system = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'canceledStateContext' => ['systemInitiatedCancellation' => []],
        ]));
        $this->assertNull($system->cancelledAt());

        $none = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2());
        $this->assertNull($none->cancellationInitiator());
        $this->assertNull($none->cancelledAt());
    }

    public function test_the_declined_order_id_comes_from_whichever_state_context_is_present(): void
    {
        $grace = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'subscriptionState' => 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD',
            'inGracePeriodStateContext' => ['renewalDeclined' => ['pendingOrderId' => 'GPA.decl-1']],
        ]));
        $this->assertSame('GPA.decl-1', $grace->declinedOrderId());

        $hold = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'subscriptionState' => 'SUBSCRIPTION_STATE_ON_HOLD',
            'onHoldStateContext' => ['renewalDeclined' => ['pendingOrderId' => 'GPA.decl-2']],
        ]));
        $this->assertSame('GPA.decl-2', $hold->declinedOrderId());

        // `renewalDeclined` is an optional union member, so an empty context is legal.
        $empty = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'inGracePeriodStateContext' => [],
        ]));
        $this->assertNull($empty->declinedOrderId());
    }

    public function test_an_unrecognized_subscription_state_keeps_its_raw_value(): void
    {
        $response = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'subscriptionState' => 'SUBSCRIPTION_STATE_SOMETHING_NEW',
        ]));

        $this->assertNull($response->subscriptionState);
        $this->assertSame('SUBSCRIPTION_STATE_SOMETHING_NEW', $response->subscriptionStateRaw);
    }

    public function test_the_recurring_price_converts_to_micros(): void
    {
        $response = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2());

        $this->assertSame('29990000', $response->recurringPriceMicros());
        $this->assertSame('USD', $response->priceCurrencyCode());

        $noPlan = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'lineItems' => [['productId' => 'a', 'expiryTime' => '2027-01-15T12:00:00Z']],
        ]));
        $this->assertNull($noPlan->recurringPriceMicros());
        $this->assertNull($noPlan->priceCurrencyCode());
    }

    public function test_the_obfuscated_account_id_reads_from_external_account_identifiers(): void
    {
        $withId = new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2([
            'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => '018f4e1a-0000-7000-8000-000000000000'],
        ]));
        $this->assertSame('018f4e1a-0000-7000-8000-000000000000', $withId->obfuscatedExternalAccountId);

        // The production shape until an Android release sets it.
        $this->assertNull((new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2()))->obfuscatedExternalAccountId);
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
