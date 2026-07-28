<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay;

use Breakpoint\GooglePlay\Exceptions\ValidationException;
use Google\Client;
use Google\Service\AndroidPublisher;
use Google\Service\AndroidPublisher\SubscriptionPurchasesAcknowledgeRequest;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

/**
 * Single owner of the AndroidPublisher client. Replaces the copy-pasted Google_Client
 * constructions and hardcoded package-name literals scattered across the app.
 */
class GooglePlayManager
{
    private ?AndroidPublisher $androidPublisher = null;

    /**
     * @param  array{credentials_path?: ?string, package_name?: ?string, application_name?: ?string, http?: array{connect_timeout?: int|float, timeout?: int|float}}  $config
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
        $client->setHttpClient($this->httpClient($client));

        return $client;
    }

    /**
     * Google's own default client sets no timeout at all, so one hung request holds the caller
     * indefinitely — past any Pub/Sub ack deadline, which defaults to 10s.
     */
    protected function httpClient(Client $client): GuzzleClient
    {
        $http = $this->config['http'] ?? [];

        return new GuzzleClient([
            // Carried over from Google's own default, never invented: its services resolve
            // against it, and `attachToHttp()` rebuilds this config when it adds auth middleware.
            'base_uri' => $client->getConfig('base_path'),
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::CONNECT_TIMEOUT => (float) ($http['connect_timeout'] ?? 3),
            RequestOptions::TIMEOUT => (float) ($http['timeout'] ?? 6),
        ]);
    }
}
