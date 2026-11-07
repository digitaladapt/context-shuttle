<?php

declare(strict_types=1);

namespace App\Messages;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;

/**
 * The message store: one SQLite file, conversations as append-only logs.
 *
 * This is context-shuttle's only owned durable state besides its logs, and it
 * is deliberately *not* a second database engine — the project carries no
 * ORM and no migrations, and a message bus is two columns past what a single
 * SQLite file can hold. The schema is ensured idempotently on first use
 * (never at boot: a store that cannot be created must not stop the gateway
 * from serving its other tools), and every operation is a plain statement
 * with no abstraction over it, because there is nothing to abstract.
 *
 * The shape follows the design note (`docs/design` SEND_MESSAGE): a message
 * is `(id, conversation, sender, body, created_at)`; `id` is monotonic and
 * doubles as the polling cursor; nothing is ever updated or deleted — a
 * conversation is an append-only log, the same discipline every client of it
 * (a resident's context window among them) already lives by.
 *
 * Concurrency: SQLite in WAL mode, one connection per store instance per
 * process. Multiple processes against one file are safe for this workload
 * (short writes, rare), which is the deployment shape the design note
 * describes — one gateway container, occasionally a CLI poking the same
 * file.
 */
final class MessageStore
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $path,
    ) {
    }

    /**
     * The file this store lives in: MESSAGES_DB when named, else the
     * gateway's own var/messages.sqlite — the same directory the logs and
     * cache own, so a default deployment never creates a new root.
     */
    private function resolvePath(): string
    {
        $configured = trim($this->path);

        return '' !== $configured ? $configured : $this->projectDir.'/var/messages.sqlite';
    }

    /**
     * Append a message to a conversation.
     *
     * The body has already been normalized by {@see MessageBody}; the store
     * scrubs nothing and caps nothing, so "stored" means exactly what the
     * caller normalized. Returns the row as callers see it, with the id the
     * polling surface uses as a cursor.
     *
     * @return array{id: int, conversation: string, sender: string, body: string, created_at: string}
     */
    public function append(ConversationName $conversation, string $sender, string $body): array
    {
        $createdAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $statement = $this->pdo()->prepare(
            'INSERT INTO message (conversation, sender, body, created_at) VALUES (:conversation, :sender, :body, :created_at)',
        );
        $statement->execute([
            'conversation' => $conversation->value,
            'sender' => $sender,
            'body' => $body,
            'created_at' => $createdAt,
        ]);

        $id = (int) $this->pdo()->lastInsertId();

        return [
            'id' => $id,
            'conversation' => $conversation->value,
            'sender' => $sender,
            'body' => $body,
            'created_at' => $createdAt,
        ];
    }

    /**
     * Messages in a conversation with an id greater than `$since`, oldest
     * first, at most `$limit`.
     *
     * The shape is the paging contract the design note states: the caller
     * holds nothing but a number it can re-derive from the last page it
     * read, so the answer carries the newest id it contains (`newest_id`)
     * and whether more exists beyond the limit (`more_available`). An empty
     * page reports `newest_id = $since`: no progress was made, and a caller
     * that persists that cursor is not wrong, it is current.
     *
     * @return array{
     *     conversation: string,
     *     messages: list<array{id: int, sender: string, body: string, created_at: string}>,
     *     count: int,
     *     newest_id: int,
     *     more_available: bool
     * }
     */
    public function after(ConversationName $conversation, int $since, int $limit): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT id, sender, body, created_at FROM message
             WHERE conversation = :conversation AND id > :since
             ORDER BY id ASC LIMIT :limit',
        );
        $statement->execute([
            'conversation' => $conversation->value,
            'since' => $since,
            'limit' => $limit,
        ]);

        $messages = [];
        $newestId = $since;

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $messages[] = [
                'id' => $id,
                'sender' => $row['sender'],
                'body' => $row['body'],
                'created_at' => $row['created_at'],
            ];
            $newestId = $id;
        }

        $more = false;
        if (\count($messages) === $limit) {
            $check = $this->pdo()->prepare(
                'SELECT 1 FROM message WHERE conversation = :conversation AND id > :newest LIMIT 1',
            );
            $check->execute([
                'conversation' => $conversation->value,
                'newest' => $newestId,
            ]);
            $more = false !== $check->fetchColumn();
        }

        return [
            'conversation' => $conversation->value,
            'messages' => $messages,
            'count' => \count($messages),
            'newest_id' => $newestId,
            'more_available' => $more,
        ];
    }

    /**
     * The connection, and the schema it depends on, created on first use.
     *
     * `CREATE TABLE IF NOT EXISTS` is the whole migration story for two
     * columns and an index; re-running it is free, so there is no version
     * marker to drift. A failure here surfaces as the exception it is —
     * "you pointed the message store at a place it cannot write" is
     * configuration to fix, and pretending otherwise would turn every
     * message into a silent no-op.
     */
    private function pdo(): PDO
    {
        if (null !== $this->pdo) {
            return $this->pdo;
        }

        $path = $this->resolvePath();
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("The message store directory '{$directory}' does not exist and cannot be created.");
        }

        try {
            $pdo = new PDO('sqlite:'.$path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS message (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    conversation TEXT NOT NULL,
                    sender TEXT NOT NULL,
                    body TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )',
            );
            $pdo->exec(
                'CREATE INDEX IF NOT EXISTS idx_message_conversation_id ON message (conversation, id)',
            );
        } catch (PDOException $exception) {
            throw new RuntimeException("The message store at '{$path}' could not be opened or initialized: ".$exception->getMessage(), previous: $exception);
        }

        return $this->pdo = $pdo;
    }
}
