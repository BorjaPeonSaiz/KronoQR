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

        /*
         * The three of RF-GP-04 (task 3.10, decision 7). THEY READ AS A SINGLE
         * PARAGRAPH, which is why they sit together: the first two say what has
         * been discounted from absenteeism and the third says what could not be.
         * Moving the third one elsewhere would leave "unexplained absence"
         * looking like a count of missed shifts, which it is not.
         */

        'absences' => 'Recorded absences (holiday, sick leave, leave of absence) count as justified days and not as absenteeism: they have their own column. When an absence falls on a public holiday, the day counts as an absence and is not counted twice.',

        'holidays' => 'There are :count public holiday(s) from the ":profile" compliance profile within the period. They do not count as absenteeism and have their own column.',

        'no_roster' => 'The product does not know the shift roster: it cannot tell which days each person was due to work. Weekly rest days therefore count as unexplained absence. Check that column against the shift schedule before using it.',
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

        /* RF-GP-04. See the `criteria` block above for the reasoning behind each. */

        'absence_days' => 'Absence days',

        'holiday_days' => 'Public holidays',

        'unjustified_absence_days' => 'Unexplained absence',
    ],

    /*
     * PAYROLL EXPORT (RF-IN-07, task 3.9). English counterpart of the `payroll`
     * block in `lang/es/reports.php`; see that file for the reasoning.
     */
    'payroll' => [

        'columns' => [

            'employee_code' => 'Employee code',

            'employee_uuid' => 'Identifier',

            'last_name' => 'Surname',

            'first_name' => 'First name',

            'full_name' => 'Full name',

            'department' => 'Department',

            'period_from' => 'From',

            'period_to' => 'To',

            'days_in_period' => 'Days in period',

            'days_with_activity' => 'Days with activity',

            'shift_count' => 'Shift entries',

            'worked_hours' => 'Hours worked',

            'contracted_hours' => 'Contracted hours',

            'deviation_hours' => 'Deviation',

            'overtime_hours' => 'Hours above contract',

            'absence_days' => 'Absence days',

            'holiday_days' => 'Public holidays',

            'unjustified_absence_days' => 'Unexplained absence',

            'days_without_contract' => 'Days without contract',

            'time_zone' => 'Time zone',
        ],
    ],

    /*
     * THE WEEKLY EMAIL SUMMARY (RF-PR-05, task 3.12).
     *
     * Written for the manager of a hotel department, not for whoever wrote this
     * code: it says what their team worked last week and what they had under
     * contract, and nothing else.
     *
     * THREE THINGS THIS TEXT DOES NOT DO, AND THEY ARE NOT STYLE:
     *
     *   · It never compares or ranks anyone. This product records working time;
     *     it does not rate anyone's work (doc 01 §12).
     *   · It never calls a positive deviation «overtime». Whether an hour is
     *     overtime is decided by the collective agreement, with offsets and
     *     reference periods the product does not model.
     *   · It never asks for a correction. Detecting is not correcting (RN-08),
     *     and a correction is made by a person, with a reason, in the panel.
     *
     * Durations arrive already formatted as `HH:MM` (`ReportedDuration`): never
     * decimal, which reads badly and depends on the reader's locale.
     */
    'weekly_summary' => [

        'subject' => 'KronoQR · summary for the week of :from to :to',

        'greeting' => 'Hello,',

        'intro' => 'Here is the summary for week :week (:from to :to) of :departments.',

        'people' => 'People within your scope this week: :count.',

        'line' => ':employee · worked :worked of :contracted contracted (:deviation) · :days day(s) with activity · :absences on leave · :holidays public holiday(s)',

        'more' => 'And :count more, available in the panel.',

        'totals' => 'Scope total: worked :worked of :contracted contracted (:deviation).',

        'incidents' => 'You have :count unresolved incident(s) in the tray.',

        'incidents_none' => 'You have no unresolved incidents.',

        'action' => 'You can see it day by day in the panel, under «Reports», selecting :from to :to.',

        'not_a_ranking' => 'The figures come from the consolidated attendance record. This summary does not rank or compare anyone, and the deviation is not an amount of overtime: that is for the collective agreement to determine.',

        'footer' => 'You get this email every Monday because your installation has the weekly summary switched on. It is sent once per week.',

        'no_department' => 'your scope',
    ],

    /*
     * THE IMPACT AND ADOPTION DASHBOARD (RF-IN-08, RNF-D-01, task 3.13).
     *
     * THE CRITERIA ARE PART OF THE DASHBOARD, NOT OF THE MANUAL, for the same
     * reason as in the period report: a percentage without its definition is a
     * number everyone reads their own way, and this dashboard is shown in
     * meetings where it is decided whether the system gets renewed.
     *
     * Every line is written for whoever runs the hotel, not for whoever wrote
     * this: it says WHAT was counted, not how it was implemented. And it also
     * says what the dashboard does NOT know, which is half of its honesty.
     */
    'adoption' => [

        'criteria' => [

            'workdays_complete' => 'A workday counts as fully recorded when all of its entries are closed. The denominator is workdays with at least one entry: whoever did not clock in is neither in the numerator nor in the denominator, so a day the hotel was closed does not drag the figure down. Voided entries and versions superseded by a correction do not count.',

            'origin' => 'The breakdown by origin counts accepted clockings only. A rejected scan —unknown card, invalid signature, bounce— is not a clocking and is not part of the breakdown, although it does count as a served attempt in availability.',

            'corrections' => 'Corrections are counted by the date they were made, not by the workday they correct, and compared against the accepted clockings of the same period. All three kinds count: manual entry, amendment and voiding. That way a closed month does not change when somebody amends an old day.',

            'availability' => 'Clocking availability is not server uptime: it measures whether the person was able to clock. The numerator holds every served clocking, including those rejected by a rule —the system was there and answered— and those that arrived through the offline queue. The denominator adds the attempts the tablet could not carry out and reported as errors: camera unavailable or not permitted, scanner failing to start, decoder failing to load, offline storage unusable and failed submission. It is an approximation that errs in favour of reliability: an attempt that never produced an error is invisible, and the periodic pruning of the error history shortens the denominator for older periods.',

            'offline' => 'Of the served clockings, those that arrived more than a minute after they actually happened: the ones that waited in the tablet queue. It is the part of availability that did not depend on the server.',

            'incidents' => 'Resolution time is measured only over open-shift incidents resolved within the period, from detection to closure. The median sits next to the mean on purpose: a single incident forgotten for three weeks moves the mean and not the median. Open incidents are today\'s snapshot, of any kind, and that is why they are not compared against the previous period.',

            'credentials' => 'People without a delivered card are active employees who today hold no current credential already handed over. A card printed and still in the drawer counts as not delivered. It is today\'s snapshot and is not compared against the previous period.',

            'hours' => 'Worked and contracted hours come from the consolidated attendance record, for the whole installation, with no breakdown by person or department. Days with a still-open shift contribute no minutes. Days without a current contract add no contracted hours.',

            'baseline' => 'The hours per month spent consolidating timesheets are declared by the customer: they describe the manual work that predates the system, and no program can measure what was done before it existed. If nothing was declared, the line is left empty instead of inventing an improvement.',

            'previous_period' => 'The previous period is the same number of days immediately before the first day of the requested period. When the previous period has nothing to compare against, the change is left empty rather than shown as zero: «there was no activity» and «there was activity and it went badly» are not the same thing.',

            'timezone' => 'Everything is measured in the site time zone. A 22:00 to 06:00 shift counts whole on the day it started and is not split at midnight, and weeks with a daylight-saving change do not distort any percentage.',

            'aggregate' => 'The dashboard covers the whole installation and carries no names and no person identifiers. There is no breakdown by department on purpose: in a one-person department, «worked hours» would be that person\'s individual data.',

            'dashboard' => 'The definitions are the same ones used by the technical monitoring dashboard of the installation, but the window is not: that one looks at the last few days and this one at a closed period, so the two figures may differ without either being wrong.',
        ],

        /*
         * Labels of the exported file. All three formats use these same texts,
         * for the same reason as the period report: whoever compares two
         * downloads of the same dashboard in different formats has to see the
         * same thing.
         */
        'document' => [

            'title' => 'Impact and adoption dashboard',

            'period' => 'Period',

            'previous_period' => 'Previous period',

            'time_zone' => 'Site time zone',

            'generated_at' => 'Generated on',

            'issuer' => 'Issued by',

            'issuer_unknown' => 'Unidentifiable account',

            'rows' => 'Indicators',

            'digest' => 'SHA-256 digest of the contents',

            'criteria' => 'Criteria for this dashboard',

            'sheet_indicators' => 'Indicators',

            'sheet_criteria' => 'Criteria',

            'empty' => 'No data',

            'origin_breakdown' => 'Clockings by origin',
        ],

        /* The columns of the indicator table, in order. */
        'columns' => [

            'indicator' => 'Indicator',

            'current' => 'Period',

            'previous' => 'Previous period',

            'delta' => 'Change',

            'target' => 'Target',

            'status' => 'Status',
        ],

        /* The columns of the breakdown by origin. */
        'origin_columns' => [

            'origin' => 'Origin',

            'scans' => 'Clockings',

            'share' => 'Share',
        ],

        /*
         * HOW A NUMBER IS WRITTEN IN THIS LANGUAGE. It lives here, and not in an
         * `if` inside the generator, so that adding a third language cannot
         * silently fall back to English separators: the generator demands the key
         * and fails by name when it is missing.
         *
         * The file is opened by a spreadsheet using the customer's regional
         * settings, so this is not cosmetic: `99.94` in a Spanish Excel reads as
         * ninety-nine thousand nine hundred and ninety-four.
         */
        'number' => [

            'decimal_separator' => '.',

            'thousands_separator' => ',',
        ],

        /*
         * The twelve indicators. The label says WHAT it measures, not what the
         * key is called: whoever reads the paper does not know `qr_scans_ratio`
         * exists.
         */
        'indicators' => [

            'workdays_complete_ratio' => 'Workdays fully recorded',

            'qr_scans_ratio' => 'Clockings by QR card',

            'manual_corrections_ratio' => 'Manual corrections over clockings',

            'clocking_availability_ratio' => 'Clocking availability',

            'offline_resolved_ratio' => 'Of those, resolved without the server',

            'incident_resolution_mean_minutes' => 'Mean time to resolve an open shift',

            'incident_resolution_median_minutes' => 'Median time to resolve an open shift',

            'open_incidents' => 'Incidents open today',

            'employees_without_credential' => 'People without a delivered card today',

            'worked_minutes' => 'Hours worked',

            'contracted_minutes' => 'Hours contracted',

            'baseline_manual_minutes_per_month' => 'Hours per month consolidating timesheets (declared)',
        ],

        /* The four clocking origins. */
        'origins' => [

            'qr_kiosk' => 'QR card at the kiosk',

            'pin_kiosk' => 'PIN at the kiosk',

            'manual_admin' => 'Manual correction',

            'import' => 'Import',
        ],

        /*
         * How the §1.3 target is phrased in the file. `reduction` says neither
         * «met» nor «not met» on purpose: the product cannot measure the work
         * that predates its installation.
         */
        'target' => [

            'at_least' => 'at least :value',

            'at_most' => 'less than :value',

            'reduction' => 'cut by :value % against the baseline',

            'none' => '—',
        ],

        /*
         * Whether the indicator meets its target. «No data» is not «not met»:
         * there is nothing to decide it with, and confusing the two is how an
         * honest dashboard turns into an alarmist one.
         */
        'status' => [

            'met' => 'On target',

            'not_met' => 'Off target',

            'unknown' => 'No data',

            'no_target' => 'No target',
        ],
    ],
];
