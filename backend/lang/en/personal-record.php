<?php

declare(strict_types=1);

/*
 * English version of the employee's own timesheet export (RF-ID-05, RL-05,
 * task 1.11). See the Spanish file for why these strings live here.
 *
 * The row prefixes (`TRAMO`, `CORRECCION`), the correction reason codes and the
 * clocking sources stay untranslated on purpose: they are a closed catalogue
 * that has to read the same here, in the legal export and in the database.
 */

return [

    'title' => 'My time record',

    'header' => [
        'period' => 'Period',
        'time_zone' => 'Site time zone',
        'legal_basis' => 'Legal basis',
        'criteria' => 'Inclusion criteria',
    ],

    'period_value' => 'from :from to :to, by work date',

    'legal_basis_value' => 'Daily working time record. Article 34.9 of the Spanish Workers\' Statute.',

    'criteria' => [
        'entries' => 'One TRAMO row per work period whose work date falls within the exported range.',
        'night' => 'A night shift is a single entry, attributed to the work date it started on. It is never split at midnight.',
        'open' => 'A shift that is still open shows no clock-out time and contributes zero minutes to the day total.',
        'corrections' => 'One CORRECCION row per correction to the record, with its author, its timestamp and its reason. Nothing has been deleted.',
        'times' => 'Local times are expressed in the time zone of the site shown on each row. The stored UTC timestamp is included as well.',
        'durations' => 'Durations are expressed as HH:MM. Never as a decimal figure.',
        'file' => 'CSV file in UTF-8 with a byte order mark, semicolon separated.',
    ],

    'columns' => [
        'record_type' => 'Type',
        'work_date' => 'Work date',
        'time_zone' => 'Time zone',
        'local_in' => 'Clock in (local time)',
        'local_out' => 'Clock out (local time)',
        'duration' => 'Duration',
        'day_total' => 'Day total',
        'status' => 'Status',
        'clock_in_source' => 'Clock-in source',
        'clock_out_source' => 'Clock-out source',
        'utc_in' => 'Clock in (UTC)',
        'utc_out' => 'Clock out (UTC)',
        'correction_local_at' => 'Correction (local time)',
        'correction_author' => 'Corrected by',
        'correction_action' => 'Action',
        'correction_reason' => 'Reason',
        'correction_explanation' => 'Explanation',
    ],

    'status' => [
        'open' => 'Shift open',
        'closed' => 'Closed',
        'anomalous' => 'Pending review',
        'none' => 'No entries recorded',
    ],

];
