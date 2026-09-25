<?php

declare(strict_types=1);

namespace App\Tests\Unit\Email;

use App\Email\Domain\MailAttachment;
use App\Email\Domain\MailContent;
use PHPUnit\Framework\TestCase;

/**
 * The two output shapes whose *absence* of fields is the design.
 *
 * `MailContent::toArray()` omits `headers` entirely when they were not
 * requested, rather than emitting an empty object: a caller that sees
 * `"headers": {}` cannot tell "not asked for" from "there were none", and
 * the spam-signal headers are exactly where that ambiguity is expensive.
 *
 * @internal
 *
 * @covers \App\Email\Domain\MailContent
 * @covers \App\Email\Domain\MailAttachment
 */
final class MailContentTest extends TestCase
{
    public function test_headers_are_omitted_when_not_requested(): void
    {
        $payload = $this->content(headers: null)->toArray();

        self::assertArrayNotHasKey('headers', $payload);
    }

    public function test_headers_are_present_when_requested(): void
    {
        $payload = $this->content(headers: ['Authentication-Results' => 'spf=fail'])->toArray();

        self::assertArrayHasKey('headers', $payload);
        self::assertSame('spf=fail', $payload['headers']['Authentication-Results']);
    }

    public function test_attachment_serialises_metadata_only(): void
    {
        $payload = (new MailAttachment('invoice.pdf', 'application/pdf', 1024))->toArray();

        self::assertSame(
            ['name' => 'invoice.pdf', 'content_type' => 'application/pdf', 'size' => 1024],
            $payload,
        );

        // Never the content. Stated as a test because "metadata only" is a
        // promise the tool description makes to a caller.
        self::assertArrayNotHasKey('content', $payload);
        self::assertArrayNotHasKey('data', $payload);
    }

    public function test_a_null_attachment_size_is_reported_as_null_not_zero(): void
    {
        // Not every server reports a size in BODYSTRUCTURE, and zero would
        // be a lie about an empty file.
        self::assertNull((new MailAttachment('x.bin', 'application/octet-stream', null))->toArray()['size']);
    }

    public function test_an_html_only_message_states_its_content_type(): void
    {
        $payload = $this->content(
            text: '<html><body>hi</body></html>',
            contentType: MailContent::TYPE_HTML,
        )->toArray();

        // A caller must be able to tell markup from prose.
        self::assertSame('text/html', $payload['content_type']);
    }

    public function test_truncation_is_reported_alongside_the_original_size(): void
    {
        $payload = $this->content(truncated: true, size: 2_000_000)->toArray();

        self::assertTrue($payload['truncated']);
        // Without `size` a caller knows it lost something but not how much.
        self::assertSame(2_000_000, $payload['size']);
    }

    /**
     * @param array<string, string>|null $headers
     */
    private function content(
        ?string $text = 'body',
        string $contentType = MailContent::TYPE_TEXT,
        bool $truncated = false,
        ?int $size = 100,
        ?array $headers = null,
    ): MailContent {
        return new MailContent(
            uid: 1,
            folder: 'INBOX',
            subject: 'Subject',
            from: null,
            to: [],
            cc: [],
            replyTo: null,
            date: null,
            messageId: null,
            inReplyTo: null,
            references: [],
            seen: false,
            flagged: false,
            tags: [],
            text: $text,
            contentType: $contentType,
            truncated: $truncated,
            size: $size,
            attachments: [],
            headers: $headers,
        );
    }
}
