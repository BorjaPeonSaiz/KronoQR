<?php

declare(strict_types=1);

/*
 * English texts of the legal export (RF-IN-05, RL-06, task 1.17).
 *
 * The Spanish file carries the reasoning; this one mirrors it key by key. Two
 * things stay untranslated on purpose, here as well: the nine correction reason
 * codes of the doc 01 Annex C —a closed catalogue whose value must read the same
 * in this file, in `shift_corrections` and in `audit_log`— and every piece of
 * data (codes, UUIDs, personal names).
 *
 * The legal basis line is deliberately still the Spanish statute: an
 * installation running in English is an English-speaking manager of a Spanish
 * workplace, not a different legal framework. When a second framework exists it
 * will come from the compliance profile (RN-10..12), not from a language file.
 */

return [

    'title' => 'Working time record — standard export for the Labour Inspectorate',

    'header' => [
        'installation' => 'Installation',
        'generated_at' => 'Generated (UTC)',
        'period' => 'Period',
        'scope' => 'Scope',
        'legal_basis' => 'Legal basis',
        'criteria' => 'Inclusion criteria',
    ],

    'period_value' => 'from :from to :to, by work date',

    'scope' => [
        'everyone' => 'Every worker with a record in the period',
        'employee' => 'A single worker (:uuid)',
    ],

    'legal_basis_value' => 'Daily working time record. Article 34.9 of the Spanish Workers\' Statute.',

    'criteria' => [
        'entries' => 'One SHIFT row per period of work whose work date falls inside the exported period. A night shift is a single entry, attributed to the day it started on.',
        'voided' => 'Voided entries are included, with their status shown, and do not add hours to the daily total.',
        'superseded' => 'Versions replaced by a correction do not appear as entries: what they said is in the "Before" column of the correction that replaced them. Nothing has been deleted.',
        'corrections' => 'One CORRECTION row per amendment to the record, with its author, its moment and its reason.',
        'times' => 'Local times are expressed in the time zone of the site shown on each row. The stored UTC instant is included as well.',
        'durations' => 'Durations are written as HH:MM. Never as a decimal.',
        'file' => 'CSV file in UTF-8 with byte order mark, semicolon separated.',
    ],

    'columns' => [
        'record_type' => 'Type',
        'employee_code' => 'Employee code',
        'employee_name' => 'Worker',
        'employee_uuid' => 'Worker identifier',
        'site' => 'Site',
        'department' => 'Department',
        'timezone' => 'Site time zone',
        'work_date' => 'Work date',
        'entry_number' => 'Entry',
        'shift_entry_id' => 'Entry identifier',
        'local_in' => 'Clock in (local time)',
        'local_out' => 'Clock out (local time)',
        'duration' => 'Duration (HH:MM)',
        'day_total' => 'Daily total (HH:MM)',
        'status' => 'Status',
        'clock_in_source' => 'Clock in source',
        'clock_out_source' => 'Clock out source',
        'utc_in' => 'Clock in (UTC)',
        'utc_out' => 'Clock out (UTC)',
        'correction_local_at' => 'Correction: moment (local time)',
        'correction_utc_at' => 'Correction: moment (UTC)',
        'correction_author' => 'Correction: author',
        'correction_author_id' => 'Correction: author identifier',
        'correction_action' => 'Correction: action',
        'correction_reason' => 'Correction: reason',
        'correction_explanation' => 'Correction: explanation',
        'correction_before' => 'Correction: before',
        'correction_after' => 'Correction: after',
    ],

    'record_type' => [
        'shift_entry' => 'SHIFT',
        'correction' => 'CORRECTION',
    ],

    'status' => [
        'open' => 'Open',
        'closed' => 'Closed',
        'anomalous' => 'Awaiting review',
        'voided' => 'Voided',
    ],

    'source' => [
        'qr_kiosk' => 'Card scan at kiosk',
        'pin_kiosk' => 'PIN at kiosk',
        'manual_admin' => 'Manual management entry',
        'import' => 'Import',
    ],

    'action' => [
        'created' => 'Entry created',
        'modified' => 'Times changed',
        'closed' => 'Entry closed',
        'voided' => 'Entry voided',
    ],

];
