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

        'apply_now_requires_due_termination' => '"Apply now" only works with a termination date of today or earlier, '
            .'and the last day (:terminated_on) has not arrived yet (today is :today). Clear it to schedule the '
            .'offboarding, or set today\'s date.',

        'offboarding_not_scheduled' => 'This person has no scheduled offboarding.',

        'offboarding_due' => 'The termination date (:terminated_on) has already passed (today is :today). The '
            .'offboarding already applies and cannot be cancelled.',

        'record_minimised' => 'This person\'s record was minimised on :anonymized_on when its retention period '
            .'ended: it no longer accepts contracts or absences.',
    ],

];
