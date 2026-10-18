<?php

declare(strict_types=1);

/*
 * Texts for the work site: the `409` for a time zone already locked and the
 * `workforce:correct-site-timezone` command (RN-23, ADR-056).
 *
 * The command is run on the server by the hotel's IT person, usually at the
 * request of HR. Each sentence says what happens and what does not: what is at
 * stake is how the whole staff's work record is presented.
 */

return [

    'errors' => [
        'timezone_locked' => 'There are clock-ins recorded with :timezone; the time zone cannot be changed through the API.',
    ],

    'correct_timezone' => [
        'invalid_timezone' => 'The time zone «:value» does not exist. Use an IANA identifier such as Europe/Madrid. Nothing has been changed.',
        'no_site' => 'The installation has no site yet: the time zone is chosen in the setup wizard. Nothing has been changed.',
        'same_timezone' => 'The site already has the time zone :timezone. There is nothing to correct and nothing has been changed.',
        'no_work_record' => 'There are no clock-ins yet: change the time zone from the panel, in Departments, Site section. Nothing has been changed.',
        'missing_options' => 'To correct the time zone, --reason and --responsible are required. Nothing has been changed.',
        'reason_too_short' => 'The reason must explain the setup mistake: at least :min characters.',
        'reason_too_long' => 'The reason accepts at most :max characters.',
        'responsible_inactive' => 'The --responsible account is not an active management account with the admin or rrhh role: it may have been deactivated or changed role while confirming. The correction is signed by someone who can change the site from the panel. Nothing has been changed.',
        'summary_current' => 'Current site time zone: :timezone',
        'summary_new' => 'Corrected time zone:    :timezone',
        'summary_entries' => 'Recorded clock-ins:     :count shift entry(ies), from work day :first to :last',
        'warning' => 'No work day is moved and nothing is recalculated: each shift entry keeps the day it was assigned to. '
            .'The whole record, including what was already clocked, will be shown and exported with the corrected time zone. '
            .'If a shift entry near midnight should belong to another work day, void it and add it to the right one, each step with its reason.',
        'confirm' => 'Correct the site time zone?',
        'needs_force' => 'Without a terminal there is no way to confirm: repeat the command with --force once you have reviewed the above. Nothing has been changed.',
        'done' => 'Time zone corrected from :previous to :timezone. It is recorded in the audit log (site.updated) with the reason and the responsible account.',
    ],

];
