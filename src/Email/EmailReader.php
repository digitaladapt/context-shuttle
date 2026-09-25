<?php

declare(strict_types=1);

namespace App\Email;

use App\Email\Domain\FolderAccess;
use App\Email\Domain\MailAddress;
use App\Email\Domain\MailAttachment;
use App\Email\Domain\MailContent;
use App\Email\Domain\MailFolder;
use App\Email\Domain\MailMessage;
use App\Email\Imap\ImapClient;
use App\Email\Imap\MessageFilter;
use Psr\Log\LoggerInterface;

/**
 * Assembles folder listings and message reads, above the IMAP client.
 *
 * Sits where `CalendarReader` sits for calendars: it owns the parts that must
 * be identical for every caller — ordering, the folder gate, DTO shaping, and
 * the "one bad message must not fail a listing" rule — so the tools stay thin
 * adapters over the tool contract.
 *
 * Two rules are implemented here rather than in the client, because they are
 * policy rather than protocol:
 *
 * 1. **Every folder goes through the gate.** The client will happily fetch
 *    from any folder it can name; `FolderGate` decides whether this
 *    deployment permits it, and a refusal is an error rather than an empty
 *    result.
 * 2. **Ordering is ours.** The server returns messages in its own order
 *    (finding 16: ascending, even for a descending fetch window), so rows
 *    are sorted here — by `Date:` descending, then UID descending — for the
 *    same reason the calendar tools pin ordering: a listing that reorders
 *    between calls describes a different mailbox.
 *
 * Nothing is cached: every call fetches.
 */
final readonly class EmailReader
{
    public function __construct(
        private ImapClient $client,
        private FolderGate $gate,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Folders this deployment can do something with.
     *
     * Folders the gate grants nothing on are omitted (see
     * {@see FolderGate::apply()}), and configured folders that match nothing
     * on the server are reported rather than silently ignored — a typo in
     * `IMAP_TRASH_FOLDER` would otherwise look exactly like a mail server
     * with no trash.
     *
     * @return array{folders: list<MailFolder>, unmatched: list<string>}
     */
    public function listFolders(): array
    {
        $discovered = $this->client->listFolders();

        $folders = [];

        foreach ($discovered as $row) {
            $folders[] = new MailFolder(
                path: $row['path'],
                name: $row['name'],
                access: FolderAccess::none(),
                messageCount: $row['messages'],
                unreadCount: $row['unread'],
            );
        }

        $unmatched = $this->gate->unmatched(array_map(
            static fn (array $row): string => $row['path'],
            $discovered,
        ));

        if ([] !== $unmatched) {
            $this->logger?->warning('Email folder configuration names folders that do not exist on the server.', [
                'folders' => $unmatched,
            ]);
        }

        return [
            'folders' => $this->gate->apply($folders),
            'unmatched' => $unmatched,
        ];
    }

    /**
     * One page of message envelopes from a permitted folder.
     *
     * `offset` selects the page. Paging is intentionally offset-based rather
     * than cursor-based: a mailbox can be changed by another client between
     * calls, and unlike the calendar's half-open time window there is no
     * stable anchor to key a cursor on, so a cursor would only pretend to be
     * more correct. `uidvalidity` is returned so a paging caller can at least
     * notice the mailbox was replaced underneath it.
     *
     * @return array{messages: list<MailMessage>, total: int, unread: int, uidvalidity: int}
     */
    public function listMessages(
        string $folder,
        MessageFilter $filter,
        int $limit,
        int $offset,
    ): array {
        $this->gate->assertAllowed(FolderGate::OPERATION_READ, $folder);

        $status = $this->client->folderStatus($folder);

        $rows = $this->client->listMessages($folder, $filter, $limit, $offset);

        $messages = [];

        foreach ($rows as $row) {
            $messages[] = $this->toMessage($row);
        }

        return [
            'messages' => $this->order($messages),
            'total' => $status['messages'],
            'unread' => $status['unread'],
            'uidvalidity' => $status['uidvalidity'],
        ];
    }

    /**
     * One message's content, from a permitted folder.
     *
     * Returns null when the UID is not in the folder, so the tool layer can
     * phrase "no such message" without the client having to know how to.
     *
     * @param int $maxBytes bound the body *on the wire*; 0 disables it
     */
    public function readMessage(string $folder, int $uid, bool $includeHeaders, int $maxBytes): ?MailContent
    {
        $this->gate->assertAllowed(FolderGate::OPERATION_READ, $folder);

        $row = $this->client->readMessage($folder, $uid, $includeHeaders, $maxBytes);

        if (null === $row) {
            return null;
        }

        return new MailContent(
            uid: $row['uid'],
            folder: $folder,
            subject: $row['subject'],
            from: null === $row['from'] ? null : MailAddress::parse($row['from']),
            to: $this->addresses($row['to']),
            cc: $this->addresses($row['cc']),
            replyTo: null === $row['reply_to'] ? null : MailAddress::parse($row['reply_to']),
            date: $row['date'],
            messageId: $row['message_id'],
            inReplyTo: $row['in_reply_to'],
            references: $row['references'],
            seen: $row['seen'],
            flagged: $row['flagged'],
            tags: $row['tags'],
            text: $row['text'],
            contentType: $row['content_type'],
            truncated: $row['truncated'],
            size: $row['size'],
            attachments: $this->attachments($row['attachments']),
            headers: [] === $row['headers'] ? null : $row['headers'],
        );
    }

    /**
     * Senders/recipients as DTOs.
     *
     * @param list<string> $values
     *
     * @return list<MailAddress>
     */
    private function addresses(array $values): array
    {
        return array_map(
            static fn (string $value): MailAddress => MailAddress::parse($value),
            $values,
        );
    }

    /**
     * @param list<array{name: string, content_type: string, size: ?int}> $rows
     *
     * @return list<MailAttachment>
     */
    private function attachments(array $rows): array
    {
        return array_map(
            static fn (array $row): MailAttachment => new MailAttachment(
                name: $row['name'],
                contentType: $row['content_type'],
                size: $row['size'],
            ),
            $rows,
        );
    }

    /**
     * @param array{uid: int, subject: ?string, from: ?string, to: list<string>, date: ?string, seen: bool, flagged: bool, answered: bool, tags: list<string>, message_id: ?string, in_reply_to: ?string, size: ?int, attachments: list<array{name: string, content_type: string, size: ?int}>} $row
     */
    private function toMessage(array $row): MailMessage
    {
        return new MailMessage(
            uid: $row['uid'],
            folder: '',
            subject: $row['subject'],
            from: null === $row['from'] ? null : MailAddress::parse($row['from']),
            to: $this->addresses($row['to']),
            date: $row['date'],
            seen: $row['seen'],
            flagged: $row['flagged'],
            answered: $row['answered'],
            tags: $row['tags'],
            attachments: $this->attachments($row['attachments']),
            messageId: $row['message_id'],
            inReplyTo: $row['in_reply_to'],
            size: $row['size'],
        );
    }

    /**
     * Order by `Date:` descending, then UID descending.
     *
     * Both keys are needed: a message with no `Date:` (ordinary for junk)
     * must still land somewhere stable rather than wherever the sort happened
     * to leave it, and two messages can share a second. UID is per-mailbox
     * monotonic, so it is the natural tiebreak and it never repeats.
     *
     * @param list<MailMessage> $messages
     *
     * @return list<MailMessage>
     */
    private function order(array $messages): array
    {
        usort($messages, static function (MailMessage $a, MailMessage $b): int {
            $aDate = $a->date ?? '';
            $bDate = $b->date ?? '';

            // A missing date sorts last: an undated message is not "newest",
            // and sorting it first would put junk mail on top of every
            // listing.
            if ($aDate !== $bDate) {
                if ('' === $aDate) {
                    return 1;
                }
                if ('' === $bDate) {
                    return -1;
                }

                return strcmp($bDate, $aDate);
            }

            return $b->uid <=> $a->uid;
        });

        return $messages;
    }
}
