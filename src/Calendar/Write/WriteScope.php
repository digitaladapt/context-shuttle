<?php

declare(strict_types=1);

namespace App\Calendar\Write;

/**
 * How much of an event a change applies to.
 *
 * Derived from the id, never chosen: a composite id (`UID::occurrence`) names
 * one occurrence, a plain UID names the series. It exists as a type so the
 * distinction is explicit *inside* the writer, where the two paths diverge
 * completely — an occurrence edit rewrites one component, a series edit
 * replaces the master — and so a mismatch between what the id said and what
 * the code assumed is impossible to express.
 */
enum WriteScope
{
    /** One occurrence: an override for an edit, an `EXDATE` for a delete. */
    case Occurrence;

    /** The whole recurring event, master and all. */
    case Series;
}
