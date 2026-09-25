<?php

declare(strict_types=1);

namespace App\Calendar\Write;

use RuntimeException;

/**
 * Someone else changed the object since it was read.
 *
 * The server's `412 Precondition Failed`, given its own type because it is
 * the one failure with a specific remedy: re-read, then decide again. It must
 * never be flattened into a generic error, or a caller that loses a race
 * learns nothing except that writing is unreliable.
 */
final class ConcurrentModification extends RuntimeException
{
}
