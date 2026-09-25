<?php

declare(strict_types=1);

namespace App\Calendar\Write;

/**
 * A stored object, resolved from an id, ready to be rewritten.
 *
 * Carries the `etag` because every write needs it and re-fetching to get it
 * would open a window between the read and the write — which is exactly the
 * race `If-Match` is there to close.
 */
final readonly class LocatedObject
{
    public function __construct(
        public string $href,
        public ?string $etag,
        public string $uid,
        public string $data,
    ) {
    }
}
