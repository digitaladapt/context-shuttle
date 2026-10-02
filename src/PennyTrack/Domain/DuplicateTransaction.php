<?php

declare(strict_types=1);

namespace App\PennyTrack\Domain;

/**
 * A transaction was refused because the ledger already holds one like it.
 *
 * This is the *tool's* duplicate rule, which is deliberately much wider than
 * penny-track's own: penny-track only refuses an identical amount, business and
 * category logged within five minutes of the receipt's own timestamp, which
 * catches a double-submitted form and nothing else. Re-processing the same
 * receipt email tomorrow — or twice in one session by two different routes —
 * would sail straight past it, and the ledger would quietly grow a second copy
 * of a real expense.
 *
 * So the rule here is the one the caller can reason about: **one transaction
 * per amount, per business, per category, per day.** That is also why the
 * message names the existing receipt — the useful next step is almost always to
 * go and look at it, because the likeliest explanation is that the transaction
 * was already logged and the email is being read twice.
 *
 * Carries the facts as well as the sentence, so a caller (or a test) can act on
 * the existing receipt rather than parse the message.
 */
final class DuplicateTransaction extends TransactionRefused
{
    public function __construct(
        public readonly string $date,
        public readonly string $amount,
        public readonly string $business,
        public readonly string $category,
        public readonly ?int $existingId,
        public readonly ?string $existingCreatedAt,
    ) {
        parent::__construct(self::describe($date, $amount, $business, $category, $existingId, $existingCreatedAt));
    }

    private static function describe(string $date, string $amount, string $business, string $category, ?int $existingId, ?string $existingCreatedAt): string
    {
        $where = null === $existingId
            ? 'It is already logged'
            : \sprintf('Receipt #%d already covers it', $existingId);

        $when = null === $existingCreatedAt ? '' : \sprintf(' (logged %s)', $existingCreatedAt);

        return \sprintf(
            'Not created: penny-track already has a %s transaction at %s in %s on %s. %s%s. Look at it before logging another — the usual cause is the same receipt being read twice — and if it genuinely is a second, separate purchase, log it by hand in penny-track, where the two can be told apart.',
            $amount,
            $business,
            $category,
            $date,
            $where,
            $when,
        );
    }
}
