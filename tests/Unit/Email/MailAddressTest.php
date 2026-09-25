<?php

declare(strict_types=1);

namespace App\Tests\Unit\Email;

use App\Email\Domain\MailAddress;
use PHPUnit\Framework\TestCase;

/**
 * Address parsing, which is lenient on purpose.
 *
 * A malformed `From:` is ordinary in real mail — and in spam especially —
 * so it must never fail a listing. The interesting cases are the ones where
 * a naive parser does something *wrong* rather than something reported.
 *
 * @internal
 *
 * @covers \App\Email\Domain\MailAddress
 */
final class MailAddressTest extends TestCase
{
    public function test_parses_a_named_address(): void
    {
        $address = MailAddress::parse('Alice Example <alice@example.com>');

        self::assertSame('alice@example.com', $address->address);
        self::assertSame('Alice Example', $address->name);
    }

    public function test_parses_a_quoted_display_name_and_strips_the_quotes(): void
    {
        // Attackers choose this string, so the quotes are presentation and
        // must not become part of the name.
        $address = MailAddress::parse('"Urgent Security" <noreply@evil.example>');

        self::assertSame('noreply@evil.example', $address->address);
        self::assertSame('Urgent Security', $address->name);
    }

    public function test_parses_a_bare_address_with_no_name(): void
    {
        $address = MailAddress::parse('alice@example.com');

        self::assertSame('alice@example.com', $address->address);
        self::assertNull($address->name);
    }

    public function test_a_display_name_is_never_mistaken_for_the_address(): void
    {
        // The security-relevant case: a model reasoning about "who sent this"
        // needs the routable part to be visibly separate from the label
        // somebody chose.
        $address = MailAddress::parse('lyra@devgnome.com <attacker@evil.example>');

        self::assertSame('attacker@evil.example', $address->address);
        self::assertSame('lyra@devgnome.com', $address->name);
    }

    public function test_tolerates_a_display_name_with_an_at_sign_inside(): void
    {
        $address = MailAddress::parse('support@example.com <real@example.org>');

        self::assertSame('real@example.org', $address->address);
    }

    public function test_an_empty_value_yields_an_empty_address_rather_than_failing(): void
    {
        $address = MailAddress::parse('   ');

        self::assertSame('', $address->address);
        self::assertNull($address->name);
    }

    public function test_an_unclosed_angle_bracket_does_not_lose_the_value(): void
    {
        // Malformed, but usable — and dropping it would be worse than
        // returning something odd, because the caller would see no sender
        // at all.
        $address = MailAddress::parse('Alice <alice@example.com');

        self::assertNotSame('', $address->address);
    }

    public function test_serialises_to_address_and_name(): void
    {
        self::assertSame(
            ['address' => 'a@b.test', 'name' => 'A'],
            (new MailAddress('a@b.test', 'A'))->toArray(),
        );
    }
}
