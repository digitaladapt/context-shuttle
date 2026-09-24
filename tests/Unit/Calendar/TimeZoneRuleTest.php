<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\Domain\TimeZoneRule;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The timezone rule: everything in — TZ; everything out — TZ.
 *
 * @internal
 *
 * @covers \App\Calendar\Domain\TimeZoneRule
 */
final class TimeZoneRuleTest extends TestCase
{
    public function test_renders_an_instant_with_the_offsets_that_zone_observes(): void
    {
        $rule = new TimeZoneRule('America/New_York');
        $instant = new DateTimeImmutable('2026-10-29T09:00:00Z');

        self::assertSame('2026-10-29T05:00:00-04:00', $rule->render($instant));
    }

    public function test_the_same_instant_renders_differently_in_another_zone_without_moving(): void
    {
        $instant = new DateTimeImmutable('2026-10-29T09:00:00Z');

        $utc = (new TimeZoneRule('UTC'))->render($instant);
        $sydney = (new TimeZoneRule('Australia/Sydney'))->render($instant);

        self::assertSame('2026-10-29T09:00:00+00:00', $utc);
        self::assertSame('2026-10-29T20:00:00+11:00', $sydney);
        self::assertSame(
            $instant->getTimestamp(),
            (new DateTimeImmutable($sydney))->getTimestamp(),
            'rendering must not change which instant it is',
        );
    }

    public function test_dst_changes_the_offset_but_not_the_instant(): void
    {
        $rule = new TimeZoneRule('America/New_York');

        $before = $rule->render(new DateTimeImmutable('2026-10-31T12:00:00Z'));
        $after = $rule->render(new DateTimeImmutable('2026-11-02T12:00:00Z'));

        self::assertSame('2026-10-31T08:00:00-04:00', $before);
        self::assertSame('2026-11-02T07:00:00-05:00', $after);
    }

    public function test_start_of_day_is_midnight_in_the_deployment_zone(): void
    {
        $rule = new TimeZoneRule('America/New_York');

        self::assertSame('2026-10-01T00:00:00-04:00', $rule->startOfDay('2026-10-01')->format('c'));
    }

    public function test_end_of_day_is_exclusive_so_the_final_day_is_kept_whole(): void
    {
        $rule = new TimeZoneRule('America/New_York');

        // An inclusive `to` of the 31st becomes exclusive midnight on Nov 1.
        self::assertSame('2026-11-01T00:00:00-04:00', $rule->endOfDayExclusive('2026-10-31')->format('c'));
    }

    public function test_empty_means_utc_rather_than_an_error(): void
    {
        self::assertSame('UTC', (new TimeZoneRule(''))->name());
        self::assertSame('UTC', (new TimeZoneRule('   '))->name());
    }

    public function test_an_invalid_zone_fails_loudly_and_names_the_problem(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TZ');

        new TimeZoneRule('Mars/Olympus_Mons');
    }

    public function test_a_fixed_offset_is_rejected_because_it_cannot_follow_dst(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fixed offset');

        new TimeZoneRule('-04:00');
    }

    public function test_a_valid_zone_reports_its_name(): void
    {
        self::assertSame('Europe/London', (new TimeZoneRule('Europe/London'))->name());
    }
}
