<?php

declare(strict_types=1);

namespace App\Alerts;

/**
 * Mints interactive-request ids.
 *
 * 128 random bits, URL-safe: the id is the capability (there is no separate
 * auth on the form or the status endpoint in v1), so its entropy is the
 * whole guard. 16 bytes of `random_bytes` encode as 22 characters of
 * unpadded base64url — short enough to live in a URL, wide enough that
 * guessing is not a thing.
 */
final class PendingRequestId
{
    /** Length, in characters, of every id this class produces. */
    public const LENGTH = 22;

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
