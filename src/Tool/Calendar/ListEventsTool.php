<?php

declare(strict_types=1);

namespace App\Tool\Calendar;

use App\Calendar\CalendarReader;
use App\Calendar\Domain\CalendarEvent;
use App\Calendar\Domain\CalendarProblem;
use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Paging\Cursor;
use App\Calendar\Paging\Page;
use InvalidArgumentException;

/**
 * `calendar_list_events` — one calendar's worth of context, in the
 * deployment's timezone.
 *
 * Everything a caller needs is on each row: recurrence is already expanded,
 * so an occurrence is addressed by its own `id`, and `readonly` rides along
 * flat so a single row answers "can this be edited?" without a second look.
 *
 * This class is the adapter between the tool contract (dates in, shaped
 * array out) and the reader, which works in instants. The inclusive `to`
 * date the caller passes becomes an exclusive instant here, in the
 * deployment's timezone, so the caller never has to reason about an offset.
 */
final readonly class ListEventsTool
{
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(
        private CalendarReader $reader,
        private TimeZoneRule $timeZone,
    ) {
    }

    /**
     * List calendar events occurring within an inclusive date range.
     *
     * @param string      $from     start date, `YYYY-MM-DD` (inclusive), in the deployment's timezone
     * @param string      $to       end date, `YYYY-MM-DD` (inclusive, >= `from`)
     * @param string|null $calendar restrict to one calendar by href or display name
     * @param string|null $search   case-insensitive substring over summary, description and location
     * @param int|null    $limit    page size, 1-200 (default 50)
     * @param string|null $cursor   opaque token from a previous call's `next_cursor`
     *
     * @return array<string, mixed>
     */
    public function listEvents(
        string $from,
        string $to,
        ?string $calendar = null,
        ?string $search = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): array {
        [$from, $to] = $this->validateDates($from, $to);
        $limit = $this->validateLimit($limit);
        $parsedCursor = $this->parseCursor($cursor);

        $result = $this->reader->listEvents(
            from: $this->timeZone->startOfDay($from),
            to: $this->timeZone->endOfDayExclusive($to),
            calendar: $calendar,
            search: $search,
            cursor: $parsedCursor,
            limit: $limit,
        );

        /** @var Page $page */
        $page = $result['page'];

        $payload = [
            'events' => array_map(
                static fn (CalendarEvent $event): array => $event->toArray(),
                $page->events,
            ),
            'count' => \count($page->events),
            'has_more' => $page->hasMore,
            'timezone' => $this->timeZone->name(),
        ];

        if (null !== $page->nextCursor) {
            $payload['next_cursor'] = $page->nextCursor->encode();
        }

        $errors = $this->describeProblems($result['problems']);

        if (null !== $errors) {
            $payload['errors'] = $errors;
        }

        return $payload;
    }

    /**
     * One human-readable sentence, never a count the caller has to act on.
     *
     * A listing that hit bad data still succeeds: "what's on tomorrow?" is
     * far better served by four events plus a note than by an error, and the
     * UID-level detail is in the log where an operator can reach it.
     *
     * @param list<CalendarProblem> $problems
     */
    private function describeProblems(array $problems): ?string
    {
        $count = \count($problems);

        if (0 === $count) {
            return null;
        }

        return \sprintf('There were %d malformed events', $count);
    }

    /**
     * Strict calendar check: the format already matches `YYYY-MM-DD`, so
     * verify the day exists in that month rather than letting a lenient
     * parse roll `2026-02-30` into March.
     *
     * @return array{0: string, 1: string}
     */
    private function validateDates(string $from, string $to): array
    {
        $from = trim($from);
        $to = trim($to);

        if (1 !== preg_match(self::DATE_PATTERN, $from) || 1 !== preg_match(self::DATE_PATTERN, $to)) {
            throw new InvalidArgumentException('Dates must be in YYYY-MM-DD format (e.g. 2026-10-09).');
        }

        if (!$this->isRealCalendarDate($from) || !$this->isRealCalendarDate($to)) {
            throw new InvalidArgumentException('Dates must be valid calendar dates (e.g. 2026-10-09; 2026-02-30 is not).');
        }

        if ($to < $from) {
            throw new InvalidArgumentException("'to' date must not be before 'from' date.");
        }

        return [$from, $to];
    }

    private function isRealCalendarDate(string $date): bool
    {
        [$year, $month, $day] = explode('-', $date);

        return checkdate((int) $month, (int) $day, (int) $year);
    }

    private function validateLimit(?int $limit): int
    {
        if (null === $limit) {
            return self::DEFAULT_LIMIT;
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(\sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }

        return $limit;
    }

    /**
     * An unparseable cursor is an error naming the parameter, not a silent
     * restart: quietly re-running from the beginning would look like success
     * while handing back duplicates.
     */
    private function parseCursor(?string $cursor): ?Cursor
    {
        if (null === $cursor || '' === trim($cursor)) {
            return null;
        }

        return Cursor::decode(trim($cursor));
    }
}
