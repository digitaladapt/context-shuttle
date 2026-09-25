<?php

declare(strict_types=1);

namespace App\Calendar\CalDav;

use RuntimeException;

/**
 * A write was refused because the target href is not where it should be.
 *
 * The escalation this guards against is concrete: a percent-encoded `..` in
 * an href escapes the calendar collection, so a write tool would become a way
 * to write anywhere the account can reach — and the calendar the operator
 * designated as the *only* writable one would stop meaning anything
 * (CALENDAR-WRITES-FINDINGS §3).
 */
final class UnsafeHref extends RuntimeException
{
}
