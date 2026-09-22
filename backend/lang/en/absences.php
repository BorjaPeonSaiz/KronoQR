<?php

declare(strict_types=1);

/*
 * Absence texts (RF-GP-04, task 3.10).
 *
 * READ BY WHOEVER IS LOADING A HOLIDAY ROTA, usually with the spreadsheet open
 * next to them. That is why every message says **what to do with that row**, not
 * just what is wrong with it: "invalid format" forces guessing, and guessing
 * forty times is what turns a file load into a support call.
 *
 * NONE OF THEM NAMES THE CELL VALUE, the employee code or the note. Whoever
 * reads the report has the file in front of them and the line number; repeating
 * the content here would put personal data —and, for sick leave, health data— in
 * a text that may end up pasted into an email (hard rule 21).
 */

return [

    'import' => [

        'messages' => [

            'missing_employee_code' => 'The employee code is missing. It is the column that says whose '
                .'absence this is: you will find it on the person record and on their card.',

            'unknown_employee' => 'No person has that employee code. Check it on their record; matching '
                .'by name is not done, because two people can share a name and the absence would end up '
                .'on the wrong person record.',

            'missing_type' => 'The absence type is missing. Write vacation, sick leave, leave or other.',

            'unknown_type' => 'That absence type does not exist. The accepted ones are vacation, sick '
                .'leave, leave and other; they are compared ignoring case and accents.',

            'missing_starts_on' => 'The first day of the absence is missing.',
            'missing_ends_on' => 'The last day of the absence is missing. For a single day, repeat the '
                .'same date in both columns.',

            'invalid_starts_on' => 'The first day cannot be understood. Write it as 2026-03-15 or as '
                .'15/03/2026. Month/day/year is not accepted: 03/04/2026 always reads as 3 April.',

            'invalid_ends_on' => 'The last day cannot be understood. Write it as 2026-03-15 or as '
                .'15/03/2026. Month/day/year is not accepted: 03/04/2026 always reads as 3 April.',

            'inverted_period' => 'The absence would end before it starts. Check both dates: they are '
                .'whole days and both ends count.',

            'note_required' => 'The "other" type needs a note explaining it; without one the absence '
                .'says nothing in the report. Do not write a medical diagnosis.',

            'note_too_long' => 'The note is over 500 characters and does not fit. Shorten it: a note is '
                .'a brief clarification, not a report. And remember it must not carry a medical diagnosis.',

            'overlapping_absence' => 'That person already has an absence recorded on some of those days. '
                .'Check their history on the absences screen: if the recorded one is wrong, correct or '
                .'void it there, which is where it is recorded who did it and why.',

            'duplicate_in_file' => 'This absence overlaps an earlier row of the same file. The first one '
                .'is loaded and this one is discarded: delete the repeat or merge the two rows.',

            'unknown_column' => 'Column ":column" is not used. If you expected it to be, check its name: '
                .'the names the system recognises are in the configuration guide, and you can add your '
                .'own without touching the program.',
        ],
    ],
];
