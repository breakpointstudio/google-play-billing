<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Support;

use Carbon\Carbon;

/**
 * Null-safe casts for AndroidPublisher fields, which arrive as strings, ints or absent depending
 * on the resource and the subscription's state.
 */
trait ValueCasting
{
    /**
     * apiclient-services is versioned independently of this package, so a field we promote can be
     * absent from the host app's copy. Prefer the getter, fall back to the property, then null.
     */
    protected static function field(object $raw, string $property): mixed
    {
        $getter = 'get'.ucfirst($property);

        if (method_exists($raw, $getter)) {
            return $raw->{$getter}();
        }

        return property_exists($raw, $property) ? $raw->{$property} : null;
    }

    protected static function toStringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    protected static function toIntOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    protected static function toBool(mixed $value): bool
    {
        return (bool) $value;
    }

    protected static function toDateFromMs(mixed $millis): ?Carbon
    {
        $millis = self::toIntOrNull($millis);

        return $millis === null ? null : Carbon::createFromTimestampUTC(intdiv($millis, 1000));
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    protected static function toEnumOrNull(string $enum, mixed $value): ?\BackedEnum
    {
        $value = self::toIntOrNull($value);

        return $value === null ? null : $enum::tryFrom($value);
    }
}
