<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tool\Alerts;

use App\Tool\Alerts\VisibleLinkFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Pins the display rules from docs/design/ALERTS.md (Visible-link
 * formatting). The four render cases there are asserts here; the
 * boundary cases sit at exactly 50/51 characters in each direction.
 *
 * @internal
 *
 * @covers \App\Tool\Alerts\VisibleLinkFormatter
 */
final class VisibleLinkFormatterTest extends TestCase
{
    private function formatter(): VisibleLinkFormatter
    {
        return new VisibleLinkFormatter();
    }

    public function test_renders_intact_domain_and_path(): void
    {
        self::assertSame(
            '→ **example.com**/run/42',
            $this->formatter()->format('https://example.com/run/42'),
        );
    }

    public function test_elides_long_path_from_the_tail(): void
    {
        self::assertSame(
            '→ **example.com**/github/actions/runs/98765/jobs/123456789012345678…',
            $this->formatter()->format('https://example.com/github/actions/runs/98765/jobs/1234567890123456789012'),
        );
    }

    public function test_elides_long_domain_from_the_head_keeping_registrable_domain(): void
    {
        self::assertSame(
            '→ **…k8s.nightly.eu-west-1.staging.internal.example.com**/reports/q3',
            $this->formatter()->format('https://big-build-node.k8s.nightly.eu-west-1.staging.internal.example.com/reports/q3'),
        );
    }

    public function test_elides_both_domain_and_path_together(): void
    {
        self::assertSame(
            '→ **…k8s.nightly.eu-west-1.staging.internal.example.com**/reports/q3/artifacts/final-results-with-very-long…',
            $this->formatter()->format('https://big-build-node.k8s.nightly.eu-west-1.staging.internal.example.com/reports/q3/artifacts/final-results-with-very-long-name.json'),
        );
    }

    public function test_domain_of_exactly_50_chars_is_not_elided(): void
    {
        $host = str_repeat('a', 38).'.example.com'; // 50 chars exactly

        self::assertSame(
            '→ **'.$host.'**/p',
            $this->formatter()->format('https://'.$host.'/p'),
        );
    }

    public function test_domain_of_51_chars_is_elided(): void
    {
        $host = str_repeat('a', 39).'.example.com'; // 51 chars

        self::assertSame(
            '→ **…'.substr($host, 1).'**/p',
            $this->formatter()->format('https://'.$host.'/p'),
        );
    }

    public function test_path_of_exactly_50_chars_is_not_elided(): void
    {
        $path = '/'.str_repeat('a', 49); // 50 chars exactly

        self::assertSame(
            '→ **example.com**'.$path,
            $this->formatter()->format('https://example.com'.$path),
        );
    }

    public function test_path_of_51_chars_is_elided(): void
    {
        $path = '/'.str_repeat('a', 50); // 51 chars

        self::assertSame(
            '→ **example.com**'.substr($path, 0, 50).'…',
            $this->formatter()->format('https://example.com'.$path),
        );
    }

    public function test_drops_query_string_and_fragment(): void
    {
        self::assertSame(
            '→ **example.com**/dashboard',
            $this->formatter()->format('https://example.com/dashboard?token=secret#section'),
        );
    }

    public function test_omits_empty_and_root_paths(): void
    {
        self::assertSame('→ **example.com**', $this->formatter()->format('https://example.com'));
        self::assertSame('→ **example.com**', $this->formatter()->format('https://example.com/'));
    }

    public function test_keeps_port(): void
    {
        self::assertSame(
            '→ **example.com:8443**/dashboard',
            $this->formatter()->format('https://example.com:8443/dashboard'),
        );
    }

    public function test_port_counts_toward_domain_elision(): void
    {
        $host = str_repeat('a', 38).'.example.com'; // 50 + ':8443' = 55

        self::assertSame(
            '→ **…'.substr($host.':8443', -50).'**/p',
            $this->formatter()->format('https://'.$host.':8443/p'),
        );
    }

    public function test_returns_null_for_missing_empty_or_non_http_urls(): void
    {
        self::assertNull($this->formatter()->format(null));
        self::assertNull($this->formatter()->format(''));
        self::assertNull($this->formatter()->format('   '));
        self::assertNull($this->formatter()->format('not a url'));
        self::assertNull($this->formatter()->format('mailto:boss@example.com'));
        self::assertNull($this->formatter()->format('ftp://example.com/file'));
    }
}
