<?php

declare(strict_types=1);

namespace App\Alerts;

/**
 * Outcome of attempting to record an answer on a pending request.
 *
 * The caller (the /ask form) maps these to page states, and tests pin the
 * distinction — an expired request must not accept an answer, and an
 * already-answered one must not be overwritten (single-use).
 */
enum AnswerResult
{
    case Answered;
    case AlreadyAnswered;
    case Expired;
    case NotFound;
}
