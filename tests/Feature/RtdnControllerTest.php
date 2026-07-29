<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Tests\Feature;

use Breakpoint\GooglePlay\Enums\NotificationType;
use Breakpoint\GooglePlay\Enums\OneTimeProductNotificationType;
use Breakpoint\GooglePlay\Enums\ProductType;
use Breakpoint\GooglePlay\Enums\RefundType;
use Breakpoint\GooglePlay\Events;
use Breakpoint\GooglePlay\GooglePlayManager;
use Breakpoint\GooglePlay\Http\RtdnController;
use Breakpoint\GooglePlay\Responses\SubscriptionV2Response;
use Breakpoint\GooglePlay\Tests\Support\FakeGooglePlayManager;
use Breakpoint\GooglePlay\Tests\Support\FakeValidator;
use Breakpoint\GooglePlay\Tests\Support\Fixtures;
use Breakpoint\GooglePlay\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

class RtdnControllerTest extends TestCase
{
    private const ENDPOINT = '/rtdn';

    private FakeValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new FakeValidator(new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2()));
        $this->app->instance(GooglePlayManager::class, new FakeGooglePlayManager($this->validator));

        Route::post(self::ENDPOINT, RtdnController::class);
        Event::fake();
    }

    public function test_an_envelope_without_a_message_is_rejected(): void
    {
        $this->postJson(self::ENDPOINT, ['subscription' => 'projects/x/subscriptions/y'])->assertStatus(422);
    }

    public function test_undecodable_data_is_rejected(): void
    {
        $payload = Fixtures::subscriptionEnvelope(NotificationType::RENEWED->value);
        $payload['message']['data'] = base64_encode('not json');

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(422);
    }

    /**
     * 200, not 422 — a 422 makes Pub/Sub redeliver for the whole retention window, and this will
     * never become deliverable. Supersedes the previous 422 expectation (G15).
     */
    public function test_another_package_is_accepted_and_logged_rather_than_retried(): void
    {
        $payload = Fixtures::envelope([
            'packageName' => 'com.someoneelse.app',
            'subscriptionNotification' => ['notificationType' => 2, 'purchaseToken' => 't', 'subscriptionId' => 's'],
        ]);

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(200);

        $this->assertSame(0, $this->validator->subscriptionCalls, 'another package must not cost a Google call');
    }

    /**
     * Google is removing `subscriptionId` from the notification, so the fetch must not need it.
     */
    public function test_the_refetch_is_keyed_on_the_token_alone(): void
    {
        $this->postJson(self::ENDPOINT, Fixtures::subscriptionEnvelope(NotificationType::RENEWED->value))
            ->assertStatus(200);

        $this->assertSame('token-abc-123', $this->validator->lastPurchaseToken);
        $this->assertNull($this->validator->lastProductId);
    }

    public function test_a_pending_refund_review_dispatches_rather_than_falling_through_to_unknown(): void
    {
        $payload = Fixtures::envelope([
            'pendingRefundReviewNotification' => [
                'pendingRefundToken' => 'refund-token-1',
                'orderId' => 'GPA.1234-5678-9012-34567',
                'refundReason' => 7,
                'obfuscatedAccountId' => '018f4e1a-0000-7000-8000-000000000000',
            ],
        ]);

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(200);

        Event::assertNotDispatched(Events\UnknownNotification::class);
        Event::assertDispatched(Events\PendingRefundReview::class, function (Events\PendingRefundReview $event): bool {
            return $event->pendingRefundToken === 'refund-token-1'
                && $event->orderId === 'GPA.1234-5678-9012-34567'
                && $event->obfuscatedAccountId === '018f4e1a-0000-7000-8000-000000000000'
                && $event->isChargeback();
        });
    }

    public function test_a_renewal_dispatches_a_typed_event_with_the_refetched_subscription(): void
    {
        $this->postJson(self::ENDPOINT, Fixtures::subscriptionEnvelope(NotificationType::RENEWED->value))
            ->assertStatus(200);

        Event::assertDispatched(Events\SubscriptionRenewed::class, function (Events\SubscriptionRenewed $event): bool {
            return $event->type === NotificationType::RENEWED
                && $event->purchaseToken === 'token-abc-123'
                && $event->subscriptionId === 'com.consumedbycode.slopes.seasonpass'
                && $event->subscription->latestSuccessfulOrderId() === 'GPA.1111-2222-3333-44444'
                && $event->subscription->productId() === 'com.consumedbycode.slopes.seasonpass'
                && $event->messageId === '9876543210';
        });
    }

    public static function subscriptionTypeProvider(): array
    {
        return [
            'recovered' => [1, Events\SubscriptionRecovered::class],
            'renewed' => [2, Events\SubscriptionRenewed::class],
            'canceled' => [3, Events\SubscriptionCanceled::class],
            'purchased' => [4, Events\SubscriptionPurchased::class],
            'on hold' => [5, Events\SubscriptionOnHold::class],
            'in grace period' => [6, Events\SubscriptionInGracePeriod::class],
            'restarted' => [7, Events\SubscriptionRestarted::class],
            'price change confirmed' => [8, Events\SubscriptionPriceChangeConfirmed::class],
            'deferred' => [9, Events\SubscriptionDeferred::class],
            'paused' => [10, Events\SubscriptionPaused::class],
            'pause schedule changed' => [11, Events\SubscriptionPauseScheduleChanged::class],
            'revoked' => [12, Events\SubscriptionRevoked::class],
            'expired' => [13, Events\SubscriptionExpired::class],
            'items changed' => [17, Events\SubscriptionItemsChanged::class],
            'cancellation scheduled' => [18, Events\SubscriptionCancellationScheduled::class],
            'price change updated' => [19, Events\SubscriptionPriceChangeUpdated::class],
            'pending purchase canceled' => [20, Events\SubscriptionPendingPurchaseCanceled::class],
            'price step up consent updated' => [22, Events\SubscriptionPriceStepUpConsentUpdated::class],
        ];
    }

    #[DataProvider('subscriptionTypeProvider')]
    public function test_every_modelled_subscription_type_maps_to_its_event(int $type, string $expected): void
    {
        $this->postJson(self::ENDPOINT, Fixtures::subscriptionEnvelope($type))->assertStatus(200);

        Event::assertDispatched($expected);
    }

    /**
     * Google adds codes without notice; an unknown one must not burn the retry budget.
     */
    public function test_an_unmodelled_subscription_type_is_accepted_and_flagged(): void
    {
        $this->postJson(self::ENDPOINT, Fixtures::subscriptionEnvelope(9999))->assertStatus(200);

        Event::assertDispatched(Events\UnknownNotification::class, fn (Events\UnknownNotification $e): bool => $e->rawType === 9999);
        Event::assertNotDispatched(Events\SubscriptionRenewed::class);
    }

    public function test_a_one_time_purchase_dispatches_its_event(): void
    {
        $payload = Fixtures::envelope(['oneTimeProductNotification' => [
            'version' => '1.0',
            'notificationType' => 1,
            'purchaseToken' => 'token-one-time',
            'sku' => 'com.consumedbycode.slopes.temppass',
        ]]);

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(200);

        Event::assertDispatched(Events\OneTimeProductPurchased::class, function (Events\OneTimeProductPurchased $e): bool {
            return $e->sku === 'com.consumedbycode.slopes.temppass'
                && $e->type === OneTimeProductNotificationType::PURCHASED;
        });
    }

    public function test_a_one_time_cancellation_dispatches_its_event(): void
    {
        $payload = Fixtures::envelope(['oneTimeProductNotification' => [
            'notificationType' => 2,
            'purchaseToken' => 'token-one-time',
            'sku' => 'com.consumedbycode.slopes.temppass',
        ]]);

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(200);

        Event::assertDispatched(Events\OneTimeProductCanceled::class);
    }

    /**
     * The shape the legacy controller 422'd — refunds and chargebacks arrive here.
     */
    public function test_a_voided_purchase_dispatches_its_event(): void
    {
        $payload = Fixtures::envelope(['voidedPurchaseNotification' => [
            'purchaseToken' => 'token-voided',
            'orderId' => 'GPA.9999-8888-7777-66666',
            'productType' => 1,
            'refundType' => 2,
        ]]);

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(200);

        Event::assertDispatched(Events\VoidedPurchase::class, function (Events\VoidedPurchase $e): bool {
            return $e->purchaseToken === 'token-voided'
                && $e->orderId === 'GPA.9999-8888-7777-66666'
                && $e->productType === ProductType::SUBSCRIPTION
                && $e->refundType === RefundType::QUANTITY_BASED_PARTIAL
                && $e->isPartialRefund();
        });
    }

    public function test_a_test_notification_dispatches_its_event(): void
    {
        $this->postJson(self::ENDPOINT, Fixtures::envelope(['testNotification' => ['version' => '1.0']]))
            ->assertStatus(200);

        Event::assertDispatched(Events\TestNotification::class);
    }

    public function test_an_unmodelled_shape_is_accepted_and_flagged(): void
    {
        $this->postJson(self::ENDPOINT, Fixtures::envelope(['somethingBrandNew' => ['x' => 1]]))
            ->assertStatus(200);

        Event::assertDispatched(Events\UnknownNotification::class);
    }

    public function test_a_redelivered_message_is_a_no_op(): void
    {
        $payload = Fixtures::subscriptionEnvelope(NotificationType::RENEWED->value, 'dupe-1');

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(200);
        $this->postJson(self::ENDPOINT, $payload)->assertStatus(200);

        Event::assertDispatchedTimes(Events\SubscriptionRenewed::class, 1);
    }

    /**
     * A failed delivery must be retryable, so the dedupe claim is released when handling throws.
     */
    public function test_a_failed_delivery_releases_its_dedupe_claim(): void
    {
        $this->app->instance(GooglePlayManager::class, new FakeGooglePlayManager(
            new FakeValidator(new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2()), failuresBeforeSuccess: 99),
        ));
        $payload = Fixtures::subscriptionEnvelope(NotificationType::RENEWED->value, 'retry-me');

        $this->postJson(self::ENDPOINT, $payload)->assertStatus(503);

        $this->app->instance(GooglePlayManager::class, new FakeGooglePlayManager($this->validator));
        $this->postJson(self::ENDPOINT, $payload)->assertStatus(200);

        Event::assertDispatchedTimes(Events\SubscriptionRenewed::class, 1);
    }

    public function test_the_refetch_retries_before_giving_up_when_retries_are_configured(): void
    {
        config(['google-play-billing.rtdn.retries' => 3]);
        $validator = new FakeValidator(new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2()), failuresBeforeSuccess: 2);
        $this->app->instance(GooglePlayManager::class, new FakeGooglePlayManager($validator));

        $this->postJson(self::ENDPOINT, Fixtures::subscriptionEnvelope(NotificationType::RENEWED->value))
            ->assertStatus(200);

        $this->assertSame(3, $validator->subscriptionCalls);
        Event::assertDispatched(Events\SubscriptionRenewed::class);
    }

    public function test_the_refetch_makes_one_attempt_by_default(): void
    {
        $validator = new FakeValidator(new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2()), failuresBeforeSuccess: 1);
        $this->app->instance(GooglePlayManager::class, new FakeGooglePlayManager($validator));

        $this->postJson(self::ENDPOINT, Fixtures::subscriptionEnvelope(NotificationType::RENEWED->value))
            ->assertStatus(503);

        $this->assertSame(1, $validator->subscriptionCalls);
        Event::assertNotDispatched(Events\SubscriptionRenewed::class);
    }

    public function test_an_exhausted_refetch_returns_503_so_pubsub_retries(): void
    {
        $this->app->instance(GooglePlayManager::class, new FakeGooglePlayManager(
            new FakeValidator(null),
        ));

        $this->postJson(self::ENDPOINT, Fixtures::subscriptionEnvelope(NotificationType::RENEWED->value))
            ->assertStatus(503);

        Event::assertNotDispatched(Events\SubscriptionRenewed::class);
    }
}
