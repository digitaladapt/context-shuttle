<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calendar;

use App\Calendar\CalendarWriteGate;
use App\Calendar\Domain\EditableCalendar;
use App\ToolRegistry\ToolDefinition;
use App\ToolRegistry\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Whether the write tools exist at all.
 *
 * This is the enforcement of the design's first rule: with no calendar named
 * for writing, the create/update/delete tools are **absent** from the tool
 * surface rather than present and refusing. The difference matters because a
 * model cannot attempt a mutation it cannot see, and because an operator can
 * verify the deployment's read-onlyness by looking at `tools/list` rather than
 * by reading a refusal message.
 *
 * The runtime half is the load-bearing part: an env-backed container parameter
 * is still the literal `%env(...)%` placeholder while the container is being
 * built, so a compile-time filter would report every unconfigured deployment
 * as writable. These tests exercise the registry the way a request does.
 *
 * @internal
 *
 * @covers \App\Calendar\CalendarWriteGate
 * @covers \App\ToolRegistry\ToolRegistry
 */
final class CalendarWriteGateTest extends TestCase
{
    private function definition(string $name, bool $requiresWrites): ToolDefinition
    {
        return new ToolDefinition(
            name: $name,
            description: 'd',
            handler: 'strlen',
            parameters: [],
            requiresWrites: $requiresWrites,
        );
    }

    /**
     * @return list<ToolDefinition>
     */
    private function mixedDefinitions(): array
    {
        return [
            $this->definition('calendar_list_events', false),
            $this->definition('calendar_create_event', true),
            $this->definition('calendar_update_event', true),
            $this->definition('calendar_delete_event', true),
        ];
    }

    public function test_write_tools_are_absent_when_no_calendar_is_named(): void
    {
        $registry = new ToolRegistry(
            $this->mixedDefinitions(),
            new CalendarWriteGate(new EditableCalendar('')),
        );

        $names = $registry->names();

        self::assertNotContains('calendar_create_event', $names);
        self::assertNotContains('calendar_update_event', $names);
        self::assertNotContains('calendar_delete_event', $names);

        // The reads are untouched: this narrows the surface, it does not
        // disable the deployment.
        self::assertContains('calendar_list_events', $names);
    }

    public function test_write_tools_are_present_once_a_calendar_is_named(): void
    {
        $registry = new ToolRegistry(
            $this->mixedDefinitions(),
            new CalendarWriteGate(new EditableCalendar('work')),
        );

        $names = $registry->names();

        self::assertContains('calendar_create_event', $names);
        self::assertContains('calendar_update_event', $names);
        self::assertContains('calendar_delete_event', $names);
    }

    public function test_a_whitespace_only_value_is_still_unconfigured(): void
    {
        // `CALDAV_EDITABLE_CALENDAR=" "` in a .env file is a plausible typo,
        // and treating it as "writes are on" would enable mutation on a
        // deployment whose operator set nothing meaningful.
        $registry = new ToolRegistry(
            $this->mixedDefinitions(),
            new CalendarWriteGate(new EditableCalendar('   ')),
        );

        self::assertNotContains('calendar_create_event', $registry->names());
    }

    /**
     * An unavailable tool must be indistinguishable from one that does not
     * exist.
     *
     * The REST surface answers "tool not found" with a list of what is
     * available, so a caller reaching for a write tool on a read-only
     * deployment learns the same thing as one that invented a name. Any other
     * answer would leak the existence of a tool the deployment deliberately
     * does not serve.
     */
    public function test_an_unavailable_tool_is_not_reachable_by_name(): void
    {
        $registry = new ToolRegistry(
            $this->mixedDefinitions(),
            new CalendarWriteGate(new EditableCalendar('')),
        );

        self::assertFalse($registry->has('calendar_create_event'));
        self::assertNull($registry->get('calendar_create_event'));
        self::assertSame(1, $registry->count());
    }

    public function test_every_shipped_write_tool_declares_the_requirement(): void
    {
        $definitions = (new \App\ToolRegistry\ToolLoader(__DIR__.'/../../../config/tools'))->load();

        $writes = array_values(array_filter(
            $definitions,
            static fn (ToolDefinition $definition): bool => str_starts_with($definition->name, 'calendar_')
                && (str_contains($definition->name, '_create_')
                    || str_contains($definition->name, '_update_')
                    || str_contains($definition->name, '_delete_')),
        ));

        self::assertNotSame([], $writes, 'the write tools should exist in config/tools');

        foreach ($writes as $definition) {
            self::assertTrue(
                $definition->requiresWrites,
                \sprintf('tool "%s" mutates, so it must declare requires_writes', $definition->name),
            );
        }

        // And the converse: a read tool must not, or enabling writes would
        // change whether a caller can read anything.
        foreach ($definitions as $definition) {
            if (str_starts_with($definition->name, 'calendar_list_') || str_starts_with($definition->name, 'calendar_get_')) {
                self::assertFalse(
                    $definition->requiresWrites,
                    \sprintf('tool "%s" only reads, so it must not require writes', $definition->name),
                );
            }
        }
    }

    /**
     * No write tool may accept a target calendar.
     *
     * Asserted against the shipped YAML rather than a fixture, because this is
     * the rule most likely to be relaxed by a well-meaning change: the read
     * tools all take a `calendar` filter, so copying one to make a write tool
     * would look consistent and would be the exact mistake the design forbids.
     */
    public function test_no_write_tool_declares_a_calendar_parameter(): void
    {
        $forbidden = ['calendar', 'calendar_href', 'calendar_name', 'calendars', 'target'];

        foreach (glob(__DIR__.'/../../../config/tools/calendar_*.yaml') ?: [] as $file) {
            $raw = Yaml::parseFile($file);
            self::assertIsArray($raw);

            if (!($raw['requires_writes'] ?? false)) {
                continue;
            }

            $parameters = array_keys($raw['parameters'] ?? []);

            foreach ($forbidden as $name) {
                self::assertNotContains(
                    $name,
                    $parameters,
                    \sprintf('tool "%s" (%s) must not let a caller choose the target calendar', $raw['name'] ?? '?', basename($file)),
                );
            }
        }
    }
}
