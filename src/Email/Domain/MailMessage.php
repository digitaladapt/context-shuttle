<?php

declare(strict_types=1);

namespace App\Email\Domain;

/**
 * One message's envelope — what a *listing* returns.
 *
 * Deliberately carries no body: a listing must not be expensive and must not
 * be able to change mailbox state, so this is headers and `BODYSTRUCTURE`
 * only. The body lives on {@see MailMessage}, which is fetched for exactly
 * one message at a time.
 *
 * `date` is the `Date:` header normalized to an ISO 8601 instant, or null
 * when the message has no usable one (finding: a message with no `Date:` at
 * all is ordinary junk mail, not an error). The design's date filters are
 * *sent* dates — `SENTSINCE`/`SENTBEFORE` — for precisely this reason.
 *
 * `tags` are IMAP keywords, which is where the agent's own state lives:
 * `\Seen` is the human's flag and is reported separately as `seen`. Keeping
 * the two apart is the point — an agent marking messages read would be
 * impersonating the human, and would also destroy the unread signal other
 * clients depend on.
 */
final readonly class MailMessage
{
    /**
     * @param list<MailAddress>    $to
     * @param list<string>         $tags        IMAP keywords, excluding system flags
     * @param list<MailAttachment> $attachments
     */
    public function __construct(
        public int $uid,
        public string $folder,
        public ?string $subject,
        public ?MailAddress $from,
        public array $to,
        public ?string $date,
        public bool $seen,
        public bool $flagged,
        public bool $answered,
        public array $tags,
        public array $attachments,
        public ?string $messageId,
        public ?string $inReplyTo,
        public ?int $size,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'folder' => $this->folder,
            'subject' => $this->subject,
            'from' => $this->from?->toArray(),
            'to' => array_map(static fn (MailAddress $a): array => $a->toArray(), $this->to),
            'date' => $this->date,
            'seen' => $this->seen,
            'flagged' => $this->flagged,
            'answered' => $this->answered,
            'tags' => $this->tags,
            'message_id' => $this->messageId,
            'in_reply_to' => $this->inReplyTo,
            'size' => $this->size,
            'has_attachments' => [] !== $this->attachments,
            'attachments' => array_map(
                static fn (MailAttachment $a): array => $a->toArray(),
                $this->attachments,
            ),
        ];
    }
}
