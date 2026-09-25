<?php

declare(strict_types=1);

namespace App\Tool\Email;

use App\Email\Domain\MailMessage;
use App\Email\EmailReader;
use App\Email\Imap\ImapConnectionFactory;
use App\Email\Imap\MessageFilter;
use InvalidArgumentException;
use RuntimeException;

/**
 * `list_emails` — what's in this folder?
 *
 * Metadata only, never a body: a listing must not be expensive and must not
 * be able to change mailbox state. Bodies live on `read_email`, which is a
 * separate call with a separate cost.
 *
 * The tool is a thin adapter between the tool contract (strings and integers
 * in, a shaped array out) and the reader, which speaks in filters and DTOs.
 * Validation lives here because it is contract-shaped — date formats, limit
 * bounds, offset sanity — while everything about *permissions* lives in the
 * folder gate, so there is exactly one place to look for "why was this
 * refused".
 */
final readonly class ListEmailsTool
{
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    public function __construct(
        private EmailReader $reader,
        private ImapConnectionFactory $connection,
    ) {
    }

    /**
     * List message envelopes in one folder, newest first.
     *
     * @param string      $folder      folder to list; must be one this deployment may read
     * @param bool|null   $unread_only only messages with no \Seen flag
     * @param string|null $tagged      only messages carrying this tag (IMAP keyword)
     * @param string|null $untagged    only messages *not* carrying this tag
     * @param string|null $sent_since  `YYYY-MM-DD`; matches the message's Date: header
     * @param string|null $sent_before `YYYY-MM-DD`; matches the message's Date: header
     * @param string|null $from        case-insensitive substring of the sender
     * @param string|null $subject     case-insensitive substring of the subject
     * @param int|null    $limit       1-100, default 25
     * @param int|null    $offset      0-based; default 0
     *
     * @return array<string, mixed>
     */
    public function listEmails(
        string $folder,
        ?bool $unread_only = null,
        ?string $tagged = null,
        ?string $untagged = null,
        ?string $sent_since = null,
        ?string $sent_before = null,
        ?string $from = null,
        ?string $subject = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        if (!$this->connection->isConfigured()) {
            throw new RuntimeException('Email is not configured. Set IMAP_HOST, IMAP_USERNAME and IMAP_PASSWORD in .env.local (IMAP_PASSWORD may also live in the secrets vault: bin/console secrets:set IMAP_PASSWORD).');
        }

        $folder = trim($folder);
        if ('' === $folder) {
            throw new InvalidArgumentException('folder must not be empty.');
        }

        $limit ??= self::DEFAULT_LIMIT;
        $offset ??= 0;

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(\sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }

        if ($offset < 0) {
            throw new InvalidArgumentException('offset must be 0 or greater.');
        }

        $filter = new MessageFilter(
            unreadOnly: true === $unread_only ? true : null,
            tagged: $this->optional($tagged),
            untagged: $this->optional($untagged),
            sentSince: $this->date($sent_since, 'sent_since'),
            sentBefore: $this->date($sent_before, 'sent_before'),
            from: $this->optional($from),
            subject: $this->optional($subject),
        );

        $result = $this->reader->listMessages($folder, $filter, $limit, $offset);

        $payload = [
            'emails' => array_map(
                static fn (MailMessage $message): array => $message->toArray(),
                $result['messages'],
            ),
            'count' => \count($result['messages']),
            'folder' => $folder,
            'total' => $result['total'],
            'unread' => $result['unread'],
            'uidvalidity' => $result['uidvalidity'],
            'offset' => $offset,
            'limit' => $limit,
        ];

        // `truncated` is loud on purpose: "25 of 300" read as "25" is how a
        // model reports an inbox as handled when it has seen none of it.
        $payload['truncated'] = $offset + \count($result['messages']) < $result['total'];

        if ($result['uidvalidity'] > 0) {
            $payload['note'] = 'Pass offset '.\sprintf('%d', $offset + $limit)
                .' for the next page. A changed uidvalidity means this mailbox was rebuilt '
                .'and any uid you stored earlier may now refer to a different message.';
        }

        return $payload;
    }

    /**
     * Strict `YYYY-MM-DD` validation.
     *
     * The pattern alone accepts `2026-02-30`, so the day is checked against
     * the month too — a lenient parse would roll it into March and silently
     * search the wrong range.
     */
    private function date(?string $value, string $name): ?string
    {
        $value = $this->optional($value);

        if (null === $value) {
            return null;
        }

        if (1 !== preg_match(self::DATE_PATTERN, $value)) {
            throw new InvalidArgumentException(\sprintf('%s must be in YYYY-MM-DD format (e.g. 2026-10-09).', $name));
        }

        [$year, $month, $day] = explode('-', $value);

        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            throw new InvalidArgumentException(\sprintf('%s must be a valid calendar date (2026-02-30 is not).', $name));
        }

        return $value;
    }

    private function optional(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return null === $value || '' === $value ? null : $value;
    }
}
