<?php

declare(strict_types=1);

/*
 * Working-time record messages shown to a panel user (F1).
 *
 * Only those of manual entries and corrections: the kiosk never receives the
 * reason for a rejection (hard rule 17) and these messages never reach it.
 */

return [

    'errors' => [
        'mark_in_future' => 'That time has not happened yet: future hours cannot be recorded. '
            .'A margin of :minutes minute(s) over the server clock is allowed.',
        'work_date_in_future' => 'That working day has not started yet: hours cannot be recorded for a future day.',
    ],

];
