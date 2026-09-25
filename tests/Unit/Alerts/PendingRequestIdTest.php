<?php

declare(strict_types=1);

namespace App\Tests\Unit\Alerts;

use App\Alerts\PendingRequestId;
use PHPUnit\Framework\TestCase;

/**
 * Ids are the capability for the whole interactive flow — the entropy
 * and the shape are both load-bearing, so both are pinned here.
 *
 * @internal
 *
 * @covers \App\Alerts\PendingRequestId
 */
final class PendingRequestIdTest extends TestCase
{
    public function test_generates_a_url_safe_id_of_the_advertised_length(): void
    {
        $id = PendingRequestId::generate();

        self::assertSame(PendingRequestId::LENGTH, \strlen($id));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_\-]+$/', $id);
    }

    public function test_ids_are_unique_across_many_draws(): void
    {
        $seen = [];

        for ($i = 0; $i < 200; ++$i) {
            $id = PendingRequestId::generate();
            self::assertArrayNotHasKey($id, $seen, 'Two generated ids collided.');
            $seen[$id] = true;
        }

        self::assertCount(200, $seen);
    }

    public function test_entropy_is_cryptographic(): void
    {
        // 16 random bytes -> 22 base64url chars ~ 128 bits. A cheap
        // statistical smoke test: two batches of ids must not overlap and
        // the character set must be used broadly (a constant id or a
        // counter would stick out immediately).
        $chars = '';
        $ids = [];

        for ($i = 0; $i < 50; ++$i) {
            $id = PendingRequestId::generate();
            $ids[] = $id;
            $chars .= $id;
        }

        self::assertSame(50, \count(array_unique($ids)));
        self::assertGreaterThan(30, \count(count_chars($chars, 1)), 'Generated ids should use a broad slice of the alphabet.');
    }
}
