<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay;

use Breakpoint\GooglePlay\Exceptions\ValidationException;
use Breakpoint\GooglePlay\Responses\PurchaseResponse;
use Breakpoint\GooglePlay\Responses\SubscriptionResponse;
use Breakpoint\GooglePlay\Responses\SubscriptionV2Response;
use Google\Service\AndroidPublisher;
use Google\Service\Exception as GoogleServiceException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Validator
{
    protected ?string $purchaseToken = null;

    protected ?string $productId = null;

    protected LoggerInterface $logger;

    public function __construct(
        protected AndroidPublisher $androidPublisher,
        protected string $packageName,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    public function setPurchaseToken(string $purchaseToken): static
    {
        $this->purchaseToken = $purchaseToken;

        return $this;
    }

    public function setProductId(string $productId): static
    {
        $this->productId = $productId;

        return $this;
    }

    public function setPackageName(string $packageName): static
    {
        $this->packageName = $packageName;

        return $this;
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    public function getPublisherService(): AndroidPublisher
    {
        return $this->androidPublisher;
    }

    /**
     * @throws ValidationException
     * @throws GoogleServiceException
     */
    public function validatePurchase(): PurchaseResponse
    {
        return new PurchaseResponse($this->androidPublisher->purchases_products->get(
            $this->packageName,
            $this->require($this->productId, 'productId'),
            $this->require($this->purchaseToken, 'purchaseToken'),
        ));
    }

    /**
     * @throws ValidationException
     * @throws GoogleServiceException
     */
    public function validateSubscription(): SubscriptionResponse
    {
        return new SubscriptionResponse($this->androidPublisher->purchases_subscriptions->get(
            $this->packageName,
            $this->require($this->productId, 'productId'),
            $this->require($this->purchaseToken, 'purchaseToken'),
        ));
    }

    /**
     * The v2 resource is keyed on the token alone — no product id.
     *
     * @throws ValidationException
     * @throws GoogleServiceException
     */
    public function validateSubscriptionV2(): SubscriptionV2Response
    {
        return new SubscriptionV2Response($this->androidPublisher->purchases_subscriptionsv2->get(
            $this->packageName,
            $this->require($this->purchaseToken, 'purchaseToken'),
        ));
    }

    private function require(?string $value, string $name): string
    {
        if ($value === null || $value === '') {
            $this->logger->warning('Google Play validation attempted without {field}.', ['field' => $name]);

            throw new ValidationException("A {$name} is required before validating.");
        }

        return $value;
    }
}
