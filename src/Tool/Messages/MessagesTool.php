<?php

declare(strict_types=1);

namespace App\Tool\Messages;

use App\Messages\ConversationName;
use App\Messages\ConversationNameException;
use App\Messages\MessageBody;
use App\Messages\MessageStore;
use App\Messages\Sender;
use InvalidArgumentException;

/**
 * The messages family: speak into a conversation, and read one back.
 *
 * `send_message` is the deliberate act of being heard — distinct from a
 * model's ambient generation, which is why it is a tool the caller must
 * choose, and why its description steers self-talk to the memory tools
 * instead (a diary entry nobody will read is not a message; it is a memory
 * the caller declined to file).
 *
 * `get_messages` is the read-side verb that makes the family survive a
 * compaction: the conversation store outlives any context window, and a
 * caller whose earlier exchanges have fallen out of context re-reads them
 * here — including its own past messages, which is half the point. New
 * messages are *delivered* to a resident by its harness; this tool is for
 * recall, not polling, and its description says so, because a caller that
 * could not know otherwise would reasonably burn turns asking an inbox
 * question the delivery path has already answered.
 *
 * Both operations are thin over {@see MessageStore}: the tool decides the
 * things that are transport concerns (the blank-body refusal, the sender
 * default, the paging ceiling) and nothing else. The one honest hole, per
 * the design note: a `sender` is a *claim*, not a credential — this
 * deployment authenticates one actor for the whole gateway, so a caller can
 * name a sender it is not and the store cannot check. v1 accepts and
 * documents that; the fix is per-participant keys, at which point the
 * parameter is removed and the sender is derived server-side.
 */
final class MessagesTool
{
    /**
     * The paging ceiling: one read can never become a context bomb. A
     * conversation longer than this pages with `since` + `newest_id`.
     */
    public const int MAX_LIMIT = 200;

    public function __construct(
        private readonly MessageStore $store,
        private readonly string $actor,
    ) {
    }

    /**
     * Send a message in a conversation.
     *
     * @return array<string, mixed> the stored row, with the id that doubles
     *                              as a paging cursor, and `truncated` when
     *                              the body was cut to the store's ceiling
     */
    public function sendMessage(string $body, ?string $conversation = null, ?string $sender = null): array
    {
        $normalized = MessageBody::normalize($body);

        try {
            $name = ConversationName::from($conversation);
        } catch (ConversationNameException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        $who = Sender::from($sender, $this->actor)->value;

        $row = $this->store->append($name, $who, $normalized['body']);

        return [
            'stored' => true,
            'id' => $row['id'],
            'conversation' => $row['conversation'],
            'sender' => $row['sender'],
            'created_at' => $row['created_at'],
            'truncated' => $normalized['truncated'],
        ];
    }

    /**
     * Read messages from a conversation, oldest first, after an id.
     *
     * @return array<string, mixed> the page: messages, the newest id it
     *                              contains, and whether more exists
     */
    public function getMessages(?string $conversation = null, int $since = 0, ?int $limit = null): array
    {
        try {
            $name = ConversationName::from($conversation);
        } catch (ConversationNameException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        $since = max(0, $since);
        $limit = min(self::MAX_LIMIT, max(1, $limit ?? 50));

        return $this->store->after($name, $since, $limit);
    }

    /*
     * The sender of record: the caller's claim, or the configured actor.
     *
     * Omitting `sender` means "I am speaking as this deployment's resident",
     * which is what MESSAGES_ACTOR names; naming one is a claim the store
     * cannot verify (see Sender). A blank claim is the same as none — a
     * caller that sends whitespace said nothing it can stand behind.
     */
}
