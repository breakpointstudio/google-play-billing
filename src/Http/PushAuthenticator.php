<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Http;

use Google\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies the OIDC token Pub/Sub attaches to an authenticated push subscription. Off by default:
 * a subscription created without a service account sends no Authorization header at all, so
 * turning this on before the subscription is reconfigured would reject every notification.
 */
class PushAuthenticator
{
    public function enabled(): bool
    {
        return (bool) config('google-play-billing.rtdn.push_auth.enabled', false);
    }

    /**
     * @return bool true when the caller proved it is our Pub/Sub subscription
     */
    public function verify(Request $request): bool
    {
        $token = $this->bearer($request);

        if ($token === null) {
            Log::warning('Google RTDN push carried no bearer token.');

            return false;
        }

        $payload = $this->decode($token);

        if ($payload === false) {
            Log::warning('Google RTDN push token failed verification.');

            return false;
        }

        $expected = config('google-play-billing.rtdn.push_auth.service_account_email');

        if ($expected !== null && ($payload['email'] ?? null) !== $expected) {
            Log::warning('Google RTDN push token is for another service account.', ['email' => $payload['email'] ?? null]);

            return false;
        }

        return ($payload['email_verified'] ?? false) == true;
    }

    /**
     * @return array<string, mixed>|false
     */
    protected function decode(string $token): array|false
    {
        try {
            return (new Client(['client_id' => config('google-play-billing.rtdn.push_auth.audience')]))
                ->verifyIdToken($token);
        } catch (Throwable $e) {
            Log::warning('Google RTDN push token could not be decoded.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function bearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }
}
