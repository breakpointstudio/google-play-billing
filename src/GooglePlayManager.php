<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay;

use Breakpoint\GooglePlay\Exceptions\ValidationException;
use Google\Client;
use Google\Service\AndroidPublisher;
use Google\Service\AndroidPublisher\SubscriptionPurchasesAcknowledgeRequest;
use Psr\Log\LoggerInterface;

/**
 * Single owner of the AndroidPublisher client. Replaces the copy-pasted Google_Client
 * constructions and hardcoded package-name literals scattered across the app.
 */
class GooglePlayManager
{
    private ?AndroidPublisher $androidPublisher = null;

    /**
     * @param  array{credentials_path?: ?string, package_name?: ?string, application_name?: ?string}  $config
     */
    public function __construct(
        private array $config,
        private ?LoggerInterface $logger = null,
    ) {}

    public function packageName(): string
    {
        $packageName = $this->config['package_name'] ?? null;

        if (! is_string($packageName) || $packageName === '') {
            throw new ValidationException('google-play-billing.package_name is not configured.');
        }

        return $packageName;
    }

    public function androidPublisher(): AndroidPublisher
    {
        return $this->androidPublisher ??= new AndroidPublisher($this->client());
    }

    public function validator(): Validator
    {
        return new Validator($this->androidPublisher(), $this->packageName(), $this->logger);
    }

    /**
     * Google refunds and revokes an unacknowledged purchase after three days.
     */
    public function acknowledgeSubscription(string $productId, string $purchaseToken): void
    {
        $this->androidPublisher()->purchases_subscriptions->acknowledge(
            $this->packageName(),
            $productId,
            $purchaseToken,
            new SubscriptionPurchasesAcknowledgeRequest,
        );
    }

    protected function client(): Client
    {
        $credentials = $this->config['credentials_path'] ?? null;

        if (! is_string($credentials) || ! is_readable($credentials)) {
            throw new ValidationException("Google Play service account credentials missing or unreadable at [{$credentials}].");
        }

        $client = new Client;
        $client->setScopes([AndroidPublisher::ANDROIDPUBLISHER]);
        $client->setApplicationName($this->config['application_name'] ?? 'Slopes');
        $client->setAuthConfig($credentials);

        return $client;
    }
}
