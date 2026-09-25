<?php

declare(strict_types=1);

namespace App\Tool\Calendar;

use App\Calendar\CalendarReader;
use App\Calendar\Domain\CalendarProblem;
use App\Calendar\Domain\CalendarTask;
use App\Calendar\Domain\TimeZoneRule;
use App\Calendar\Paging\Cursor;
use App\Calendar\Paging\Page;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * `calendar_list_tasks` — what's outstanding, in the deployment's timezone.
 *
 * Open tasks by default and no date range required, because "what do I still
 * have to do?" is the dominant question and a task with no deadline is a
 * normal thing to have. A caller reconstructing history asks for completed
 * tasks and a range.
 */
final readonly class ListTasksTool
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
     * List calendar tasks.
     *
     * @param bool        $include_completed include finished tasks (default false)
     * @param string|null $from              only tasks due on or after this date, `YYYY-MM-DD`
     * @param string|null $to                only tasks due on or before this date, `YYYY-MM-DD`
     *
     * @return array<string, mixed>
     */
    public function listTasks(
        bool $include_completed = false,
        ?string $calendar = null,
        ?string $search = null,
        ?string $from = null,
        ?string $to = null,
        ?int $limit = null,
        ?string $cursor = null,
    ): array {
        [$fromInstant, $toInstant] = $this->validateRange($from, $to);
        $limit = $this->validateLimit($limit);

        $result = $this->reader->listTasks(
            includeCompleted: $include_completed,
            calendar: $calendar,
            search: $search,
            dueFrom: $fromInstant,
            dueTo: $toInstant,
            cursor: $this->parseCursor($cursor),
            limit: $limit,
        );

        /** @var Page $page */
        $page = $result['page'];

        $payload = [
            'tasks' => array_map(
                static fn (CalendarTask $task): array => $task->toArray(),
                $page->tasks,
            ),
            'count' => \count($page->tasks),
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
     * @param list<CalendarProblem> $problems
     */
    private function describeProblems(array $problems): ?string
    {
        $count = \count($problems);

        if (0 === $count) {
            return null;
        }

        return \sprintf('There were %d malformed tasks', $count);
    }

    /**
     * An optional `from`/`to` pair, each date-only and inclusive.
     *
     * `to` becomes the last instant of its day, so a task due at 17:00 on the
     * named day is inside the range rather than excluded by it — the same
     * inclusive reading the event tool gives a date range.
     *
     * @return array{0: DateTimeImmutable|null, 1: DateTimeImmutable|null}
     */
    private function validateRange(?string $from, ?string $to): array
    {
        $from = null === $from ? null : trim($from);
        $to = null === $to ? null : trim($to);

        foreach ([$from, $to] as $value) {
            if (null === $value || '' === $value) {
                continue;
            }

            if (1 !== preg_match(self::DATE_PATTERN, $value)) {
                throw new InvalidArgumentException('Dates must be in YYYY-MM-DD format (e.g. 2026-10-09).');
            }

            [$year, $month, $day] = explode('-', $value);

            if (!checkdate((int) $month, (int) $day, (int) $year)) {
                throw new InvalidArgumentException('Dates must be valid calendar dates (e.g. 2026-10-09; 2026-02-30 is not).');
            }
        }

        if (null !== $from && null !== $to && $to < $from) {
            throw new InvalidArgumentException("'to' date must not be before 'from' date.");
        }

        return [
            null === $from || '' === $from ? null : $this->timeZone->startOfDay($from),
            null === $to || '' === $to ? null : $this->timeZone->endOfDayExclusive($to)->modify('-1 second'),
        ];
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

    private function parseCursor(?string $cursor): ?Cursor
    {
        if (null === $cursor || '' === trim($cursor)) {
            return null;
        }

        return Cursor::decode(trim($cursor));
    }
}
