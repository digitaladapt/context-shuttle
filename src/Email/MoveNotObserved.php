<?php

declare(strict_types=1);

namespace App\Email;

use RuntimeException;

/**
 * A move was issued but the message could not be found in the destination.
 *
 * This is the distinction the design insists on: `to_folder` is where the
 * message was *observed*, not the configured value echoed back. A move that
 * cannot be observed is not a move we may report as done — the message may
 * be missing from both folders, or the destination search may have failed,
 * and either way saying "moved: true" would be a claim we cannot support.
 *
 * The likely cause is worth naming in the message, because the fix differs:
 * a message with no `Message-ID` cannot be located after a move at all
 * (ordinary for junk mail), while a server that renumbers UIDs without
 * reporting `COPYUID` leaves nothing to search on.
 */
final class MoveNotObserved extends RuntimeException
{
    public function __construct(
        public readonly string $fromFolder,
        public readonly string $toFolder,
        public readonly int $uid,
    ) {
        parent::__construct(\sprintf(
            'The message with uid %d was moved out of "%s", but could not be found in "%s" afterwards, '
            .'so the move is not being reported as complete. Check both folders with list_emails: the message '
            .'either has no Message-ID header to identify it by, or another client moved it on.',
            $uid,
            $fromFolder,
            $toFolder,
        ));
    }
}
