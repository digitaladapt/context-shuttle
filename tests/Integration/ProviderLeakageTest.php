<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Calendar\Domain\CalendarInfo;
use App\Calendar\Domain\CalendarObject;
use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Mapping\EventMapper;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The guard that keeps a data source from leaking into the tool surface.
 *
 * A caller must never learn which kind of source a deployment reads, because
 * the moment a result invites a caller to branch on that, adding a second
 * source becomes an expensive change instead of an invisible one. This test
 * is deliberately blunt about it: no result, and no model-visible
 * description, may contain the vocabulary.
 *
 * It runs against the *shipped* YAML and real mapper output rather than a
 * fixture, so a future field cannot slip past it.
 *
 * @internal
 */
final class ProviderLeakageTest extends TestCase
{
    /** Words that would tell a caller where the data came from. */
    private const FORBIDDEN = ['caldav', 'ics', 'source', 'provider', 'webcal'];

    public function test_no_tool_description_names_a_data_source(): void
    {
        foreach (glob(__DIR__.'/../../config/tools/*.yaml') ?: [] as $file) {
            $raw = Yaml::parseFile($file);
            self::assertIsArray($raw);

            $description = strtolower((string) ($raw['description'] ?? ''));

            foreach (self::FORBIDDEN as $word) {
                self::assertStringNotContainsString(
                    $word,
                    $description,
                    \sprintf('tool "%s" (%s) names a data source', $raw['name'] ?? '?', basename($file)),
                );
            }
        }
    }

    public function test_a_mapped_event_carries_no_provider_vocabulary(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
            ."BEGIN:VEVENT\r\nUID:evt\r\nDTSTART:20261015T130000Z\r\nDTEND:20261015T140000Z\r\n"
            ."SUMMARY:Standup\r\nDESCRIPTION:A meeting\r\nLOCATION:Office\r\nCATEGORIES:work\r\nSTATUS:CONFIRMED\r\n"
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";

        $mapper = new EventMapper(new TimeZoneRule('UTC'));

        [$events] = $mapper->map(
            new CalendarObject('/cal/a.ics', $ics, null, new CalendarInfo('/cal/', 'Work', false)),
            new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC')),
        );

        self::assertCount(1, $events);

        $encoded = strtolower((string) json_encode($events[0]->toArray(), \JSON_THROW_ON_ERROR));

        foreach (self::FORBIDDEN as $word) {
            self::assertStringNotContainsString($word, $encoded, 'an event row must not name its origin');
        }
    }

    public function test_the_event_row_has_no_field_that_could_branch_on_origin(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
            ."BEGIN:VEVENT\r\nUID:evt\r\nDTSTART:20261015T130000Z\r\nDTEND:20261015T140000Z\r\nSUMMARY:S\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $mapper = new EventMapper(new TimeZoneRule('UTC'));

        [$events] = $mapper->map(
            new CalendarObject('/cal/a.ics', $ics, null, new CalendarInfo('/cal/', 'Work', false)),
            new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-11-01', new DateTimeZone('UTC')),
        );

        $keys = array_keys($events[0]->toArray());

        self::assertNotContains('source', $keys);
        self::assertNotContains('provider', $keys);
        self::assertNotContains('kind', $keys);
        self::assertNotContains('editable', $keys, 'editable would duplicate readonly as a second source of truth');
    }
}
