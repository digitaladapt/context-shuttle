<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Messages;

use App\Messages\MessageStore;
use App\Tool\Messages\MessagesTool;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the messages family, against a throwaway SQLite file so the
 * real store's behaviour (id monotonicity, cursor paging, conversation
 * isolation) is exercised without a network or a kernel.
 *
 * The assertions check both directions of the transport, like the
 * memory-draft tests do: what a send returns, and what a read hands back —
 * including the paging contract (newest_id, more_available) that the
 * resident harness and the REST surface both depend on.
 *
 * @internal
 *
 * @covers \App\Tool\Messages\MessagesTool
 */
final class MessagesToolTest extends TestCase
{
    private ?MessagesTool $tool = null;

    #[Override]
    protected function tearDown(): void
    {
        $file = $this->storePath();
        if (null !== $file && file_exists($file)) {
            @unlink($file);
            @unlink($file.'-wal');
            @unlink($file.'-shm');
        }

        parent::tearDown();
    }

    private function tool(string $actor = 'resident'): MessagesTool
    {
        if (null === $this->tool) {
            $this->tool = new MessagesTool(new MessageStore(sys_get_temp_dir(), $this->storePath()), $actor);
        }

        return $this->tool;
    }

    private function storePath(): string
    {
        return sys_get_temp_dir().'/resident-messages-test-'.getmypid().'.sqlite';
    }

    public function test_send_stores_and_confirms(): void
    {
        $result = $this->tool()->sendMessage('Hello there.');

        self::assertTrue($result['stored']);
        self::assertSame(1, $result['id']);
        self::assertSame('default', $result['conversation']);
        self::assertSame('resident', $result['sender']);
        self::assertFalse($result['truncated']);
        self::assertNotEmpty($result['created_at']);
    }

    public function test_send_refuses_a_blank_body(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nothing to store');

        $this->tool()->sendMessage("   \n\t ");
    }

    public function test_sender_defaults_to_the_configured_actor_and_honours_a_claim(): void
    {
        self::assertSame('flint', $this->tool('flint')->sendMessage('hello')['sender']);
        self::assertSame('andrew', $this->tool('flint')->sendMessage('hello', sender: 'andrew')['sender']);
    }

    public function test_a_blank_sender_claim_falls_back_to_the_actor(): void
    {
        self::assertSame('flint', $this->tool('flint')->sendMessage('hello', sender: '  ')['sender']);
    }

    public function test_conversations_are_isolated_logs(): void
    {
        $this->tool()->sendMessage('first', 'alpha');
        $this->tool()->sendMessage('second', 'alpha');
        $this->tool()->sendMessage('other', 'beta');

        $alpha = $this->tool()->getMessages('alpha');

        self::assertSame(2, $alpha['count']);
        self::assertSame('first', $alpha['messages'][0]['body']);
        self::assertSame('second', $alpha['messages'][1]['body']);
    }

    public function test_the_default_conversation_is_named_default(): void
    {
        $this->tool()->sendMessage('hello');

        self::assertSame('default', $this->tool()->getMessages()['conversation']);
    }

    public function test_an_invalid_conversation_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('conversation name');

        $this->tool()->sendMessage('hello', 'Not A Name');
    }

    public function test_an_empty_conversation_is_the_default_not_an_error(): void
    {
        $this->tool()->sendMessage('hello', 'default');
        $page = $this->tool()->getMessages('');

        self::assertSame('default', $page['conversation']);
        self::assertSame(1, $page['count']);
    }

    public function test_reads_page_by_cursor_oldest_first(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->tool()->sendMessage("message {$i}");
        }

        $page = $this->tool()->getMessages(limit: 2);

        self::assertSame(2, $page['count']);
        self::assertSame('message 1', $page['messages'][0]['body']);
        self::assertSame('message 2', $page['messages'][1]['body']);
        self::assertSame(2, $page['newest_id']);
        self::assertTrue($page['more_available']);

        $next = $this->tool()->getMessages(since: $page['newest_id'], limit: 2);

        self::assertSame('message 3', $next['messages'][0]['body']);
        self::assertTrue($next['more_available']);

        $last = $this->tool()->getMessages(since: $next['newest_id'], limit: 2);

        self::assertSame('message 5', $last['messages'][0]['body']);
        self::assertFalse($last['more_available']);
    }

    public function test_an_empty_page_reports_the_cursor_it_was_given(): void
    {
        $page = $this->tool()->getMessages(since: 41);

        self::assertSame(0, $page['count']);
        self::assertSame(41, $page['newest_id']);
        self::assertFalse($page['more_available']);
    }

    public function test_the_read_includes_the_callers_own_past_messages(): void
    {
        $this->tool()->sendMessage('my own words');

        $page = $this->tool()->getMessages();

        self::assertSame('resident', $page['messages'][0]['sender']);
        self::assertSame('my own words', $page['messages'][0]['body']);
    }

    public function test_limit_is_clamped_to_the_ceiling(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->tool()->sendMessage("message {$i}");
        }

        self::assertSame(5, $this->tool()->getMessages(limit: 9999)['count']);
        self::assertSame(1, $this->tool()->getMessages(limit: 0)['count']);
    }

    public function test_a_long_body_is_truncated_on_a_character_boundary(): void
    {
        $result = $this->tool()->sendMessage('é'.str_repeat('x', 5000));

        self::assertTrue($result['truncated']);

        $page = $this->tool()->getMessages();
        $body = $page['messages'][0]['body'];

        self::assertSame(4000, \strlen($body));
        self::assertSame('é', mb_substr($body, 0, 1));
        self::assertSame(1, preg_match('//u', $body));
    }

    public function test_control_characters_are_stripped_but_newlines_survive(): void
    {
        $this->tool()->sendMessage("line one\nline two\x07\x1b[0m");

        $page = $this->tool()->getMessages();

        // The ESC byte is a control character and goes; the "[0m" it
        // introduced is ordinary printable text and stays — scrubbing is
        // byte-level, not CSI-sequence-aware, and pretending otherwise
        // would be a parser where a filter is wanted.
        self::assertSame("line one\nline two[0m", $page['messages'][0]['body']);
    }
}
