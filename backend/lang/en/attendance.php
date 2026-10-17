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
        'work_date_outside_employment' => 'This person has been offboarded since :terminated_on: hours can only '
            .'be recorded for working days between their start date (:hired_on) and their termination date '
            .'(:terminated_on).',
        'entry_does_not_continue_work_day' => 'The hours of this shift belong to another working day: it starts on '
            .'another day and does not continue the working day of :work_date, because :hours h or more (the minimum '
            .'rest between working days) pass between the previous clock-out and its clock-in, or a shift of another '
            .'working day lies in between. Record it on the working day it starts.',
        'next_entry_would_not_continue_work_day' => 'The next shift would no longer continue the working day of '
            .':work_date: it starts on another day and its clock-in would be :hours h or more after the previous '
            .'clock-out (the minimum rest between working days) or behind a shift of another working day. Correct or '
            .'void that shift first.',
        'void_would_change_work_date' => 'This shift opens the working day of :work_date and the next one starts on '
            .'another day: voiding it would move their hours to another working day. Void the later shifts first.',
        'mark_after_termination' => 'This person left on :terminated_on: that time falls outside their last working '
            .'day. Only hours that continue the working day of the termination date are accepted.',
    ],

];
