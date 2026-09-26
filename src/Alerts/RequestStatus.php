<?php

declare(strict_types=1);

namespace App\Alerts;

/**
 * Lifecycle of an interactive request.
 *
 * `expired` is computed lazily on read (a `pending` request whose
 * `expiresAt` has passed reads as `expired`); it is never written to the
 * store except as part of that derivation, so the persisted state only
 * ever moves `pending` → `answered`.
 */
enum RequestStatus: string
{
    case Pending = 'pending';
    case Answered = 'answered';
    case Expired = 'expired';
}
