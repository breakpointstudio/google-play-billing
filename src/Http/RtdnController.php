<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Http;

use Breakpoint\GooglePlay\Enums\NotificationType;
use Breakpoint\GooglePlay\Enums\OneTimeProductNotificationType;
use Breakpoint\GooglePlay\Enums\ProductType;
use Breakpoint\GooglePlay\Enums\RefundType;
use Breakpoint\GooglePlay\Events;
use Breakpoint\GooglePlay\GooglePlayManager;
use Breakpoint\GooglePlay\Responses\SubscriptionResponse;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decodes the Pub/Sub push, re-fetches the purchase from Google, and dispatches one typed event.
 * Contract: unauthenticated → 401, malformed → 422, unmodelled → logged 200 (never 422 — that
 * burns Google's retries), re-fetch exhausted → 503 so Pub/Sub retries, listener throws → 500.
 */
class RtdnController
{
    public function __invoke(Request $request, GooglePlayManager $manager, PushAuthenticator $auth): JsonResponse
    {
        if ($auth->enabled() && ! $auth->verify($request)) {
            return response()->json([], 401);
        }

        $envelope = $request->json()->all();
        $data = data_get($envelope, 'message.data');

        if (! is_string($data) || ! isset($envelope['subscription'])) {
            Log::warning('Google RTDN envelope was not a Pub/Sub push.');

            return response()->json([], 422);
        }

        $notification = json_decode((string) base64_decode($data, true), true);
        if (! is_array($notification)) {
            Log::warning('Google RTDN message.data was not decodable JSON.');

            return response()->json([], 422);
        }

        $packageName = (string) ($notification['packageName'] ?? '');
        if ($packageName !== $manager->packageName()) {
            Log::warning('Google RTDN for another package.', ['packageName' => $packageName]);

            return response()->json([], 422);
        }

        $messageId = data_get($envelope, 'message.messageId') ?? data_get($envelope, 'message.message_id');
        $messageId = is_scalar($messageId) ? (string) $messageId : null;

        if ($messageId !== null && ! $this->claim($messageId)) {
            Log::info('Google RTDN redelivery ignored.', ['messageId' => $messageId]);

            return response()->json([], 200);
        }

        // A delivery that failed must stay retryable, so the claim is released on 5xx and on throw.
        try {
            $response = $this->dispatchFor($notification, $packageName, $messageId, $manager);
        } catch (Throwable $e) {
            $this->release($messageId);

            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            $this->release($messageId);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $notification
     */
    protected function dispatchFor(array $notification, string $packageName, ?string $messageId, GooglePlayManager $manager): JsonResponse
    {
        if (is_array($notification['subscriptionNotification'] ?? null)) {
            return $this->subscription($notification, $packageName, $messageId, $manager);
        }

        if (is_array($notification['oneTimeProductNotification'] ?? null)) {
            return $this->oneTime($notification, $packageName, $messageId);
        }

        if (is_array($notification['voidedPurchaseNotification'] ?? null)) {
            $voided = $notification['voidedPurchaseNotification'];
            Event::dispatch(new Events\VoidedPurchase(
                $notification,
                $packageName,
                (string) ($voided['purchaseToken'] ?? ''),
                isset($voided['orderId']) ? (string) $voided['orderId'] : null,
                ProductType::tryFrom((int) ($voided['productType'] ?? 0)),
                RefundType::tryFrom((int) ($voided['refundType'] ?? 0)),
                $messageId,
            ));

            return response()->json([], 200);
        }

        if (is_array($notification['testNotification'] ?? null)) {
            Event::dispatch(new Events\TestNotification($notification, $packageName, $messageId));

            return response()->json([], 200);
        }

        Log::warning('Google RTDN of an unmodelled shape.', ['keys' => array_keys($notification)]);
        Event::dispatch(new Events\UnknownNotification($notification, $packageName, null, $messageId));

        return response()->json([], 200);
    }

    /**
     * @param  array<string, mixed>  $notification
     */
    protected function subscription(array $notification, string $packageName, ?string $messageId, GooglePlayManager $manager): JsonResponse
    {
        $inner = $notification['subscriptionNotification'];
        $rawType = (int) ($inner['notificationType'] ?? 0);
        $type = NotificationType::tryFrom($rawType);
        $purchaseToken = (string) ($inner['purchaseToken'] ?? '');
        $subscriptionId = (string) ($inner['subscriptionId'] ?? '');

        if ($type === null) {
            Log::warning('Google RTDN subscription type not modelled.', ['notificationType' => $rawType]);
            Event::dispatch(new Events\UnknownNotification($notification, $packageName, $rawType, $messageId));

            return response()->json([], 200);
        }

        $subscription = $this->fetchSubscription($manager, $subscriptionId, $purchaseToken);
        if ($subscription === null) {
            return response()->json([], 503);
        }

        $event = self::SUBSCRIPTION_EVENTS[$type->value];
        Event::dispatch(new $event($notification, $packageName, $purchaseToken, $subscriptionId, $subscription, $type, $messageId));

        return response()->json([], 200);
    }

    /**
     * @param  array<string, mixed>  $notification
     */
    protected function oneTime(array $notification, string $packageName, ?string $messageId): JsonResponse
    {
        $inner = $notification['oneTimeProductNotification'];
        $rawType = (int) ($inner['notificationType'] ?? 0);
        $type = OneTimeProductNotificationType::tryFrom($rawType);

        if ($type === null) {
            Log::warning('Google RTDN one-time type not modelled.', ['notificationType' => $rawType]);
            Event::dispatch(new Events\UnknownNotification($notification, $packageName, $rawType, $messageId));

            return response()->json([], 200);
        }

        $event = $type === OneTimeProductNotificationType::PURCHASED
            ? Events\OneTimeProductPurchased::class
            : Events\OneTimeProductCanceled::class;

        Event::dispatch(new $event(
            $notification,
            $packageName,
            (string) ($inner['purchaseToken'] ?? ''),
            (string) ($inner['sku'] ?? ''),
            $type,
            $messageId,
        ));

        return response()->json([], 200);
    }

    /**
     * Google is routinely 503 for a few seconds after sending a notification.
     */
    protected function fetchSubscription(GooglePlayManager $manager, string $subscriptionId, string $purchaseToken): ?SubscriptionResponse
    {
        $attempts = (int) config('google-play-billing.rtdn.retries', 5);
        $delay = (int) config('google-play-billing.rtdn.retry_delay', 3);

        for ($attempt = 1; $attempt <= max(1, $attempts); $attempt++) {
            try {
                return $manager->validator()
                    ->setProductId($subscriptionId)
                    ->setPurchaseToken($purchaseToken)
                    ->validateSubscription();
            } catch (GoogleServiceException $e) {
                Log::warning('Google RTDN re-fetch failed.', ['attempt' => $attempt, 'error' => $e->getMessage()]);
                if ($attempt < $attempts && $delay > 0) {
                    sleep($delay);
                }
            }
        }

        Log::error('Google RTDN re-fetch exhausted retries.', ['subscriptionId' => $subscriptionId]);

        return null;
    }

    /**
     * @return bool true when this message has not been seen before
     */
    protected function claim(string $messageId): bool
    {
        return Cache::add($this->dedupeKey($messageId), true, (int) config('google-play-billing.rtdn.dedupe_ttl', 3600));
    }

    protected function release(?string $messageId): void
    {
        if ($messageId !== null) {
            Cache::forget($this->dedupeKey($messageId));
        }
    }

    protected function dedupeKey(string $messageId): string
    {
        return 'google_rtdn:'.$messageId;
    }

    private const SUBSCRIPTION_EVENTS = [
        1 => Events\SubscriptionRecovered::class,
        2 => Events\SubscriptionRenewed::class,
        3 => Events\SubscriptionCanceled::class,
        4 => Events\SubscriptionPurchased::class,
        5 => Events\SubscriptionOnHold::class,
        6 => Events\SubscriptionInGracePeriod::class,
        7 => Events\SubscriptionRestarted::class,
        8 => Events\SubscriptionPriceChangeConfirmed::class,
        9 => Events\SubscriptionDeferred::class,
        10 => Events\SubscriptionPaused::class,
        11 => Events\SubscriptionPauseScheduleChanged::class,
        12 => Events\SubscriptionRevoked::class,
        13 => Events\SubscriptionExpired::class,
        17 => Events\SubscriptionItemsChanged::class,
        18 => Events\SubscriptionCancellationScheduled::class,
        19 => Events\SubscriptionPriceChangeUpdated::class,
        20 => Events\SubscriptionPendingPurchaseCanceled::class,
        22 => Events\SubscriptionPriceStepUpConsentUpdated::class,
    ];
}
