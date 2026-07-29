<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Tests\Feature;

use Breakpoint\GooglePlay\Enums\NotificationType;
use Breakpoint\GooglePlay\Events;
use Breakpoint\GooglePlay\GooglePlayManager;
use Breakpoint\GooglePlay\Http\PushAuthenticator;
use Breakpoint\GooglePlay\Http\RtdnController;
use Breakpoint\GooglePlay\Responses\SubscriptionV2Response;
use Breakpoint\GooglePlay\Tests\Support\FakeGooglePlayManager;
use Breakpoint\GooglePlay\Tests\Support\FakeValidator;
use Breakpoint\GooglePlay\Tests\Support\Fixtures;
use Breakpoint\GooglePlay\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/**
 * The OIDC gate is off by default (§11 D7): an unauthenticated push subscription sends no
 * Authorization header, so switching it on before Pub/Sub is reconfigured rejects everything.
 */
class PushAuthenticationTest extends TestCase
{
    private const ENDPOINT = '/rtdn';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            GooglePlayManager::class,
            new FakeGooglePlayManager(new FakeValidator(new SubscriptionV2Response(Fixtures::subscriptionPurchaseV2()))),
        );

        Route::post(self::ENDPOINT, RtdnController::class);
        Event::fake();
    }

    private function notify(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::ENDPOINT, Fixtures::subscriptionEnvelope(NotificationType::RENEWED->value));
    }

    public function test_pushes_are_unauthenticated_by_default(): void
    {
        $this->notify()->assertStatus(200);

        Event::assertDispatched(Events\SubscriptionRenewed::class);
    }

    public function test_an_unsigned_push_is_rejected_once_the_gate_is_on(): void
    {
        config(['google-play-billing.rtdn.push_auth.enabled' => true]);

        $this->notify()->assertStatus(401);

        Event::assertNotDispatched(Events\SubscriptionRenewed::class);
    }

    public function test_a_token_for_another_service_account_is_rejected(): void
    {
        config([
            'google-play-billing.rtdn.push_auth.enabled' => true,
            'google-play-billing.rtdn.push_auth.service_account_email' => 'rtdn@slopes.iam.gserviceaccount.com',
        ]);
        $this->fakeVerifiedToken(['email' => 'someoneelse@evil.iam.gserviceaccount.com', 'email_verified' => true]);

        $this->withHeaders(['Authorization' => 'Bearer whatever'])->notify()->assertStatus(401);
    }

    public function test_a_token_from_the_expected_service_account_is_accepted(): void
    {
        config([
            'google-play-billing.rtdn.push_auth.enabled' => true,
            'google-play-billing.rtdn.push_auth.service_account_email' => 'rtdn@slopes.iam.gserviceaccount.com',
        ]);
        $this->fakeVerifiedToken(['email' => 'rtdn@slopes.iam.gserviceaccount.com', 'email_verified' => true]);

        $this->withHeaders(['Authorization' => 'Bearer whatever'])->notify()->assertStatus(200);

        Event::assertDispatched(Events\SubscriptionRenewed::class);
    }

    public function test_an_unverified_email_claim_is_rejected(): void
    {
        config(['google-play-billing.rtdn.push_auth.enabled' => true]);
        $this->fakeVerifiedToken(['email' => 'rtdn@slopes.iam.gserviceaccount.com', 'email_verified' => false]);

        $this->withHeaders(['Authorization' => 'Bearer whatever'])->notify()->assertStatus(401);
    }

    /**
     * Signature checking belongs to Google's client; this stands in for it so the claim rules
     * above are testable without minting a real Google-signed JWT.
     *
     * @param  array<string, mixed>|false  $payload
     */
    private function fakeVerifiedToken(array|false $payload): void
    {
        $this->app->instance(PushAuthenticator::class, new class($payload) extends PushAuthenticator
        {
            /**
             * @param  array<string, mixed>|false  $payload
             */
            public function __construct(private readonly array|false $payload) {}

            protected function decode(string $token): array|false
            {
                return $this->payload;
            }
        });
    }
}
