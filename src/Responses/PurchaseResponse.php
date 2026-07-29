<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Responses;

use Breakpoint\GooglePlay\Enums\ConsumptionState;
use Breakpoint\GooglePlay\Enums\PurchaseState;
use Carbon\Carbon;
use Google\Service\AndroidPublisher\ProductPurchase;

/**
 * purchases.products — one-time products.
 */
class PurchaseResponse extends AbstractResponse
{
    public readonly ?ConsumptionState $consumptionState;

    /** Typed, unlike the v1 fork where a string leaked through and made strict comparisons dead. */
    public readonly ?PurchaseState $purchaseState;

    public readonly ?Carbon $purchasedAt;

    public readonly ?string $orderId;

    public readonly ?string $regionCode;

    /** Only set when the client passed it to `setObfuscatedAccountId()` at purchase time. */
    public readonly ?string $obfuscatedExternalAccountId;

    public readonly ?string $obfuscatedExternalProfileId;

    /** @var array<string, mixed>|string|null */
    public readonly array|string|null $developerPayload;

    public function __construct(ProductPurchase $raw)
    {
        parent::__construct($raw);

        $this->consumptionState = self::toEnumOrNull(ConsumptionState::class, $raw->getConsumptionState());
        $this->purchaseState = self::toEnumOrNull(PurchaseState::class, $raw->getPurchaseState());
        $this->purchasedAt = self::toDateFromMs($raw->getPurchaseTimeMillis());
        $this->orderId = self::toStringOrNull($raw->getOrderId());
        $this->regionCode = self::toStringOrNull(self::field($raw, 'regionCode'));
        $this->obfuscatedExternalAccountId = self::toStringOrNull(self::field($raw, 'obfuscatedExternalAccountId'));
        $this->obfuscatedExternalProfileId = self::toStringOrNull(self::field($raw, 'obfuscatedExternalProfileId'));
        $this->developerPayload = self::decodePayload($raw->getDeveloperPayload());
    }

    public function isCanceled(): bool
    {
        return $this->purchaseState === PurchaseState::CANCELED;
    }

    public function developerPayloadValue(string $key): ?string
    {
        return is_array($this->developerPayload) && isset($this->developerPayload[$key])
            ? (string) $this->developerPayload[$key]
            : null;
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private static function decodePayload(mixed $payload): array|string|null
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $decoded = json_decode((string) $payload, true);

        return is_array($decoded) ? $decoded : (string) $payload;
    }
}
