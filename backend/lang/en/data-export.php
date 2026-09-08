<?php

declare(strict_types=1);

/*
 * The `README.md` shipped INSIDE the full data export ZIP (RF-PD-14, RL-20,
 * task 5.10).
 *
 * Same rules as the Spanish file, which is the reference: every file and every
 * column explained, nothing about the product taken for granted, complete
 * copy-pasteable commands, and no promise the product does not keep. See
 * `lang/es/data-export.php` for the reasoning behind each section.
 */

return [

    'title' => 'Full data export from KronoQR',

    'intro' => <<<'TEXT'
        This archive contains **all** the data in your KronoQR installation, in open
        formats: one CSV per table, JSON for configuration, and this document.

        It is yours and it depends on nothing. You can open it with any spreadsheet, load it
        into any database and keep it for as long as the law requires, even if you stop
        using the product. You do not need KronoQR to read it, nor a licence, nor a
        connection to anyone.

        **It contains personal data about your whole workforce.** Store it where you store
        the rest of your employment records and delete it when you no longer need it: you
        are the data controller.
        TEXT,

    'generated_heading' => 'About this export',

    'generated' => <<<'TEXT'
        - **Generated:** :generated_at (UTC)
        - **Product version:** :product_version
        - **Export format version:** :schema_version
        - **Site time zone:** :timezone
        - **Requested from:** :requested_via
        - **Requested by:** :requested_by
        - **Total data rows:** :total_rows
        TEXT,

    'requested_via_panel' => 'the management panel',
    'requested_via_console' => 'the server console (`php artisan product:export-all`)',
    'requested_by_nobody' => 'nobody with an open session: it was requested from the server console',

    'timezone_heading' => 'Timestamps are in UTC',

    'timezone_body' => <<<'TEXT'
        **Every** date-and-time column in this archive is in UTC, ISO-8601 with microseconds:
        `2026-09-08T10:15:00.123456Z`. The trailing `Z` means Coordinated Universal Time, not
        the hotel's local time.

        Your site is in the **:timezone** zone. Reading a timestamp in local time requires a
        conversion, and the conversion depends on the date, because of daylight saving time.

        This is deliberate. It is the only way for a night shift that starts on Saturday and
        ends on Sunday — or the night the clock goes back and 02:30 happens twice — to have
        an unambiguous value. A local timestamp does not.

        Conversion examples, with the file already open:

        - **In a spreadsheet:** if `2026-09-08T10:15:00.123456Z` is in `A2`, local time at
          UTC+2 is `=DATEVALUE(MID(A2,1,10))+TIMEVALUE(MID(A2,12,8))+2/24`.
        - **In PostgreSQL:** `SELECT '2026-09-08T10:15:00Z'::timestamptz AT TIME ZONE ':timezone';`
        - **On the command line:** `TZ=':timezone' date -d '2026-09-08T10:15:00Z'`

        Date-only columns (`work_date`, `hired_at`, `valid_from`) are not instants and are not
        converted: they are the day a shift or a contract is attributed to, already in the
        site's calendar.
        TEXT,

    'integrity_heading' => 'Checking the export is complete',

    'integrity_body' => <<<'TEXT'
        `manifest.json` records, for every file, how many data rows it holds and its SHA-256
        digest. That is enough to prove nothing was lost or altered, without opening the
        files and without KronoQR.

        From the directory where you unzipped the archive:

        ```
        sha256sum employees.csv shift_entries.csv audit_log.csv
        ```

        and compare the digests with the ones in `manifest.json`. If they match, the file is
        byte for byte the one that left the server.

        The digest of the whole ZIP is returned when you download it (header
        `X-Kronoqr-Export-Sha256`) and is recorded in the panel and in the audit trail. To
        check it:

        ```
        sha256sum kronoqr-export-*.zip
        ```

        **`manifest.json` does not list itself**: it cannot, writing its own digest inside
        would change it.

        The counts in `manifest.json` are **data** rows: they do not include the first line of
        each CSV, which holds the column names.
        TEXT,

    'format_heading' => 'File formats',

    'format_body' => <<<'TEXT'
        - **CSV** — UTF-8 with a byte order mark (so that Excel shows accents correctly),
          `:delimiter` as the separator, RFC 4180 quoting with `"` and `CRLF` line endings.
          The first line holds the technical column names: they are deliberately not
          translated, so that a `COPY` or a `LOAD DATA` into another database keeps working
          even if you change the panel's language. What each column means is explained in
          this document.
        - **JSON** — always a list of objects, even when there is only one element. Scalar
          values are given as text; nested documents (`settings`, `holiday_calendar`,
          `features`, `value`) as real JSON.
        - **Empty cells** mean "no value" (`NULL` in the database), not zero and not an empty
          string.
        - **A cell starting with `=`, `+`, `-` or `@`** is prefixed with a single quote. That
          is Excel's text marker and stops a spreadsheet from treating human-written text as
          a formula.
        TEXT,

    'chain_heading' => 'Verifying the audit chain yourself',

    'chain_body' => <<<'TEXT'
        `audit_log.csv` is the record of everything legally relevant that happened in your
        installation: who corrected which shift, when, why, who signed in, who changed a
        threshold. Rows are only ever appended: never modified, never deleted.

        Every row carries its own digest (`hash`) and the previous row's (`prev_hash`), so
        they form a chain. If somebody altered a row, its digest would stop matching, and so
        would every row after it. **This can be checked outside KronoQR**, and here is the
        exact formula:

        ```
        hash = SHA256( prev_hash + RS + occurred_at + RS + actor + RS
                       + action + RS + subject + RS + canonical_payload + RS )
        ```

        where:

        - `RS` is the record separator byte, `0x1E`. It follows every component.
        - `occurred_at` is written as `2026-09-08T10:15:00.123456+00:00` (with the `+00:00`
          offset, not `Z`). The CSV column uses `Z`: replace it with `+00:00` before
          computing.
        - `actor` is `actor_type + "#" + actor_id`, with an empty `actor_id` when there is
          none.
        - `subject` is `subject_type + "#" + subject_id`, same rules.
        - `canonical_payload` is the `payload` JSON with **every object's keys sorted
          alphabetically byte by byte**, no whitespace, slashes and non-ASCII characters
          unescaped. An empty payload is `{}`.
        - The very first row of the installation uses the SHA-256 of the text
          `FICHAJE-HOTEL-GENESIS` as its `prev_hash`.

        `audit_chain_anchors.csv` records, per year, the first and last digest and the row
        count: enough to check a closed year without walking it.

        **`audit_log.csv` is the only file in this archive that carries internal identifiers**
        (`id`, `actor_id`, `subject_id`), and that is on purpose: those are the numbers that
        went into the digest, so replacing them would have produced a file nobody could
        verify. So that it can still be read, the `actor_uuid` column identifies the same
        actor the way every other file does.
        TEXT,

    'files_heading' => 'What is in each file',

    'file_heading' => ':file — :rows rows',

    'not_installed_heading' => 'What this version does not record',

    'not_installed_body' => <<<'TEXT'
        The following are **not present** in version :product_version of the product, which is
        why there is no file for them. They are not empty: this version does not record that
        information at all.

        :list
        TEXT,

    'not_installed_names' => [
        'absences' => 'Absences and leave.',
        'error_events' => 'Technical error history of the applications.',
    ],

    'unknown_column' => '(not described in this version; its technical name is ":column")',

    'files' => [

        'site' => [
            'summary' => 'The workplace. There is one per installation.',
            'columns' => [
                'name' => 'Site name, as shown in the panel and in reports.',
                'timezone' => 'Time zone used to interpret the site\'s hours. It is what decides which working day a clock-in belongs to.',
                'compliance_profile' => 'Compliance profile in force: the set of legal thresholds (minimum rest, maximum working day, retention) used to detect incidents.',
                'settings' => 'Historic site settings. Empty in current installations: configuration lives in `installation_settings.json`.',
                'created_at' => 'When the site was created.',
            ],
        ],

        'departments' => [
            'summary' => 'The site\'s departments. An employee belongs to at most one.',
            'columns' => [
                'name' => 'Department name. It is what the `department_name` column of `employees.csv` refers to.',
                'site_name' => 'Site it belongs to.',
                'manager_user_uuid' => 'Management account responsible for the department, if any. Matches `user_uuid` in `users.csv`.',
            ],
        ],

        'employees' => [
            'summary' => 'The workforce: everybody ever registered, including people who have already left. Nobody is deleted.',
            'columns' => [
                'employee_uuid' => 'Identifier for the person. It is what the shift, incident and credential files refer to.',
                'employee_code' => 'Employee code used to sign in to the personal portal.',
                'first_name' => 'First name.',
                'last_name' => 'Surname.',
                'email' => 'Email address. It is **optional** in this product: most rows may be empty, and that is normal.',
                'department_name' => 'Department. Empty if none.',
                'status' => 'Situation: `active`, `suspended` or `terminated`.',
                'hired_at' => 'Hire date.',
                'terminated_at' => 'Termination date. Empty if still employed.',
                'locale' => 'Language the person sees on the kiosk and the portal.',
                'pin_issued_at' => 'When their backup PIN was issued. Empty if never issued.',
                'pin_delivered_at' => 'When that PIN was handed over in person.',
                'pin_delivered_by_user_uuid' => 'Who handed it over.',
                'created_at' => 'When the record was created.',
                'updated_at' => 'Last change to the record.',
            ],
        ],

        'employment_contracts' => [
            'summary' => 'Contracted working hours, with their history: a change opens a new row and closes the previous one. It is the figure worked hours are measured against.',
            'columns' => [
                'employee_uuid' => 'Person it belongs to.',
                'weekly_hours' => 'Contracted weekly hours.',
                'annual_hours' => 'Annual hours, when the contract sets them.',
                'schedule_type' => 'Declared schedule type.',
                'valid_from' => 'In force from.',
                'valid_to' => 'In force until. Empty for the current contract.',
                'created_at' => 'When it was recorded.',
                'created_by_user_uuid' => 'Who recorded it.',
            ],
        ],

        'credentials' => [
            'summary' => 'QR cards issued, revoked ones included. **It carries no card secret**: without it, none of these rows can be used to clock in.',
            'columns' => [
                'credential_uuid' => 'Card identifier.',
                'employee_uuid' => 'Person it was issued to.',
                'key_id' => 'Signing key it was issued under. Useful to know which cards a key rotation affected.',
                'issued_at' => 'When it was issued.',
                'printed_at' => 'When it was printed.',
                'delivered_at' => 'When it was handed over in person.',
                'delivered_by_user_uuid' => 'Who handed it over.',
                'revoked_at' => 'When it was revoked. Empty if still valid.',
                'revoked_reason' => 'Why it was revoked (loss, termination, key rotation).',
            ],
        ],

        'devices' => [
            'summary' => 'Paired kiosk tablets. **It carries no device token**: none of these rows can authenticate anything.',
            'columns' => [
                'device_uuid' => 'Kiosk identifier. It is what `scan_events.csv` refers to.',
                'name' => 'Name given when it was paired ("Reception", "Kitchen").',
                'site_name' => 'Site it is installed at.',
                'app_version' => 'Kiosk application version at its last connection.',
                'status' => 'Kiosk situation.',
                'pending_queue_size' => 'Clock-ins still unsent at its last connection. A high, stable number means a kiosk without network.',
                'paired_at' => 'When it was paired.',
                'last_seen_at' => 'Last heartbeat received.',
                'created_at' => 'When the record was created.',
                'updated_at' => 'Last change.',
            ],
        ],

        'shift_entries' => [
            'summary' => 'The time record: every shift worked, with its clock-in and clock-out. **It carries every version**, corrected and voided ones included; a night shift is a single entry, attributed to the day it started.',
            'columns' => [
                'shift_entry_uuid' => 'Shift entry identifier.',
                'employee_uuid' => 'Person who worked it.',
                'site_name' => 'Site.',
                'work_date' => 'Working day it is attributed to. A shift starting Saturday at 22:00 and ending Sunday at 06:00 belongs **entirely** to Saturday.',
                'clocked_in_at' => 'Clock-in (UTC).',
                'clocked_out_at' => 'Clock-out (UTC). Empty if the entry was left open.',
                'duration_minutes' => 'Minutes worked. Empty while the entry is open.',
                'status' => '`open` (no clock-out), `closed` (closed and current), `superseded` (replaced by a later correction) or `voided` (voided, does not count towards hours).',
                'clock_in_source' => 'How the clock-in was recorded: `qr_kiosk` (card at the kiosk), `pin_fallback` (PIN, when the card fails) or `manual` (a correction made from the panel).',
                'clock_out_source' => 'The same for the clock-out.',
                'version' => 'Version number. 1 is the original; every correction creates the next one.',
                'superseded_by_uuid' => 'Entry that replaced this one. Empty for the current version. Following this column reconstructs the full history of a correction.',
                'created_at' => 'When this version was created.',
                'updated_at' => 'Last change to this version.',
            ],
        ],

        'shift_corrections' => [
            'summary' => 'Corrections to the time record, with author and reason. Nothing is overwritten: each correction records what was there before and what was left afterwards.',
            'columns' => [
                'shift_entry_uuid' => 'Corrected entry.',
                'action' => 'What was done: created, amended or voided.',
                'performed_by_user_uuid' => 'Account that made the correction.',
                'performed_by_name' => 'That person\'s name, so the file can be read without cross-referencing `users.csv`.',
                'reason_code' => 'Reason, as a code (forgot to clock in, kiosk failure, and so on).',
                'reason_text' => 'Free-text reason, when one was written.',
                'before' => 'How the entry looked before, as JSON.',
                'after' => 'How it looked afterwards, as JSON. Empty when the correction was a voiding.',
                'created_at' => 'When it was made.',
            ],
        ],

        'daily_totals' => [
            'summary' => 'Totals per person and working day. **It is a calculation, not source data**: it is derived entirely from `shift_entries.csv` and the product rebuilds it from scratch whenever anything changes. It is here for convenience; if it ever disagreed, the shift entry wins.',
            'columns' => [
                'employee_uuid' => 'Person.',
                'work_date' => 'Working day.',
                'total_minutes' => 'Minutes worked that day, voided entries excluded.',
                'shift_count' => 'How many entries the day had.',
                'first_in_at' => 'First clock-in of the day (UTC).',
                'last_out_at' => 'Last clock-out of the day (UTC).',
                'has_open_shift' => 'Whether any entry was left open.',
                'has_incident' => 'Whether the day has an open incident.',
                'recalculated_at' => 'When it was last recalculated.',
            ],
        ],

        'incidents' => [
            'summary' => 'Incidents detected on the time record: insufficient rest, excessive working day, unclosed shift, clock skew. The system opens them and warns; **it never modifies a clock-in by itself**.',
            'columns' => [
                'employee_uuid' => 'Person affected.',
                'work_date' => 'Working day it refers to.',
                'shift_entry_uuid' => 'Specific entry, when the incident is about one.',
                'type' => 'Incident type.',
                'severity' => 'Severity.',
                'status' => 'Open or resolved.',
                'detected_at' => 'When it was detected.',
                'context' => 'The data it was detected with, as JSON: the actual values that triggered it.',
                'assigned_to_user_uuid' => 'Who it was assigned to.',
                'notified_at' => 'When the notification went out.',
                'resolved_at' => 'When it was resolved.',
                'resolved_by_user_uuid' => 'Who resolved it.',
                'resolution_note' => 'What was done about it.',
                'created_at' => 'When the record was created.',
                'updated_at' => 'Last change.',
            ],
        ],

        'scan_events' => [
            'summary' => 'Every time somebody presented a card or typed a PIN at a kiosk, **rejected attempts included**. It is the raw evidence, prior to any calculation.',
            'columns' => [
                'scan_id' => 'Scan identifier, generated by the tablet. It is what stops a re-sent clock-in from being duplicated.',
                'device_uuid' => 'Kiosk where it happened.',
                'employee_uuid' => 'Person recognised. **Empty for rejected attempts**: if the card was not valid, there is nobody to attribute them to.',
                'occurred_at' => 'When it actually happened, according to the kiosk (UTC). This is the legally relevant moment.',
                'recorded_at' => 'When it reached the server (UTC). It can be hours later if the kiosk was offline.',
                'origin' => 'How it came in: card at the kiosk, backup PIN, or queue synchronisation.',
                'intent' => 'What was asked for: clock-in, clock-out, or automatic.',
                'result' => 'What happened: clock-in recorded, clock-out recorded, rejected, duplicate.',
                'shift_entry_uuid' => 'Entry created or closed by this scan, if any.',
                'worked_minutes' => 'Minutes this scan closed, when it was a clock-out.',
                'clock_skew_seconds' => 'Difference between the tablet clock and the server clock. A large skew raises an incident but **never rejects the clock-in**.',
                'flagged_for_review' => 'Whether it was flagged for human review.',
                'client_meta' => 'What the tablet reported about itself at that moment, as JSON.',
            ],
        ],

        'audit_log' => [
            'summary' => 'The complete audit trail, with its digest chain. See "Verifying the audit chain yourself" above.',
            'columns' => [
                'id' => 'Sequence number of the entry. It goes into the digest.',
                'occurred_at' => 'When the fact happened (UTC).',
                'actor_type' => 'What kind of actor did it: `user` (management account), `device` (kiosk), `support_grant` (temporary access granted to the vendor), `system` (a scheduled task) or `maintenance`.',
                'actor_id' => 'Internal identifier of the actor. It goes into the digest; to read it, use `actor_uuid`.',
                'actor_uuid' => 'The same actor identified the way every other file does: cross-reference `users.csv`, `devices.csv` or `support_grants.csv`. Empty for `system` and `maintenance`.',
                'action' => 'What was done, as `subject.fact` (`shift_entry.corrected`, `license.activated`, `data_export.downloaded`).',
                'subject_type' => 'What it applied to.',
                'subject_id' => 'Internal identifier of what it applied to. It goes into the digest.',
                'payload' => 'Detail of the fact, as JSON. **It never carries names or emails**: the audit trail is deliberately kept minimal.',
                'prev_hash' => 'Digest of the previous entry.',
                'hash' => 'Digest of this entry.',
                'ip' => 'Address it came from, when it came over the network.',
                'user_agent' => 'Browser or application it was done from.',
            ],
        ],

        'audit_chain_anchors' => [
            'summary' => 'Yearly seals of the audit chain: enough to check a closed year without walking it.',
            'columns' => [
                'partition_year' => 'Year sealed.',
                'first_hash' => 'Digest of the first entry of the year.',
                'last_hash' => 'Digest of the last one.',
                'row_count' => 'How many entries there were.',
                'sealed_at' => 'When it was sealed.',
                'sealed_by' => 'Which process sealed it.',
            ],
        ],

        'users' => [
            'summary' => 'Management accounts: who can sign in to the panel, and with what permissions. **It carries no password and no two-factor secret.**',
            'columns' => [
                'user_uuid' => 'Account identifier. It is what the other files refer to.',
                'name' => 'Person\'s name.',
                'email' => 'Email used to sign in to the panel.',
                'roles' => 'Roles held, space separated. This is what decided what the account could do.',
                'locale' => 'Language the panel was shown in.',
                'is_active' => 'Whether the account was still active. Accounts are never deleted: they are deactivated.',
                'two_factor_enabled' => 'Whether two-factor authentication was set up. The secret does **not** travel here.',
                'last_login_at' => 'Last sign-in.',
                'created_at' => 'When it was created.',
                'updated_at' => 'Last change.',
            ],
        ],

        'support_grants' => [
            'summary' => 'Temporary accesses you granted to the vendor to resolve an incident. **It carries no token.** If it is empty, the vendor has never been inside your installation.',
            'columns' => [
                'support_grant_uuid' => 'Grant identifier.',
                'granted_by_user_uuid' => 'Who authorised it.',
                'reason' => 'Which incident it was granted for.',
                'scope' => 'How far it reached: `diagnostics` (generate the diagnostics bundle only), `read_only` (read) or `configuration` (read and change settings).',
                'granted_at' => 'When it was granted.',
                'expires_at' => 'When it stopped being valid, with nobody doing anything.',
                'revoked_at' => 'When it was revoked early, if it was.',
                'revoked_by_user_uuid' => 'Who revoked it. Empty if it was revoked from the server console.',
                'accessed_at' => 'Last time it was used. Empty if it was granted and never needed.',
            ],
        ],

        'installation_settings' => [
            'summary' => 'Installation configuration as you left it in the panel: languages, branding, operational thresholds.',
            'columns' => [
                'key' => 'Setting name.',
                'value' => 'Its value, as a JSON document.',
                'updated_at' => 'When it was last changed.',
                'updated_by_user_uuid' => 'Who changed it.',
            ],
        ],

        'compliance_profiles' => [
            'summary' => 'The legal thresholds used to detect incidents and to decide how long data is kept.',
            'columns' => [
                'name' => 'Profile name.',
                'jurisdiction' => 'Jurisdiction it corresponds to.',
                'retention_years' => 'Years the time record is kept.',
                'min_rest_hours' => 'Minimum rest between working days, in hours.',
                'max_daily_hours' => 'Maximum daily working time, in hours.',
                'max_weekly_hours' => 'Maximum weekly working time, in hours.',
                'break_required_after_hours' => 'After how many consecutive hours a break is required.',
                'week_starts_on' => 'Day the week starts on for computation purposes.',
                'holiday_calendar' => 'Public holiday calendar, as JSON.',
                'is_default' => 'Whether this is the profile in force.',
                'updated_at' => 'When it was last changed.',
                'updated_by_user_uuid' => 'Who changed it.',
            ],
        ],

        'license' => [
            'summary' => 'The activated licence. **It does not carry the signed key**, which belongs to the vendor and is already in your activation email; it does carry everything the key states.',
            'columns' => [
                'license_id' => 'Licence identifier.',
                'customer_name' => 'Legal entity it was issued to.',
                'plan' => 'Contracted plan.',
                'max_employees' => 'Maximum number of employees covered.',
                'max_devices' => 'Maximum number of kiosks covered.',
                'features' => 'Optional features included, as JSON.',
                'valid_from' => 'Valid from.',
                'valid_until' => 'Valid until. **A date in the past does not stop clocking in or reading the record**: only optional features are switched off.',
                'issued_at' => 'When it was issued.',
                'activated_at' => 'When it was activated in this installation.',
                'activated_by_user_uuid' => 'Who activated it.',
                'last_verified_at' => 'Last signature check. It happens on the server, with no internet access.',
            ],
        ],
    ],
];
