<?php

declare(strict_types=1);

namespace App\Alerts;

/**
 * What kind of answer an interactive request expects.
 *
 * `text` is the free-text side (ask_user): the form renders a textarea and
 * the stored answer is a string. `confirm` is the yes/no side
 * (ask_user_confirm): the form renders two buttons and the stored answer
 * is a bool.
 */
enum RequestType: string
{
    case Text = 'text';
    case Confirm = 'confirm';
}
