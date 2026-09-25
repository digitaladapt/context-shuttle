<?php

declare(strict_types=1);

namespace App\Email\Imap;

use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Tokens\Literal;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Enums\ImapSearchKey;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Message;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The one class that speaks IMAP.
 *
 * Everything above this talks in {@see \App\Email\Domain} DTOs, which is
 * what makes it possible to change the library (or add a second source)
 * without touching a tool. The four defects the design recorded all land
 * here, contained:
 *
 * 1. **Non-ASCII search** (finding 3) — every free-text value goes out as
 *    `RawQueryValue` via {@see SearchValue}, because the library's own
 *    conversion to modified UTF-7 makes the server match nothing, silently.
 * 2. **Header truncation** (finding 10) — `getValue()` dropped the
 *    SPF/DKIM/DMARC verdicts from `Authentication-Results`; headers are read
 *    with `getRawValue()`.
 * 3. **`$_GET` leakage into `paginate()`** (finding 15) — `paginate()` is
 *    never called; paging is `limit()` plus explicit ordering.
 * 4. **`LengthAwarePaginator` is not iterable** (finding 17) — iteration is
 *    over `->items()`.
 *
 * Two more behaviours are load-bearing rather than defensive:
 *
 * - **Nothing here marks a message read.** The library's `fetchAsUnread`
 *   defaults to `true`, so headers and bodies go out as `BODY.PEEK[…]`; the
 *   read/write split depends on that staying true, so `markAsRead()` is
 *   never called anywhere in this codebase.
 * - **Nothing here expunges.** `expunge()` is mailbox-wide and would destroy
 *   messages other clients flagged; the tool surface has no delete, and this
 *   class offers no way to reach one.
 *
 * Folder identity is normalized through {@see self::decode()} on the way
 * out, so `path` is always the human-readable name and never the server's
 * modified UTF-7 (finding 19).
 */
final readonly class ImapClient
{
    public function __construct(
        private ImapConnectionFactory $connection,
    ) {
    }

    /**
     * Every folder, with the server's message and unread counts.
     *
     * `status()` is used rather than selecting each folder: it is one cheap
     * request per folder and, unlike `SELECT`, it does not disturb the
     * mailbox's selected state (verified — `status()` reports `UIDVALIDITY`
     * and counts without selecting).
     *
     * @return list<array{path: string, name: string, messages: int, unread: int}>
     */
    public function listFolders(): array
    {
        return $this->connection->with(function (Mailbox $mailbox): array {
            $folders = [];

            foreach ($mailbox->folders()->get() as $folder) {
                $folders[] = $this->describeFolder($folder);
            }

            return $folders;
        });
    }

    /**
     * A folder's `UIDVALIDITY`, so a caller holding a UID from an earlier
     * call can tell whether it still means anything.
     *
     * A mailbox replaced on the server (a re-import, a rebuild) comes back
     * with a new `UIDVALIDITY` and UIDs that refer to different messages.
     * Returning it costs one `STATUS` and turns a silent wrong-message
     * reference into something visible.
     *
     * @return array{messages: int, unread: int, uidvalidity: int}
     */
    public function folderStatus(string $path): array
    {
        return $this->connection->with(function (Mailbox $mailbox) use ($path): array {
            $folder = $this->findFolder($mailbox, $path);

            $status = $folder->status();

            return [
                'messages' => (int) ($status['MESSAGES'] ?? 0),
                'unread' => (int) ($status['UNSEEN'] ?? 0),
                'uidvalidity' => (int) ($status['UIDVALIDITY'] ?? 0),
            ];
        });
    }

    /**
     * Message envelopes, newest first, with no body fetched.
     *
     * `offset` selects the page: the server's fetch window is `limit` wide,
     * so a 50-message page of a 20 000-message mailbox does not transfer
     * 20 000 headers. When `offset` is not a whole multiple of `limit` (a
     * caller resuming mid-page), the window is widened to cover the partial
     * first page so the caller's offset is still honoured exactly.
     *
     * Ordering is **not** trusted to the server: the returned collection
     * comes back in whatever order the server emitted (finding 16 —
     * ascending, even for a descending fetch window), so the reader
     * re-orders explicitly.
     *
     * @return list<array{uid: int, subject: ?string, from: ?string, to: list<string>, date: ?string, seen: bool, flagged: bool, answered: bool, tags: list<string>, message_id: ?string, in_reply_to: ?string, size: ?int, attachments: list<array{name: string, content_type: string, size: ?int}>}>
     */
    public function listMessages(string $path, MessageFilter $filter, int $limit, int $offset): array
    {
        return $this->connection->with(function (Mailbox $mailbox) use ($path, $filter, $limit, $offset): array {
            $folder = $this->findFolder($mailbox, $path);

            $limit = max(1, $limit);
            $offset = max(0, $offset);

            $page = intdiv($offset, $limit) + 1;
            $skew = $offset % $limit;

            $query = $folder->messages()
                ->withHeaders()
                ->withFlags()
                ->withSize()
                ->withBodyStructure()
                ->setFetchOrderDesc()
                ->limit($limit + $skew, $page);

            $filter->apply($query);

            $rows = [];

            foreach ($query->get() as $message) {
                $rows[] = $this->envelope($message, $folder);
            }

            // The window may start up to `limit - 1` messages before the
            // caller's offset; drop the skew here so the caller sees exactly
            // the page it asked for.
            if ($skew > 0) {
                $rows = \array_slice($rows, $skew, $limit);
            }

            return $rows;
        });
    }

    /**
     * One message's content, by UID.
     *
     * Returns null rather than throwing when the UID is not in the folder:
     * "no such message" is an answer, and the tool layer phrases it.
     *
     * The body is fetched with `BODY.PEEK` (the library's default), so
     * reading a message does **not** mark it read. `$maxBytes` bounds the
     * fetch on the wire, not just the output — see the note in the body.
     *
     * @return array{uid: int, subject: ?string, from: ?string, to: list<string>, cc: list<string>, reply_to: ?string, date: ?string, message_id: ?string, in_reply_to: ?string, references: list<string>, seen: bool, flagged: bool, tags: list<string>, text: ?string, content_type: string, truncated: bool, size: ?int, attachments: list<array{name: string, content_type: string, size: ?int}>, headers: array<string, string>}|null
     */
    public function readMessage(string $path, int $uid, bool $includeHeaders, int $maxBytes): ?array
    {
        return $this->connection->with(function (Mailbox $mailbox) use ($path, $uid, $includeHeaders, $maxBytes): ?array {
            $folder = $this->findFolder($mailbox, $path);

            $message = $folder->messages()
                ->withHeaders()
                ->withFlags()
                ->withSize()
                ->withBodyStructure()
                ->find($uid);

            if (null === $message) {
                return null;
            }

            $size = $message->size();

            [$text, $contentType, $truncated] = $this->fetchBody($mailbox->connection(), $message, $maxBytes);

            return [
                'uid' => $message->uid(),
                'subject' => $this->nullIfEmpty($message->subject()),
                'from' => $this->firstAddress($message->from()),
                'to' => $this->addressList($message->to()),
                'cc' => $this->addressList($message->cc()),
                'reply_to' => $this->firstAddress($message->replyTo()),
                'date' => $this->dateOf($message),
                'message_id' => $this->nullIfEmpty($message->messageId()),
                'in_reply_to' => $this->firstOrNull($message->inReplyTo()),
                'references' => $this->headerReferences($message),
                'seen' => $message->isSeen(),
                'flagged' => $message->isFlagged(),
                'tags' => $this->keywords($message),
                'text' => $text,
                'content_type' => $contentType,
                'truncated' => $truncated,
                'size' => $size,
                'attachments' => $this->attachments($message),
                'headers' => $includeHeaders ? $this->spamHeaders($message) : [],
            ];
        });
    }

    /**
     * Fetch the message's readable body, bounded on the wire.
     *
     * **The bound is a partial fetch, not a post-hoc truncation.**
     * `BODY.PEEK[1]<0.100000>` asks the *server* for the first 100 000
     * bytes of the text part, so the bytes never cross the network — verified
     * against Dovecot: a 2.3 MB part came back as exactly 1000 bytes for
     * `<0.1000>`, in 0.001 s. The design's finding 14 measured a 3.4 MB
     * message returning 2.3 MB of text, and downloading that in order to
     * discard 95% of it is the cost this avoids.
     *
     * Finding 12 lives here too: an HTML-only message — newsletters, and a
     * large share of real mail — has no `text/plain` part, and `text()`
     * returns NULL for it. Falling back to the HTML part is what makes those
     * messages readable at all, and `content_type` records which part the
     * caller is holding so it does not mistake markup for prose.
     *
     * `$maxBytes <= 0` disables the bound (fetch the whole part).
     *
     * @return array{0: ?string, 1: string, 2: bool}
     */
    private function fetchBody(
        ConnectionInterface $connection,
        MessageInterface $message,
        int $maxBytes,
    ): array {
        $structure = $message->bodyStructure();

        $part = $structure?->text();
        $contentType = 'text/plain';

        if (null === $part) {
            $part = $structure?->html();
            $contentType = 'text/html';
        }

        // No usable structure at all: a message with no `text/*` part, or a
        // server whose `BODYSTRUCTURE` we could not read. There is nothing
        // to fetch a byte range from, so this reports no body rather than
        // inventing one — the caller is told `text: null` and the size, which
        // is honest about not being able to show the content.
        if (null === $part) {
            return [null, $contentType, false];
        }

        $partNumber = $part->partNumber();
        $partSize = $part->size();

        $truncated = $maxBytes > 0 && null !== $partSize && $partSize > $maxBytes;

        $raw = $this->fetchPart($connection, $message, $partNumber, $maxBytes);

        if (null === $raw) {
            return [null, $contentType, false];
        }

        $decoded = $this->decodePart($message, $partNumber, $raw, $part);

        if (null === $decoded) {
            return [null, $contentType, false];
        }

        if ($maxBytes > 0 && \strlen($decoded) > $maxBytes) {
            $decoded = substr($decoded, 0, $maxBytes);
            $truncated = true;
        }

        return [$decoded, $contentType, $truncated];
    }

    /**
     * Pull (up to) `$limit` bytes of one body part, without setting `\Seen`.
     *
     * This reaches the connection directly because the library's public
     * `bodyPart()` has no partial-fetch syntax, and the partial fetch is the
     * whole point: it is the difference between transferring 2.3 MB and
     * transferring 100 KB. `BODY.PEEK` keeps the read/write split intact.
     */
    private function fetchPart(
        ConnectionInterface $connection,
        MessageInterface $message,
        string $partNumber,
        int $limit,
    ): ?string {
        $item = \sprintf('BODY.PEEK[%s]', $partNumber);

        if ($limit > 0) {
            $item = \sprintf('%s<0.%d>', $item, $limit);
        }

        try {
            $responses = $connection->fetch([$item], $message->uid());
        } catch (Throwable) {
            return null;
        }

        // The body arrives as the last literal in the data list, and that is
        // what this reads. **Not** `lookup('[n]')`: a *partial* fetch makes
        // Dovecot echo the range back as a bare `<0>`, which the tokenizer
        // splits into its own tokens, so the `[n]` key is no longer adjacent
        // to its value and `lookup()` returns the range marker instead of
        // the body. Taking the last literal works for both forms — verified
        // against full and partial fetches.
        foreach ($responses as $response) {
            $data = $response->tokenAt(3);

            if (!$data instanceof ListData) {
                continue;
            }

            $body = null;

            foreach ($data->tokens() as $token) {
                if ($token instanceof Literal) {
                    $body = (string) $token->value;
                }
            }

            if (null !== $body) {
                return $body;
            }
        }

        return null;
    }

    /**
     * Decode a fetched part (quoted-printable, base64, charset).
     *
     * The library's decoder is reached through a throwaway `BODYSTRUCTURE`
     * parse rather than reimplemented: transfer encodings and charsets are
     * exactly the kind of thing a hand-rolled decoder gets subtly wrong on
     * real mail.
     */
    private function decodePart(MessageInterface $message, string $partNumber, string $raw, \DirectoryTree\ImapEngine\BodyStructurePart $part): ?string
    {
        // A partial fetch can cut a quoted-printable or base64 stream
        // mid-sequence; decoding that produces mojibake at the seam. The
        // library's decoder is fed the raw part and asked to do its best.
        $decoded = \DirectoryTree\ImapEngine\Support\BodyPartDecoder::text($part, $raw);

        if (null === $decoded || '' === $decoded) {
            // Some parts decode to nothing at all (a bare HTML wrapper, an
            // empty body). Returning the raw text is more honest than
            // returning an empty string.
            return '' === $raw ? null : $raw;
        }

        return $decoded;
    }

    /**
     * Which folders exist, so the gate can report configured names that do
     * not.
     *
     * @return list<string>
     */
    public function folderPaths(): array
    {
        return array_map(
            static fn (array $folder): string => $folder['path'],
            $this->listFolders(),
        );
    }

    /**
     * Add or remove a keyword on one message, and report the tags as the
     * server has them afterwards.
     *
     * **`$expunge` is never `true` here, and never will be.** `flag()` takes
     * an `$expunge` argument that runs a mailbox-wide `EXPUNGE`. The design's
     * rule is that no `trash` → expunge follow-up ever happens: `EXPUNGE` is
     * mailbox-wide, so honouring it could destroy mail the caller never
     * named. The parameter is not exposed and not passed.
     *
     * Verified against the reference server (finding 23): `flag()` performs
     * no validation, so a `\`-prefixed tag would set a *system* flag. The
     * caller is responsible for handing this a validated keyword —
     * {@see \App\Email\TagName} is that guard, and `tag_email` applies it
     * before ever reaching here.
     *
     * @return list<string> the message's tags, re-read from the server
     */
    public function setTag(string $path, int $uid, string $tag, bool $remove): array
    {
        return $this->connection->with(function (Mailbox $mailbox) use ($path, $uid, $tag, $remove): array {
            $folder = $this->findFolder($mailbox, $path);

            $message = $folder->messages()->withFlags()->find($uid);

            if (null === $message) {
                throw new MessageNotFound($path, $uid);
            }

            $message->flag($tag, $remove ? '-' : '+');

            return $this->keywords($this->reread($folder, $uid));
        });
    }

    /**
     * Set or clear `\Seen` on one message, and report the state as the
     * server has it afterwards.
     *
     * The design returns "the state as verified *after* the change, not
     * merely the intent", so the message is re-read rather than echoing what
     * was asked for. That is one extra round trip, and it is the difference
     * between "we issued a STORE" and "the flag is set" — the same
     * distinction `move_email` makes about `to_folder`.
     *
     * `\Seen` is set by name, not through `flag()`'s system-flag laxity: this
     * is the *only* place a system flag is written, it is deliberate, and it
     * is gated separately (`IMAP_MARK_FOLDERS`) from tagging.
     *
     * @return bool the message's `seen` state, re-read from the server
     */
    public function setSeen(string $path, int $uid, bool $seen): bool
    {
        return $this->connection->with(function (Mailbox $mailbox) use ($path, $uid, $seen): bool {
            $folder = $this->findFolder($mailbox, $path);

            $message = $folder->messages()->withFlags()->find($uid);

            if (null === $message) {
                throw new MessageNotFound($path, $uid);
            }

            $message->flag(ImapFlag::Seen->value, $seen ? '+' : '-');

            return $this->reread($folder, $uid)->isSeen();
        });
    }

    /**
     * Move one message to another folder, and report where it actually is.
     *
     * **Finding 26 is why this verifies by observation rather than trusting
     * the return value.** The library's `move()` returned `NULL` on the
     * reference server even though the move succeeded: Dovecot reports the
     * new UID in an *untagged* `* OK [COPYUID …]`, and
     * `MessageResponseParser::getUidFromCopy()` reads the *tagged* response,
     * so the UID is always lost. The untagged response is not reachable
     * through any public accessor.
     *
     * The design asks for exactly this anyway — `to_folder` is "the folder
     * the message was *observed* in afterwards, not the configured value
     * echoed back". So the move is issued, then the destination is searched
     * for the message's `Message-ID` (`HEADER Message-ID`, which the
     * reference server matches reliably and case-insensitively). The search
     * is scoped to the one destination folder, so it costs one `SEARCH`
     * against a mailbox we already have open.
     *
     * `$expunge` is never passed, for the reason given on
     * {@see self::setTag()}.
     *
     * @return array{uid: ?int, to_folder: string, tags: list<string>}|null
     *                                                                      null when the message is in neither folder afterwards — the move failed,
     *                                                                      which the caller must not report as success
     */
    public function moveMessage(string $path, int $uid, string $destination): ?array
    {
        return $this->connection->with(function (Mailbox $mailbox) use ($path, $uid, $destination): ?array {
            $source = $this->findFolder($mailbox, $path);

            $message = $source->messages()->withHeaders()->withFlags()->find($uid);

            if (null === $message) {
                throw new MessageNotFound($path, $uid);
            }

            // Captured *before* the move, because after it the message is
            // addressed by a different UID and this connection's source
            // folder no longer holds it.
            $messageId = $this->nullIfEmpty($message->messageId());

            // `move()` is on the concrete `Message`, not on the interface
            // this was typed against — the same shape as `hasBody()` and
            // `folder()`. Narrowed rather than asserted, so the call is
            // checked when it is made.
            if (!$message instanceof Message) {
                throw new RuntimeException('This mail server returned a message type that cannot be moved; context-shuttle expected DirectoryTree\\ImapEngine\\Message. This is a context-shuttle bug, not a problem with the mailbox.');
            }

            $newUid = $message->move($destination);

            return $this->locate($mailbox, $destination, $messageId, $newUid);
        });
    }

    /**
     * Find a message in a folder, preferring the UID the server reported and
     * falling back to its `Message-ID`.
     *
     * @return array{uid: ?int, to_folder: string, tags: list<string>}|null
     */
    private function locate(Mailbox $mailbox, string $destination, ?string $messageId, ?int $reportedUid): ?array
    {
        $folder = $mailbox->folders()->find($destination);

        if (null === $folder) {
            return null;
        }

        $folder->select(true);

        // The reported UID when the server gave one (it will not, per
        // finding 26 — but a server that does report it saves a search, and
        // this must not silently depend on the defect continuing).
        if (null !== $reportedUid) {
            $found = $folder->messages()->withFlags()->find($reportedUid);

            if (null !== $found) {
                return [
                    'uid' => $found->uid(),
                    'to_folder' => $destination,
                    'tags' => $this->keywords($found),
                ];
            }
        }

        if (null === $messageId) {
            // A message with no `Message-ID` cannot be located after a move:
            // ordinary for junk mail, and reporting it as moved when we
            // cannot see it would be a guess.
            return null;
        }

        // `HEADER` takes two arguments and the builder cannot express that
        // through two `where()` calls — see {@see SearchValue::header()}.
        $query = $folder->messages()->withFlags();
        $query->where(ImapSearchKey::Header, SearchValue::header('Message-ID', $messageId));

        $found = $query->first();

        if (null === $found) {
            return null;
        }

        return [
            'uid' => $found->uid(),
            'to_folder' => $destination,
            'tags' => $this->keywords($found),
        ];
    }

    /**
     * Re-read one message's flags from the server.
     *
     * Deliberately a fresh query rather than trusting the in-memory copy
     * `flag()` maintains: the design asks for verified state, and the
     * in-memory copy is the library's own bookkeeping of what it just sent.
     */
    private function reread(FolderInterface $folder, int $uid): MessageInterface
    {
        $message = $folder->messages()->withFlags()->find($uid);

        if (null === $message) {
            // The message was there a moment ago and a flag change cannot
            // remove it; a `null` here means another client expunged it, and
            // saying so is better than reporting a state we cannot see.
            throw new MessageNotFound($folder->path(), $uid);
        }

        return $message;
    }

    /**
     * The header set that answers "is this message what it claims to be?".
     *
     * These are the headers a model needs to triage a suspicious message and
     * that nothing else in the output carries: the receiving server's
     * SPF/DKIM/DMARC verdicts, the hop chain, the envelope sender (which is
     * *not* the `From:`), and a `Reply-To` that disagrees with `From:`.
     *
     * **Finding 10 is why this uses `getRawValue()`.** The library's
     * friendly `getValue()` returned just `mx.devgnome.com` for
     * `mx.devgnome.com; spf=fail smtp.mailfrom=evil.example; dkim=none;
     * dmarc=fail` — the entire verdict, silently discarded. That is the one
     * header where a partial read is worse than no read, because it looks
     * like a clean result.
     *
     * @return array<string, string>
     */
    private function spamHeaders(MessageInterface $message): array
    {
        $names = [
            'Authentication-Results',
            'Received',
            'Return-Path',
            'Reply-To',
            'Message-ID',
            'List-Unsubscribe',
            'X-Spam-Flag',
            'X-Spam-Score',
            'X-Spam-Status',
            'Content-Type',
            'Date',
            'User-Agent',
        ];

        $headers = [];

        foreach ($names as $name) {
            $header = $message->header($name);

            if (null === $header) {
                continue;
            }

            $headers[$name] = (string) $header->getRawValue();
        }

        return $headers;
    }

    /**
     * `References` as a list of message-ids, for thread reconstruction.
     *
     * @return list<string>
     */
    private function headerReferences(MessageInterface $message): array
    {
        $header = $message->header('References');

        if (null === $header) {
            return [];
        }

        $raw = (string) $header->getRawValue();

        preg_match_all('/<[^>]+>/', $raw, $matches);

        return $matches[0];
    }

    /**
     * @return array{path: string, name: string, messages: int, unread: int}
     */
    private function describeFolder(FolderInterface $folder): array
    {
        $path = $this->decode($folder->path());

        $messages = 0;
        $unread = 0;

        try {
            $status = $folder->status();
            $messages = (int) ($status['MESSAGES'] ?? 0);
            $unread = (int) ($status['UNSEEN'] ?? 0);
        } catch (Throwable) {
            // A folder that cannot be `STATUS`ed (a `\Noselect` parent, a
            // permissions quirk) is still a folder we can name. Counts of
            // zero are honest here: they say "not known", and the alternative
            // — failing the whole listing over one unreadable parent — is
            // worse.
        }

        return [
            'path' => $path,
            'name' => $this->folderName($folder),
            'messages' => $messages,
            'unread' => $unread,
        ];
    }

    /**
     * The folder's own label: the last decoded path segment.
     *
     * Not taken from the server's `name()`, which decodes but keeps the
     * server's separator assumptions; splitting the decoded path on any of
     * the common separators is right on every server we have seen and
     * produces `Widget` for `Projects/Widget` either way.
     */
    private function folderName(FolderInterface $folder): string
    {
        $path = $this->decode($folder->path());

        $segments = preg_split('/[\/.]+/', $path) ?: [$path];

        return (string) end($segments);
    }

    /**
     * @return array{uid: int, subject: ?string, from: ?string, to: list<string>, date: ?string, seen: bool, flagged: bool, answered: bool, tags: list<string>, message_id: ?string, in_reply_to: ?string, size: ?int, attachments: list<array{name: string, content_type: string, size: ?int}>}
     */
    private function envelope(MessageInterface $message, FolderInterface $folder): array
    {
        return [
            'uid' => $message->uid(),
            'subject' => $this->nullIfEmpty($message->subject()),
            'from' => $this->firstAddress($message->from()),
            'to' => $this->addressList($message->to()),
            'date' => $this->dateOf($message),
            'seen' => $message->isSeen(),
            'flagged' => $message->isFlagged(),
            'answered' => $message->isAnswered(),
            'tags' => $this->keywords($message),
            'message_id' => $this->nullIfEmpty($message->messageId()),
            'in_reply_to' => $this->firstOrNull($message->inReplyTo()),
            'size' => $message->size(),
            'attachments' => $this->attachments($message),
            'folder' => $this->decode($folder->path()),
        ];
    }

    /**
     * Attachment *metadata*, never content (finding 13).
     *
     * Built from the message's `BODYSTRUCTURE` parts directly, **not** via
     * `$message->attachments(fetch: true)`. That helper wraps each part's
     * content in a `LazyBodyPartStream` whose `getSize()` **downloads the
     * whole part** (`strlen($this->getOrFetchContent())`), so reading a
     * size off it fetches every attachment in the folder — the exact cost
     * this method exists to avoid. Verified against the scripted server: the
     * helper turned one envelope fetch into an extra `UID FETCH 1
     * (BODY.PEEK[2])`.
     *
     * `BODYSTRUCTURE` already carries the size, the filename and the type,
     * which is everything the tool surface promises. The alternative —
     * `attachmentCount()`/`hasAttachments()` — goes the other way and parses
     * the MIME body, reporting `0` for any message whose body was not
     * fetched, which is a silently wrong answer.
     *
     * @return list<array{name: string, content_type: string, size: ?int}>
     */
    private function attachments(MessageInterface $message): array
    {
        $attachments = [];

        try {
            $parts = $message->bodyStructure()?->attachments() ?? [];

            foreach ($parts as $part) {
                $filename = $part->filename();

                $attachments[] = [
                    'name' => null === $filename || '' === $filename ? 'unnamed' : $filename,
                    'content_type' => $part->contentType(),
                    // The size is from BODYSTRUCTURE, so nothing is fetched.
                    'size' => $part->size(),
                ];
            }
        } catch (Throwable) {
            // A malformed `BODYSTRUCTURE` must not fail a listing: the
            // message is still readable, it just has no attachment detail.
        }

        return $attachments;
    }

    /**
     * The message's `Date:` header as an ISO 8601 instant.
     *
     * The library parses it into a Carbon instance; a message with no
     * usable `Date:` (ordinary for junk mail) yields null rather than an
     * error, and the listing still shows it.
     */
    private function dateOf(MessageInterface $message): ?string
    {
        try {
            $date = $message->date();

            return $date?->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * IMAP keywords — the agent's own state, as opposed to system flags.
     *
     * Finding 4: a custom keyword set on a message is searchable via
     * `UNKEYWORD`, and finding 5: it survives a move, so the mailbox itself
     * is the agent's state store. System flags (`\Seen`, `\Flagged`,
     * `\Answered`, `\Deleted`, `\Draft`, `\Recent`) are reported as their own
     * booleans and excluded here, so "tags" means exactly "things the agent
     * (or a filter) invented".
     *
     * @return list<string>
     */
    private function keywords(MessageInterface $message): array
    {
        $tags = [];

        foreach ($message->flags() as $flag) {
            if (!str_starts_with($flag, '\\')) {
                $tags[] = $flag;
            }
        }

        sort($tags);

        return $tags;
    }

    private function firstAddress(?\DirectoryTree\ImapEngine\Address $address): ?string
    {
        if (null === $address) {
            return null;
        }

        $name = $address->name();

        return '' === $name ? $address->email() : \sprintf('%s <%s>', $name, $address->email());
    }

    /**
     * @param list<\DirectoryTree\ImapEngine\Address> $addresses
     *
     * @return list<string>
     */
    private function addressList(array $addresses): array
    {
        $out = [];

        foreach ($addresses as $address) {
            $formatted = $this->firstAddress($address);
            if (null !== $formatted) {
                $out[] = $formatted;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $values
     */
    private function firstOrNull(array $values): ?string
    {
        return [] === $values ? null : $this->nullIfEmpty($values[0]);
    }

    private function nullIfEmpty(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return null === $value || '' === $value ? null : $value;
    }

    private function findFolder(Mailbox $mailbox, string $path): FolderInterface
    {
        $folder = $mailbox->folders()->find($path);

        if (null === $folder) {
            // The gate has already let this folder through, so a miss here
            // means the folder vanished between discovery and use. Say so in
            // terms the caller can act on: the name they typed.
            throw new RuntimeException(\sprintf('No such folder on this mail server: "%s".', $path));
        }

        return $folder;
    }

    /**
     * Decode a server folder path to its human-readable form.
     *
     * Modified UTF-7 is a *folder name* encoding, and the `&` marker it
     * always produces is the reliable signal (finding 19). Decoding here
     * means nothing above this class ever compares an encoded path.
     */
    private function decode(string $path): string
    {
        return str_contains($path, '&') ? Str::fromImapUtf7($path) : $path;
    }
}
