<?php

declare(strict_types=1);

namespace App\Calendar\Domain;

/**
 * The component type a calendar object carries.
 *
 * An enum rather than a string so that passing the wrong thing is a type
 * error rather than a runtime surprise.
 */
enum ComponentType: string
{
    case Event = 'VEVENT';
    case Task = 'VTODO';
}
