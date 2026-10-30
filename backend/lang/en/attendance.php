<?php

declare(strict_types=1);

/*
 * Working-time record messages shown to a panel user.
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
        'work_date_after_termination' => 'This person has a scheduled offboarding with termination date '
            .':terminated_on: hours cannot be recorded for working days after that date.',
        'work_date_between_periods' => 'This person was away between their termination on :terminated_on and '
            .'their return on :rehired_on: hours cannot be recorded for those days.',
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
        'entry_not_after_break' => 'Hours starting on another day only continue the working day of :work_date if '
            .'the previous shift ended in a break, and it did not: they belong to another working day. Record them on '
            .'the working day they start, or declare that the previous shift ended in a break.',
        'entry_beyond_next_day' => 'The working day of :work_date only continues until :next_day at the latest: a '
            .'shift starting later belongs to another working day. Record it on the working day it starts.',
        'break_without_clock_out' => 'Only a shift with a clock-out time can end in a break.',
        'mark_after_termination' => 'This person left on :terminated_on: that time falls outside their last working '
            .'day. Only hours that continue the working day of the termination date are accepted.',
    ],

];
