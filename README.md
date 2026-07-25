# breakpoint/google-play-billing

Google Play Billing validation, Laravel integration, and Real-time Developer Notifications, for
Slopes.

It exists because the upstream `aporat/store-receipt-validator` deleted its `GooglePlay` namespace,
and because the Google-side code in Slopes Server had accumulated copy-pasted `Google_Client`
constructions and hardcoded package-name literals. This package owns all of that: one
`AndroidPublisher` client, typed responses, typed enums, and one RTDN controller that turns a
Pub/Sub push into a single typed event.

## Requirements

- PHP 8.2+
- Laravel 12 or 13 (`illuminate/support ^12.0|^13.0`)
- A Google Play service account JSON with the AndroidPublisher scope

## Installation

Private package, installed straight from this repository:

```jsonc
// composer.json
"repositories": [
    { "type": "vcs", "url": "https://github.com/breakpointstudio/google-play-billing" }
],
"require": {
    "breakpoint/google-play-billing": "^1.0"
}
```

The service provider is auto-discovered and deferred — it registers bindings and merges config, and
nothing more, because the host app owns the RTDN route.

```bash
php artisan vendor:publish --tag=google-play-billing-config   # optional
```

## Configuration

| Key | Env | Default |
| --- | --- | --- |
| `credentials_path` | `GOOGLE_PLAY_CREDENTIALS_PATH` | `storage_path('app/google_play_service.json')` |
| `package_name` | `GOOGLE_PLAY_PACKAGE_NAME` | `com.consumedbycode.slopes` |
| `application_name` | `GOOGLE_PLAY_APPLICATION_NAME` | `Slopes` |
| `rtdn.dedupe_ttl` | `GOOGLE_PLAY_RTDN_DEDUPE_TTL` | `3600` |
| `rtdn.retries` | `GOOGLE_PLAY_RTDN_RETRIES` | `5` |
| `rtdn.retry_delay` | `GOOGLE_PLAY_RTDN_RETRY_DELAY` | `3` |
| `rtdn.push_auth.enabled` | `GOOGLE_PLAY_RTDN_PUSH_AUTH` | `false` |
| `rtdn.push_auth.audience` | `GOOGLE_PLAY_RTDN_PUSH_AUDIENCE` | `null` |
| `rtdn.push_auth.service_account_email` | `GOOGLE_PLAY_RTDN_PUSH_SERVICE_ACCOUNT` | `null` |

`retries`/`retry_delay` govern re-fetching the purchase from Google, which is routinely 503 for a
few seconds after a notification arrives.

## Validating a purchase

```php
use Breakpoint\GooglePlay\Validator;

$response = app(Validator::class)
    ->setProductId('year_sub')
    ->setPurchaseToken($token)
    ->validateSubscriptionV2();

$response->getExpiryTime();
$response->getSubscriptionState();   // typed enum
$response->getLinkedPurchaseToken();
```

Three entry points, all returning readonly response objects:

| Method | Endpoint | Response |
| --- | --- | --- |
| `validatePurchase()` | `purchases.products.get` | `PurchaseResponse` |
| `validateSubscription()` | `purchases.subscriptions.get` (v1) | `SubscriptionResponse` |
| `validateSubscriptionV2()` | `purchases.subscriptionsv2.get` | `SubscriptionV2Response` |

Prefer v2 for anything new: v1 cannot represent multi-line-item subscriptions, and Google is
steering everything toward v2.

Acknowledgement lives on the manager, since it is a write, not a read:

```php
app(\Breakpoint\GooglePlay\GooglePlayManager::class)->acknowledgeSubscription($productId, $token);
```

Unacknowledged purchases are auto-refunded and revoked by Google after three days, so this is not
optional.

## Real-time Developer Notifications

Point the Pub/Sub push subscription at a route of your choosing and hand it the invokable
controller:

```php
Route::post('webhooks/google/rtdn', \Breakpoint\GooglePlay\Http\RtdnController::class);
```

The controller decodes the Pub/Sub envelope, rejects notifications for another package, dedupes on
`messageId`, re-fetches the purchase from Google, and dispatches exactly one typed event from
`Breakpoint\GooglePlay\Events`.

Its status codes are a deliberate contract:

| Situation | Status | Why |
| --- | --- | --- |
| Push authentication on and the OIDC token missing or wrong | 401 | Not from our subscription |
| Malformed envelope, or another package | 422 | Not ours; retrying will never help |
| Notification type we do not model | 200 (logged) | A 422 here burns Google's retry budget on forward-compatibility |
| Redelivery of a `messageId` already handled | 200 | Pub/Sub delivers at least once |
| Re-fetch from Google exhausted its retries | 503 | Pub/Sub should try again later |
| A listener threw | 500 | Same — earn the retry rather than swallow the failure |

Listen for what you care about; every event carries the decoded notification plus the re-fetched
purchase:

```php
Event::listen(\Breakpoint\GooglePlay\Events\SubscriptionRenewed::class, RecordRenewal::class);
```

`UnknownNotification` is dispatched for anything unmodelled, so nothing is silently dropped.

### Push authentication

Pub/Sub can attach an OIDC token to every push when the subscription is created with a service
account. Verification of that token is **off by default**, because a subscription without one
sends no `Authorization` header at all and enabling the check early rejects every notification.
Configure the subscription first, then set `GOOGLE_PLAY_RTDN_PUSH_AUTH=true` along with the
audience the subscription was created with and the service account's email. The re-fetch from
Google stays the hard guarantee either way — the token only proves who is calling.

## Testing

```bash
composer install
vendor/bin/phpunit
```

Testbench-based; no Google credentials or network access required. `tests/Support/` ships
`FakeValidator`, `FakeGooglePlayManager` and `Fixtures` — host applications are welcome to use them
rather than hand-rolling Google payloads.

## Versioning

Semver, tagged on `main`. Consumers pin `^1.0`.
