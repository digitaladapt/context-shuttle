<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;

/**
 * The wire-level detail of a scripted IMAP server.
 *
 * A single server response is a *sequence of physical lines*, and each must
 * be fed separately: the library appends its own `CRLF` to every entry it is
 * given, so an entry containing an embedded newline is read as two lines
 * with a stray terminator in the middle. That mistake shows up as
 * "Unterminated list in response" rather than as anything obviously about
 * newlines, which is why the escaping is centralised here.
 *
 * A line ending in a literal marker (`{n}`) is followed by exactly `n` bytes
 * of content, passed through untouched so the byte count stays honest.
 */
final class ScriptedImap
{
    /**
     * @param list<string> $lines untagged responses, in order
     */
    public function __construct(
        private readonly ImapConnection $connection,
        private readonly array $lines,
    ) {
    }

    public function connection(): ImapConnection
    {
        return $this->connection;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Build a `FETCH` response for one message envelope.
     *
     * Returns the physical lines, in Dovecot's order: the header literal,
     * then `BODYSTRUCTURE` as the final data item. `BODYSTRUCTURE` must come
     * after any literal, because a literal's content is the next thing on
     * the wire — putting it last is how the real server does it, and getting
     * it wrong is how a fixture silently parses as something else.
     *
     * @param list<array{name: string, type: string, size: int}> $attachments
     *
     * @return list<string>
     */
    public static function envelope(
        int $uid,
        string $subject,
        ?string $date,
        int $size = 300,
        array $attachments = [],
    ): array {
        $headers = "From: Sender <sender@example.com>\r\n"
            ."Subject: {$subject}\r\n"
            .(null === $date ? '' : "Date: {$date}\r\n")
            ."\r\n";

        $structure = self::bodyStructure($size, $attachments);

        return [
            \sprintf(
                '* %d FETCH (UID %d FLAGS () RFC822.SIZE %d BODY[HEADER] {%d}',
                $uid,
                $uid,
                $size,
                \strlen($headers),
            ),
            $headers,
            ' BODYSTRUCTURE '.$structure.')',
        ];
    }

    /**
     * The `BODYSTRUCTURE` value for a message: a `text/plain` body, plus a
     * part per attachment.
     *
     * @param list<array{name: string, type: string, size: int}> $attachments
     */
    public static function bodyStructure(int $bodySize, array $attachments = []): string
    {
        $text = \sprintf('("text" "plain" ("charset" "utf-8") NIL NIL "7bit" %d 1 NIL NIL NIL NIL)', $bodySize);

        if ([] === $attachments) {
            // A single-part message is the bare part, with no enclosing
            // multipart list — wrapping it produces a multipart with no
            // parts, which parses without error and yields nothing useful.
            return $text;
        }

        $parts = [$text];

        foreach ($attachments as $attachment) {
            $parts[] = \sprintf(
                '("application" "octet-stream" ("name" "%s") NIL NIL "base64" %d NIL ("attachment" ("filename" "%s")) NIL NIL)',
                $attachment['name'],
                $attachment['size'],
                $attachment['name'],
            );
        }

        // The trailing fields after the subtype are the multipart's own
        // parameters and disposition, which the real server sends as NILs.
        // Copied from a captured Dovecot response rather than guessed: the
        // parser is positional, so a missing field shifts everything after
        // it and the part list silently comes back wrong.
        return '('.implode(' ', $parts).' "mixed" ("boundary" "BOUNDARY") NIL NIL NIL)';
    }

    /**
     * Feed scripted lines into a `FakeStream`, honouring literals.
     *
     * @param list<string> $lines
     */
    public static function feed(FakeStream $stream, array $lines): void
    {
        $expectLiteral = false;

        foreach ($lines as $line) {
            if ($expectLiteral) {
                $stream->feedRaw($line);
                $expectLiteral = false;

                continue;
            }

            $stream->feed($line);

            if (preg_match('/\{(\d+)\}$/', $line, $matches)) {
                // A zero-length literal has no content to consume.
                $expectLiteral = (int) $matches[1] > 0;
            }
        }
    }
}
