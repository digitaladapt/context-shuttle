<?php

declare(strict_types=1);

namespace App\Calendar\Write;

use RuntimeException;

/**
 * A write was refused on purpose, before or instead of a request.
 *
 * Distinct from a transport or server failure: this is the tools declining to
 * do something, and the message is the whole value — it has to be a sentence
 * a caller can act on, because "it didn't work" is not.
 */
final class WriteRefused extends RuntimeException
{
}
