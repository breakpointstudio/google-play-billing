<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Tests\Support;

use Breakpoint\GooglePlay\Responses\SubscriptionV2Response;
use Breakpoint\GooglePlay\Validator;
use Google\Service\Exception as GoogleServiceException;

class FakeValidator extends Validator
{
    public int $subscriptionCalls = 0;

    /** Records what the controller passed, so a test can prove the fetch is token-only. */
    public ?string $lastProductId = null;

    public ?string $lastPurchaseToken = null;

    /**
     * @param  int  $failuresBeforeSuccess  how many times to throw before returning the response
     */
    public function __construct(
        private ?SubscriptionV2Response $response,
        private int $failuresBeforeSuccess = 0,
    ) {}

    public function setProductId(string $productId): static
    {
        $this->lastProductId = $productId;

        return $this;
    }

    public function setPurchaseToken(string $purchaseToken): static
    {
        $this->lastPurchaseToken = $purchaseToken;

        return $this;
    }

    public function validateSubscriptionV2(): SubscriptionV2Response
    {
        $this->subscriptionCalls++;

        if ($this->subscriptionCalls <= $this->failuresBeforeSuccess || $this->response === null) {
            throw new GoogleServiceException('Service unavailable', 503);
        }

        return $this->response;
    }
}
