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
        'entry_does_not_continue_work_day' => 'Those hours do not belong to the working day of :work_date: the shift '
            .'starts on another day and :minutes minutes or more pass between the previous clock-out and its '
            .'clock-in, the minimum rest between working days. Record them on the working day they start.',
        'mark_after_termination' => 'This person left on :terminated_on: that time falls outside their last working '
            .'day. Only hours that continue the working day of the termination date are accepted.',
    ],

];
