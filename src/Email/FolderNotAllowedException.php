<?php

declare(strict_types=1);

namespace App\Email;

use RuntimeException;

/**
 * A folder operation was refused by the deployment's allowlist.
 *
 * A distinct type from a generic failure because the two mean different
 * things to a caller: a permission refusal is not a degraded result to be
 * retried or worked around, it is a boundary the deployment set. It carries
 * a sentence naming the operation, the folder and the env var that would
 * allow it — never a message body or a header value.
 */
final class FolderNotAllowedException extends RuntimeException
{
}
