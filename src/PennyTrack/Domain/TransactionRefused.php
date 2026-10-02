<?php

declare(strict_types=1);

namespace App\PennyTrack\Domain;

use RuntimeException;

/**
 * A transaction was refused on purpose, before any request was made.
 *
 * Distinct from a transport or server failure, and from a configuration
 * mistake: this is the tool declining to write, because writing would have
 * produced something the ledger should not contain — a second spelling of an
 * existing category, a merchant that does not exist, or a duplicate of a
 * transaction that is already logged.
 *
 * The message is the whole value. It has to be a sentence a caller can act on,
 * because the caller is usually a model that will read it and try again with a
 * better argument — "it didn't work" gives it nothing to correct.
 */
class TransactionRefused extends RuntimeException
{
}
