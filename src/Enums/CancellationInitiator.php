<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Enums;

/**
 * Who cancelled, from `canceledStateContext`. Replaces v1's `cancelReason` int.
 *
 * Three of the four contexts carry no fields at all, so the variant is identified by which key is
 * present — never by reading a value out of it.
 */
enum CancellationInitiator: string
{
    case USER = 'user';
    case SYSTEM = 'system';
    case DEVELOPER = 'developer';
    case REPLACEMENT = 'replacement';
}
