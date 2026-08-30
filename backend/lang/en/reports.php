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

    /*
     * Exported file labels (RF-IN-04, task 2.9). See `lang/es/reports.php` for
     * the reasoning: CSV, XLSX and PDF share these strings so that two downloads
     * of the same report in different formats read the same.
     */

    'document' => [

        'title' => 'Hours by period report',

        'period' => 'Period',

        'granularity' => 'Granularity',

        'group_by' => 'Grouped by',

        'time_zone' => 'Site time zone',

        'generated_at' => 'Generated on',

        'issuer' => 'Issued by',

        'issuer_unknown' => 'Unidentifiable account',

        'rows' => 'Rows',

        'digest' => 'Content SHA-256 digest',

        'criteria' => 'Criteria for this report',

        'empty' => 'There are no rows in this period within the requester scope.',

        'contract_coverage' => 'There are :days person-days in the period with no recorded contract, affecting :employees person(s). Those days add no contracted hours: the deviation of those rows is incomplete.',

        'sheet_hours' => 'Hours',

        'sheet_criteria' => 'Criteria',
    ],

    'subject_kind' => [

        'employee' => 'Employee',

        'department' => 'Department',

        'site' => 'Site',
    ],

    'subject' => [

        'unassigned' => 'No department',
    ],

    'granularity' => [

        'day' => 'Day',

        'week' => 'Week',

        'month' => 'Month',

        'range' => 'Whole period',
    ],

    'columns' => [

        'subject_kind' => 'Type',

        'subject' => 'Subject',

        'employee_code' => 'Employee code',

        'employee_uuid' => 'Identifier',

        'department_id' => 'Department (id)',

        'period_from' => 'From',

        'period_to' => 'To',

        'worked' => 'Worked',

        'contracted' => 'Contracted',

        'deviation' => 'Deviation',

        'overtime' => 'Excess',

        'shift_count' => 'Shift entries',

        'days_in_period' => 'Days',

        'days_with_activity' => 'Days with activity',

        'days_without_activity' => 'Days without activity',

        'open_shift_days' => 'Days with an open shift',

        'incident_days' => 'Days with an incident',

        'days_without_contract' => 'Days without contract',
    ],
];
