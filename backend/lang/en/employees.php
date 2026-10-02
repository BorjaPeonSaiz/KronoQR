<?php

declare(strict_types=1);

/*
 * Employee record texts shown to a panel user (RF-GP-03). See
 * `lang/es/employees.php` for the reasoning: each message says which date is
 * accepted, and none of them names the person (hard rule 21).
 */

return [

    'errors' => [

        'termination_before_hiring' => 'The termination date (:terminated_on) is earlier than the start date '
            .'(:hired_on).',

        'termination_after_today' => 'The termination date (:terminated_on) is later than today (:today). '
            .'Offboarding takes effect as soon as it is recorded; record it on the last day, once the shift is over.',

        'not_started_termination_must_be_hire_date' => 'This person has not started working yet (start date '
            .':hired_on). The only possible termination date is the start date, :hired_on.',
    ],

];
