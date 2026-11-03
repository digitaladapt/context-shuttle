<?php

declare(strict_types=1);

namespace App\Tool\Taskwarrior;

/**
 * `taskwarrior_sync_status` — report whether sync is configured and
 * when the last sync attempt happened, along with its result code.
 *
 * When `TASKCHAMPION_SYNC_URL` is empty, `configured` is false and the
 * other fields are null. When configured, the last sync attempt is always
 * from the most recent call to this tool (it does not persist across
 * requests, because the service runs statelessly per invocation).
 *
 * @return array{configured: bool, last_sync: string|null, last_status: int|null, last_error: string|null}
 */
final readonly class SyncStatusTool
{
    public function __construct(
        private TaskwarriorService $service,
    ) {
    }

    /**
     * @return array{configured: bool, last_sync: string|null, last_status: int|null, last_error: string|null}
     */
    public function syncStatus(): array
    {
        return $this->service->syncStatus();
    }
}
