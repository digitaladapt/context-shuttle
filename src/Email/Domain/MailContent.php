<?php

declare(strict_types=1);

namespace App\Email\Domain;

/**
 * One message's *content*, for a single message at a time.
 *
 * Kept separate from {@see MailMessage} because the two have different costs
 * and different permissions: a listing is headers-only and always allowed
 * for a readable folder, while content is a body fetch that the deployment
 * bounds by size (finding 14: a 3.4 MB message returned 2.3 MB of text).
 *
 * `contentType` states which part the text came from. Finding 12: an
 * HTML-only message — a large share of real mail, and of newsletters in
 * particular — has **no** `text/plain` part at all, and the library's
 * `text()` returns `NULL` for it. Falling back to the HTML part is what
 * makes those messages readable; saying so in the output is what stops a
 * caller assuming it is reading plain prose.
 *
 * `truncated` is set when the body was cut to fit the configured cap. It is
 * never silent: a model shown the first 100 000 characters of a 2 MB
 * message must know it did not see the whole thing, and `size` is the
 * original so it can say by how much.
 *
 * @phpstan-type AddressList list<\App\Email\Domain\MailAddress>
 * @phpstan-type AttachmentList list<\App\Email\Domain\MailAttachment>
 */
final readonly class MailContent
{
    public const TYPE_TEXT = 'text/plain';

    public const TYPE_HTML = 'text/html';

    /**
     * @param AddressList                $to
     * @param AddressList                $cc
     * @param list<string>               $references
     * @param list<string>               $tags
     * @param AttachmentList             $attachments
     * @param array<string, string>|null $headers
     */
    public function __construct(
        public int $uid,
        public string $folder,
        public ?string $subject,
        public ?MailAddress $from,
        public array $to,
        public array $cc,
        public ?MailAddress $replyTo,
        public ?string $date,
        public ?string $messageId,
        public ?string $inReplyTo,
        public array $references,
        public bool $seen,
        public bool $flagged,
        public array $tags,
        public ?string $text,
        public string $contentType,
        public bool $truncated,
        public ?int $size,
        public array $attachments,
        public ?array $headers = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'uid' => $this->uid,
            'folder' => $this->folder,
            'subject' => $this->subject,
            'from' => $this->from?->toArray(),
            'to' => array_map(static fn (MailAddress $a): array => $a->toArray(), $this->to),
            'cc' => array_map(static fn (MailAddress $a): array => $a->toArray(), $this->cc),
            'reply_to' => $this->replyTo?->toArray(),
            'date' => $this->date,
            'message_id' => $this->messageId,
            'in_reply_to' => $this->inReplyTo,
            'references' => $this->references,
            'seen' => $this->seen,
            'flagged' => $this->flagged,
            'tags' => $this->tags,
            'text' => $this->text,
            'content_type' => $this->contentType,
            'truncated' => $this->truncated,
            'size' => $this->size,
            'attachments' => array_map(
                static fn (MailAttachment $a): array => $a->toArray(),
                $this->attachments,
            ),
        ];

        if (null !== $this->headers) {
            $payload['headers'] = $this->headers;
        }

        return $payload;
    }
}
