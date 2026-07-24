<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Events;

/**
 * A notification shape or type this package does not model yet. Dispatched instead of failing, so
 * forward-compatible types never burn Google's retry budget.
 */
class UnknownNotification extends RtdnEvent
{
    /**
     * @param  array<string, mixed>  $notification
     */
    public function __construct(
        array $notification,
        string $packageName,
        public readonly ?int $rawType = null,
        ?string $messageId = null,
    ) {
        parent::__construct($notification, $packageName, $messageId);
    }
}
