<?php

declare(strict_types=1);

namespace App\Email\Imap;

use RuntimeException;

/**
 * No message with that UID in that folder.
 *
 * A distinct type because it is the one write failure that is *expected* in
 * normal use: a caller holding a UID from an earlier listing is racing every
 * other mail client on the account, and "it is gone" deserves to be phrased
 * differently from "the server refused" or "the network died".
 *
 * Carries the folder and UID so the message can name both, following the
 * repo's "a sentence naming the parameter" convention.
 */
final class MessageNotFound extends RuntimeException
{
    public function __construct(
        public readonly string $folder,
        public readonly int $uid,
        ?string $detail = null,
    ) {
        parent::__construct(\sprintf(
            'No message with uid %d in folder "%s"%s. UIDs are per-folder and change when a message moves, '
            .'so re-read the folder with list_emails if another mail client may have changed it.',
            $uid,
            $folder,
            null === $detail ? '' : ' ('.$detail.')',
        ));
    }
}
