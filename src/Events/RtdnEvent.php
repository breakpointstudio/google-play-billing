<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Events;

use Carbon\Carbon;

/**
 * Base for every Real-time Developer Notification. Carries the decoded notification so listeners
 * never re-parse the Pub/Sub envelope.
 */
abstract class RtdnEvent
{
    /**
     * @param  array<string, mixed>  $notification  the decoded message.data body
     */
    public function __construct(
        public readonly array $notification,
        public readonly string $packageName,
        public readonly ?string $messageId = null,
    ) {}

    public function eventTime(): ?Carbon
    {
        $millis = $this->notification['eventTimeMillis'] ?? null;

        return $millis === null ? null : Carbon::createFromTimestampUTC(intdiv((int) $millis, 1000));
    }
}
