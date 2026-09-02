<?php

declare(strict_types=1);

/*
 * Staff import texts (RF-GP-05, task 5.5). See `lang/es/import.php` for the
 * reasoning: every message says what to do with that line, and none of them
 * quotes the value of the cell.
 */

return [

    'unreadable_file' => 'The file could not be read. Check that it is a CSV or an XLSX, that the first '
        .'row holds the column names, and that it is not empty. If you exported it from another program, '
        .'export it again as CSV and upload it once more.',

    'too_many_rows' => 'The file has more than :max rows and nothing was imported. Split it into smaller '
        .'files and import them one by one. If your staff list really is that large, raise the limit with '
        .'WORKFORCE_IMPORT_MAX_ROWS in the server .env file.',

    'messages' => [

        'missing_identity' => 'This row carries neither an identity document nor an email address, so '
            .'there would be no way to recognise it if you import the file again and it would be '
            .'duplicated. Add the document column (national ID or passport) or the email column.',

        'missing_first_name' => 'The first name is missing.',
        'missing_last_name' => 'The last name is missing.',

        'missing_hired_at' => 'The start date is missing. It is required: it marks from when work days '
            .'can be recorded and from when the retention period runs.',

        'invalid_email' => 'The email in this row is not shaped like an email. Fix it, or leave it '
            .'empty: email is optional and portal access uses employee code and PIN.',

        'invalid_hired_at' => 'The start date cannot be understood. Write it as 2026-03-15 or as '
            .'15/03/2026. Month/day/year is not accepted: 03/04/2026 always reads as 3 April.',

        'invalid_national_id' => 'The identity document is too short. Write it in full, including its '
            .'letter if it has one.',

        'unknown_department' => 'That department does not exist yet. Create it in the panel first, or '
            .'fix the cell; the comparison ignores case and accents.',

        'duplicate_in_file' => 'This person already appears in an earlier row of the same file. The '
            .'first one is imported and this one is discarded: delete the repeat or merge the two rows.',

        'email_taken' => 'That email already belongs to somebody else on the staff list. Check whose it '
            .'is before going on: either there is a typo, or these are two records for the same person.',

        'hired_at_not_updated' => 'A stored start date is NOT changed by an import, so the one in this '
            .'row is ignored. If it really has to change, do it on the person record: it moves the point '
            .'from which their record retention runs.',

        'unknown_column' => 'Column ":column" is not used. If you expected it to be, check its name: the '
            .'names the system recognises are in the configuration guide, and you can add your own '
            .'without touching the program.',
    ],
];
