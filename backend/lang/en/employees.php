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

        'offboarding_due_reschedule' => 'The termination date (:terminated_on) has already passed (today is :today) '
            .'and can no longer be changed. With that same date, the offboarding is applied at once.',

        'record_minimised' => 'This person\'s record was minimised on :anonymized_on when its retention period '
            .'ended: it no longer accepts contracts, absences, rehiring nor termination date corrections. Someone '
            .'who comes back is hired as a new person.',
        'employee_not_terminated' => 'This person has not left and cannot be rehired. If they have a scheduled '
            .'offboarding, cancel it or wait for it to be applied.',

        'offboarding_not_effective' => 'This period has no effective offboarding. A scheduled offboarding is moved '
            .'from the offboarding itself.',

        'rehire_not_after_termination' => 'The hiring date (:hired_on) must be after the termination date '
            .'(:terminated_on).',

        'rehire_not_found' => 'This person does not exist.',

        'correction_after_today' => 'The termination date (:terminated_on) cannot be after today (:today).',

        'correction_before_hiring' => 'The termination date (:terminated_on) cannot be before the hiring date of the '
            .'period (:hired_on).',

        'correction_reaches_next_period' => 'The termination date (:terminated_on) must be before the hiring date of '
            .'the next period (:next_hired_on).',

        'correction_unchanged' => 'The termination date is already :terminated_on.',

    ],

];
