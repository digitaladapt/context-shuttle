<?php

declare(strict_types=1);

namespace App\Messages;

use InvalidArgumentException;

/**
 * Thrown when a conversation name does not name one.
 */
final class ConversationNameException extends InvalidArgumentException
{
}
