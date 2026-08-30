<?php

declare(strict_types=1);

/*
 * Period reports (RF-IN-01, RF-IN-02, RF-IN-03, task 2.8).
 *
 * See `lang/es/reports.php` for the reasoning: the inclusion criteria are part
 * of the report, not of its documentation. Keys are decided by
 * `GeneratePeriodReport` and translated by the `Resource`; task 2.9 will write
 * the same list into the CSV and PDF headers.
 */

return [

    'criteria' => [

        'source' => 'Totals come from the consolidated attendance record (the daily projection); they are not recomputed for this report.',

        'work_date' => 'Each shift is attributed in full to the working day it started on, in the site time zone: a 22:00 to 06:00 shift counts on the day it began and is not split at midnight.',

        'voided' => 'Voided entries and versions superseded by a correction are not counted: only the current version of each shift entry.',

        'incidents' => 'An unresolved incident does not deduct hours. Days with an incident are counted separately, in their own column, so that they get reviewed.',

        'empty_days' => 'Days without activity are shown as zero and never omitted. In department or site aggregates, the day counters are person-days.',

        'contracted' => 'Contracted hours for the period are prorated per calendar day of contract validity: days in force × weekly hours ÷ 7. Days with no contract in force add nothing and are reported separately.',

        'scope' => 'The report only includes people within the requester scope, including anyone who left during the period.',

        'open_shifts_excluded' => 'Days with a shift still open contribute no minutes, because the working day has not finished. They count as days with activity and are reported separately.',

        'open_shifts_included' => 'Days with a shift still open contribute the minutes already closed; the shift in progress adds nothing until it is closed.',

        'iso_week' => 'Weeks start on Monday (ISO 8601) and are clipped to the requested range.',
    ],
];
