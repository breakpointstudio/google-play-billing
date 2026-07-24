<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Tests\Support;

use Breakpoint\GooglePlay\Responses\SubscriptionResponse;
use Breakpoint\GooglePlay\Validator;
use Google\Service\Exception as GoogleServiceException;

class FakeValidator extends Validator
{
    public int $subscriptionCalls = 0;

    /**
     * @param  int  $failuresBeforeSuccess  how many times to throw before returning the response
     */
    public function __construct(
        private ?SubscriptionResponse $response,
        private int $failuresBeforeSuccess = 0,
    ) {}

    public function setProductId(string $productId): static
    {
        return $this;
    }

    public function setPurchaseToken(string $purchaseToken): static
    {
        return $this;
    }

    public function validateSubscription(): SubscriptionResponse
    {
        $this->subscriptionCalls++;

        if ($this->subscriptionCalls <= $this->failuresBeforeSuccess || $this->response === null) {
            throw new GoogleServiceException('Service unavailable', 503);
        }

        return $this->response;
    }
}
