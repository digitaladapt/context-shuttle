<?php

declare(strict_types=1);

namespace App\Tool\Calendar;

use App\Calendar\Write\EventWriter;
use App\Calendar\Write\WriteScope;
use InvalidArgumentException;

/**
 * `calendar_delete_event` — remove an event, or one occurrence of it.
 *
 * **The id decides the scope**, the same way it does for an update. A
 * composite id removes one occurrence by excluding it from the series (the
 * other occurrences stay); a plain UID removes the whole event. That
 * distinction is the difference between "cancel Thursday's standup" and "cancel
 * the standup", and it is read from the id rather than asked for, because a
 * caller who passes the wrong scope parameter gets a deletion they did not want
 * and there is no undo.
 *
 * Deleting one occurrence of something that does not repeat is refused with a
 * sentence pointing at the right call, rather than quietly deleting the whole
 * event to satisfy the request.
 */
final readonly class DeleteEventTool
{
    public function __construct(
        private EventWriter $writer,
    ) {
    }

    /**
     * Remove one event or occurrence.
     *
     * @param string $id an id from a listing (one occurrence), or a uid (the whole event)
     *
     * @return array<string, mixed>
     */
    public function deleteEvent(string $id): array
    {
        $id = trim($id);

        if ('' === $id) {
            throw new InvalidArgumentException('id must not be empty. Pass back an id from calendar_list_events, or a series uid.');
        }

        $scope = $this->writer->scopeOf($id);

        $uid = $this->writer->delete($id, $scope);

        return [
            'deleted' => true,
            'uid' => $uid,
            'scope' => WriteScope::Occurrence === $scope ? 'occurrence' : 'series',
            'message' => WriteScope::Occurrence === $scope
                ? 'Removed that one occurrence. The rest of the series still happens.'
                : 'Deleted the event, including every occurrence of it.',
        ];
    }
}
