<?php

declare(strict_types=1);

namespace App\Tool\Taskwarrior;

/**
 * `taskwarrior_project_list` — list all projects known to taskwarrior.
 *
 * Returns a flat list of project names. Empty when no project has ever
 * been used. Projects are created on-the-fly by `taskwarrior_create_task`
 * and `taskwarrior_update_task`; this tool only lists them.
 *
 * @return list<string>
 */
final readonly class ProjectListTool
{
    public function __construct(
        private TaskwarriorService $service,
    ) {
    }

    /**
     * @return list<string>
     */
    public function projectList(): array
    {
        return $this->service->projectList();
    }
}
