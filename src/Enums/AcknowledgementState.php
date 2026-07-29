<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Enums;

enum AcknowledgementState: int
{
    case YET_TO_BE_ACKNOWLEDGED = 0;
    case ACKNOWLEDGED = 1;

    /**
     * v1 sends an int, subscriptionsv2 sends `ACKNOWLEDGEMENT_STATE_*`. A plain int cast turns every
     * v2 string into 0, which reads as unacknowledged and re-acknowledges on every notification.
     */
    public static function fromApiValue(mixed $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return self::tryFrom((int) $value);
        }

        return match ((string) $value) {
            'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED' => self::ACKNOWLEDGED,
            'ACKNOWLEDGEMENT_STATE_PENDING' => self::YET_TO_BE_ACKNOWLEDGED,
            default => null,
        };
    }
}
