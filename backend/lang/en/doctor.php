<?php

declare(strict_types=1);

/*
 * Texts of the `php artisan product:doctor --lang=en` report (RF-PD-13, task 5.9).
 *
 * Same rules as the Spanish file: `checks.*` says what was checked and what was
 * found, `fixes.*` says what to do with the full copy-pasteable command, and no
 * sentence assumes any prior knowledge of the product. The vendor has no access
 * to this server (ADR-016): a message that does not say what to do leaves a
 * phone call as the only way out.
 */

/** Shared text for a whole family of checks that blew up. */
$probe = [
    'failure' => 'The «:family» group of checks could not run: something failed unexpectedly inside the '
        .'diagnostic itself. Every other check did run.',
    // PR2: the group needs a service that did not answer. It is not a product defect.
    'failure_redis' => 'The «:family» group of checks could not run because Redis does not respond. '
        .'Every other check did run.',
    'failure_database' => 'The «:family» group of checks could not run because the database does not respond. '
        .'Every other check did run.',
];

$probeFix = [
    'failure' => "This is a product defect, not a problem with your installation.\n"
        ."Generate the diagnostics bundle and send it to support:\n"
        .'  php artisan product:diagnostics',
    'failure_redis' => "Fix Redis first: look at the «queue.redis» check in this same report.\n"
        ."Then run this command again:\n"
        .'  php artisan product:doctor',
    'failure_database' => "Fix the database first: look at the «database.connection» check in this same report.\n"
        ."Then run this command again:\n"
        .'  php artisan product:doctor',
];

return [

    /*
     * The frame of the report printed by `product:doctor` without `--json`.
     *
     * It lives here and not in the command so that the whole report speaks one
     * language: with the frame written in PHP, the title and headings came out
     * in Spanish while the checks came out in English as soon as `APP_LOCALE`
     * and the installation language disagreed. Nobody reads a bilingual report.
     */
    'report' => [
        'title' => 'KronoQR diagnostics :version',
        'all_ok' => 'All good: :total checks, none with findings.',
        'problems' => ':count of :total checks have findings:',
        'ok_header' => 'Checks that passed',
        'fix_label' => 'What to do:',
        'result' => 'Result: :label (exit code :code)',
        'tag_ok' => 'ok',
        'tag_warning' => 'warning',
        'tag_failure' => 'FAILURE',
        'status_ok' => 'OK',
        'status_warning' => 'WITH WARNINGS',
        'status_failure' => 'WITH FAILURES',
        'meaning_ok' => 'There is nothing to do.',
        'meaning_warning' => 'Nothing is broken. People clock in and the record can be read as usual; look at the '
            .'above when you can. Installation and updates are NOT stopped by this.',
        'meaning_failure' => 'Something needs fixing. Follow the «What to do» of each failure and run this command '
            .'again. If you need help, generate the diagnostics bundle with `php artisan product:diagnostics` and '
            .'send it to support.',
    ],

    'checks' => [

        'database' => [
            'probe' => $probe,
            'connection' => [
                'ok' => 'The database responds (:server_version) and has :applied_migrations migrations applied.',
                'failure' => 'The database cannot be reached. Without it the system cannot record clock-ins or '
                    .'serve any query.',
            ],
            'migrations_pending' => [
                'ok' => 'The database schema is up to date.',
                'failure' => ':count migration(s) have not been applied. The code and the database do not match, '
                    .'so any screen may fail in odd ways.',
            ],
            'audit_log_privileges' => [
                'ok' => 'The application user cannot modify or delete the audit log, which is how it must be.',
                'failure' => 'Database user «:user» CAN modify or delete the audit log. That log is the proof '
                    .'that the recorded hours have not been tampered with; if it can be edited, it stops being '
                    .'usable as evidence in a labour inspection.',
                'warning_unknown' => 'The privileges on the audit log could not be read.',
            ],
            'audit_chain' => [
                'ok' => 'The audit log is correctly chained.',
                'failure' => 'The last yearly seal of the audit log does not add up: rows are missing or the '
                    .'chain is broken. It may mean someone modified the log outside the application, or that a '
                    .'restore was left incomplete.',
                'warning_unknown' => 'The audit log chain could not be checked.',
            ],
        ],

        'queue' => [
            'probe' => $probe,
            'redis' => [
                'ok' => 'Redis responds.',
                'failure' => 'Redis does not respond. Without it the job queue and the metrics do not work, and the '
                    .'panel and the employee portal reject requests, because their attempt limit cannot be checked. '
                    .'Clocking in keeps working.',
            ],
            'backlog' => [
                'ok' => 'The job queue is up to date (:count pending).',
                'warning' => ':count jobs are waiting in the queue. Not serious yet, but notifications and '
                    .'reports are running late.',
                'failure' => ':count jobs are stuck in the queue. Notifications, reports and the nightly '
                    .'recalculation are not running.',
                'warning_unknown' => 'The size of the job queue could not be measured.',
            ],
            'worker' => [
                'ok' => 'Horizon, the process that consumes the job queue, is running (:masters active '
                    .'supervisor(s)).',
                'ok_busy' => 'There are jobs in progress: something is consuming the job queue.',
                'ok_idle' => 'The job queue is empty. Horizon could not be asked, so from here it is unknown whether '
                    .'the process that consumes it is running. Check it with: docker compose ps horizon',
                'warning' => 'At :checked_at UTC there were :count jobs waiting and none in progress. The process '
                    .'that consumes the queue is probably not running.',
                'failure_stopped' => 'At :checked_at UTC no Horizon process was consuming the job queue (:count '
                    .'waiting). Clocking in does not depend on it, but no full export (the Labour Inspectorate\'s '
                    .'or an employee\'s asking for their data), no deferred report and no incident notice gets '
                    .'done until it is back.',
                'warning_paused' => 'Horizon, the process that consumes the job queue, is paused (:count waiting). '
                    .'While it stays paused, notifications, reports and the nightly recalculation do not run.',
                'warning_unknown' => 'Whether the queue worker is alive could not be determined.',
            ],
        ],

        // --- Scheduler -------------------------------------------------------

        'scheduler' => [
            'probe' => $probe,
            'heartbeat' => [
                'ok' => 'The task scheduler is launching its tasks (last every-minute measurement at :last_run_at '
                    .'UTC).',
                'warning' => 'The task the scheduler launches every minute has published nothing since :last_run_at '
                    .'UTC (:minutes min ago). Most likely the «scheduler» container is stopped, and without it there '
                    .'are no backups, no daily audit log verification and no nightly recalculation. Clocking in '
                    .'does not depend on it.',
                'warning_never' => 'There is no record of the task scheduler working: the measurement it launches '
                    .'every minute has never been published. If you have just installed or updated, wait a couple '
                    .'of minutes and check again.',
                'warning_unknown' => ':path, where the scheduler leaves its every-minute measurement, cannot be read.',
            ],
        ],

        'mail' => [
            'probe' => $probe,
            'transport' => [
                'ok' => 'Mail is configured with the «:mailer» transport.',
                'warning' => 'In production, mail is set to «:mailer»: messages are not sent to anyone, they are '
                    .'written to the technical log. Nothing fails and nobody receives anything.',
            ],
            'reachable' => [
                'ok' => 'The mail server accepts connections on port :port.',
                'warning' => 'The mail server could not be reached on port :port. Email notifications will not '
                    .'go out. Nothing else depends on this: in KronoQR nobody receives their card or their '
                    .'access by email.',
                'warning_not_configured' => 'No mail server is configured. Email notifications will not go out. '
                    .'This does not prevent clocking in or reading the record.',
            ],
            'alert_recipients' => [
                'ok' => 'All :expected alert recipients (it-cliente, rrhh and seguridad) have somewhere to '
                    .'receive their alerts, by email or by webhook.',
                'ok_disabled' => 'Automatic monitoring is switched off in this installation, so there are no '
                    .'alerts to send to anyone.',
                'warning' => 'Automatic monitoring is switched on and :missing of the :expected alert '
                    .'recipients have neither an email address nor a webhook: :roles. The alerts addressed to '
                    .'them turn on and off without anyone seeing them. These are not minor notices: a broken '
                    .'audit record goes to «seguridad» and unclosed shifts go to «rrhh».',
            ],
        ],

        // --- Edge networks (PP-01) -------------------------------------------

        'network' => [
            'probe' => $probe,
            'portal' => [
                'ok' => 'The employee portal only opens from a private network.',
                'ok_not_provided' => 'The application does not receive PORTAL_INTERNAL_CIDR, so where the portal '
                    .'opens from could not be checked.',
                'warning_open' => 'The employee portal is open to the internet (PORTAL_INTERNAL_CIDR allows any '
                    .'origin). This is a legitimate customer decision, but the portal signs in with an employee '
                    .'code and a PIN: it should be on record, someone should watch the failed attempts and the '
                    .'PINs should have 8 digits (the «access.pin_length» check says how they stand).',
                'warning_public' => 'PORTAL_INTERNAL_CIDR includes addresses that are not from a private network: '
                    .'the employee portal can be opened from those public internet addresses.',
                'warning_sample' => 'PORTAL_INTERNAL_CIDR still holds the example value of the development '
                    .'network. On a real server that network does not exist and nobody can open the portal: the '
                    .'whole staff gets a 403.',
                'failure_invalid' => 'PORTAL_INTERNAL_CIDR is not a valid IPv4 network range. The web server does '
                    .'not start with that value, so nothing is served: neither the portal, nor the panel, nor the '
                    .'kiosks.',
            ],
            'admin' => [
                'ok' => 'The management panel and staff sign-in are only accepted from one specific network '
                    .'(ADMIN_INTERNAL_CIDR).',
                'ok_not_provided' => 'The application does not receive ADMIN_INTERNAL_CIDR, so where the '
                    .'management panel opens from could not be checked.',
                'warning_unfiltered' => 'The management panel (/admin/) and staff sign-in are not filtered by '
                    .'network (ADMIN_INTERNAL_CIDR empty or open to any origin): they are reachable from wherever '
                    .'the server is, and if the employee portal is open to the internet, so is the panel. This is '
                    .'the default and an accepted risk, not a fault: the password, the mandatory second factor of '
                    .'the management roles, the limit of 5 attempts per minute and the account lockout still '
                    .'protect it.',
                'failure_invalid' => 'ADMIN_INTERNAL_CIDR is not a valid IPv4 network range. The web server does '
                    .'not start with that value, so nothing is served: neither the portal, nor the panel, nor the '
                    .'kiosks.',
            ],
            'kiosk_vlan' => [
                'ok' => 'The raised clock-in limit only applies to one specific network (KIOSK_VLAN_CIDR).',
                'ok_not_provided' => 'The application does not receive KIOSK_VLAN_CIDR, so the kiosk network '
                    .'could not be checked.',
                'warning_open' => 'KIOSK_VLAN_CIDR allows any origin: the raised clock-in limit (600 requests per '
                    .'minute) applies to the whole internet and the 30 per minute limit that protects from '
                    .'outside applies to nobody.',
                'failure_invalid' => 'KIOSK_VLAN_CIDR is not a valid IPv4 network range. The web server does not '
                    .'start with that value, so kiosks cannot clock in against the server.',
            ],
            'metrics' => [
                'ok' => 'Reading /metrics is limited to one specific network (METRICS_ALLOW_CIDR).',
                'ok_not_provided' => 'The application does not receive METRICS_ALLOW_CIDR, so who can read '
                    .'/metrics could not be checked.',
                'warning_open' => 'METRICS_ALLOW_CIDR allows any origin: /metrics, which exposes the internal '
                    .'state of the system, can be read from anywhere.',
                'failure_invalid' => 'METRICS_ALLOW_CIDR is not a valid IPv4 network range. The web server does '
                    .'not start with that value.',
            ],
        ],

        // --- Access to the portal and the panel (ADR-050) --------------------

        'access' => [
            'probe' => $probe,
            'pin_length' => [
                'ok' => 'The employee portal is not declared as reachable from the internet and PINs are issued '
                    .'with :length digits, which is enough together with the attempt lockout.',
                'ok_exposed' => 'The employee portal is reachable from the internet and PINs are issued with 8 '
                    .'digits, which is the recommendation.',
                'warning' => 'The employee portal is reachable from the internet and PINs are issued with 6 '
                    .'digits. With 6 digits, someone trying from many different connections ends up guessing a '
                    .'PIN within months; with 8, within decades. The per-connection lockout does not stop '
                    .'someone who keeps changing address.',
            ],
            'short_pins' => [
                'ok' => 'Every active person with a PIN already has an 8-digit PIN.',
                'ok_six' => 'PINs are issued with 6 digits: there are no short PINs waiting to be reset.',
                'warning' => ':count active person(s) still have a 6-digit PIN although PINs are now issued with '
                    .'8. They can still sign in with it, nothing is broken, but until it is reset their PIN is '
                    .'the easy one to guess. This report only gives the number, never who.',
            ],
            'two_factor_roles' => [
                'ok' => 'The second factor is mandatory for the four management roles: administration, HR, audit '
                    .'and department manager.',
                'warning' => 'IDENTITY_2FA_REQUIRED_ROLES does not include :roles: those accounts can sign in to the '
                    .'panel with the password alone, and all of them read or correct the time record. The '
                    .'department manager corrects working days: with their stolen password the record of their '
                    .'department can be rewritten.',
            ],
            'two_factor_pending' => [
                'ok' => 'Every active account of the mandatory roles has its second factor enrolled.',
                'warning' => ':count active account(s) of roles that must use a second factor have not enrolled '
                    .'it yet. They will on their next panel sign-in, but until then someone holding only their '
                    .'password could enrol it in their place. This report only gives the number.',
            ],
        ],

        'tls' => [
            'probe' => $probe,
            'certificate' => [
                'ok' => 'The server certificate is valid and has :days days left.',
                'warning' => 'The server certificate expires in :days days. Once it does, the tablets will stop '
                    .'syncing their clock-ins silently: people will keep clocking in and the queue will pile up '
                    .'on each tablet.',
                'failure' => 'The server certificate EXPIRED :days days ago. The tablets are not syncing: they '
                    .'keep accepting clock-ins and storing them locally, but nothing reaches this server.',
                'warning_no_url' => 'The server address (APP_URL) could not be parsed, so the certificate was '
                    .'not checked.',
                'warning_not_https' => 'The server address does not use https (:scheme), so there is no '
                    .'certificate to check. In production this should not be the case.',
                'warning_unreachable' => 'A secure connection to port :port could not be opened to read the '
                    .'certificate. The web server may not be up yet.',
                'warning_unreadable' => 'The web server answered but the expiry date of its certificate could '
                    .'not be read.',
                'warning_self_signed' => 'The certificate is self-signed and the configuration says self-signed '
                    .'certificates should not be accepted (TLS_ALLOW_SELF_SIGNED=false). Tablets may refuse the '
                    .'connection.',
            ],
        ],

        'permissions' => [
            'probe' => $probe,
            'storage' => [
                'ok' => 'The application working directories are writable.',
                'failure' => 'The application cannot write to: :paths. Without that it cannot generate reports, '
                    .'store its cache or write its technical log.',
            ],
            // 2.2.0 (block 20, A3-R2): the backup root is mounted read-only and
            // each container writes only to its own subdirectory.
            'backup_path' => [
                'ok' => 'The backup directory :path can be read and the application cannot write to it: backups '
                    .'are written by the backup task only.',
                'ok_not_checked' => 'The backup directory :path can be read. Whether it is read-only is not checked '
                    .'because this is not a production installation.',
                'failure_missing' => 'The backup directory :path does not exist. BACKUPS ARE NOT BEING TAKEN.',
                'failure_unreadable' => 'The backup directory :path cannot be read. BACKUPS ARE NOT BEING TAKEN, and '
                    .'there is no other symptom until the day you need one.',
                'warning_writable' => 'The «:service» container can write to the backup directory :path. Backups '
                    .'work, but whoever runs code in the application could delete them or drop files next to them: '
                    .'the docker-compose.yml in use is not the one of this version.',
            ],
            'backup_metrics' => [
                'ok' => 'Backup and archiving metrics can be written to :path.',
                'failure' => 'The application cannot write to :path. Neither the backups nor the database archiving '
                    .'can publish their result, and the alerts that warn about a failed backup will not fire.',
                'failure_missing' => 'The metrics directory :path does not exist. Backups and archiving cannot publish '
                    .'their result, and the alerts that warn about a failed backup will not fire.',
            ],
            'backup_copies' => [
                'ok' => 'The backup directories (daily and base) exist and only the backup task writes to them.',
                'failure' => 'The backup task cannot write to: :paths. BACKUPS ARE NOT BEING TAKEN.',
                'failure_missing' => 'The backup directories do not exist: :paths. BACKUPS ARE NOT BEING TAKEN.',
                'warning_writable' => 'The «:service» container can write to :paths, where the backups live. Only '
                    .'the backup task should be able to: the docker-compose.yml in use is not the one of this version.',
            ],
            'branding_root' => [
                'ok' => 'The logo directory is accessible.',
                'warning' => 'The logo directory :path cannot be read. The applications will show the product '
                    .'branding instead of the hotel branding. Nothing else is affected.',
            ],
            'branding_logo' => [
                'ok' => 'The configured logo can be read.',
                'warning_path' => 'The configured logo cannot be used: its path is not inside the brand directory. '
                    .'The applications and the PDFs will come out without a logo. Nothing else is affected.',
                'warning_missing' => 'The configured logo cannot be used: there is no readable file at that path. '
                    .'The applications and the PDFs will come out without a logo. Nothing else is affected.',
                'warning_content' => 'The configured logo cannot be used: the file is not an accepted PNG or SVG '
                    .'(format, size, dimensions or an SVG with a script). The applications and the PDFs will come '
                    .'out without a logo. Nothing else is affected.',
                'warning_unknown' => 'The configured logo could not be checked.',
            ],
        ],

        'disk' => [
            'probe' => $probe,
            'storage' => [
                'ok' => 'Plenty of space left on the application disk (:free free, :percent %).',
                'warning' => 'Space is running low on the application disk: :free free (:percent %).',
                'failure' => 'Very little space left on the application disk: :free free (:percent %). Once it '
                    .'fills up, the database will stop accepting writes and NOBODY WILL BE ABLE TO CLOCK IN.',
                'warning_missing' => 'The directory :path does not exist, so its disk could not be measured.',
                'warning_unknown' => 'The free space of :path could not be measured.',
            ],
            'backup' => [
                'ok' => 'Plenty of space left on the backup disk (:free free, :percent %).',
                'warning' => 'Space is running low on the backup disk: :free free (:percent %).',
                'failure' => 'Very little space left on the backup disk: :free free (:percent %). The next '
                    .'backups will fail.',
                'warning_missing' => 'The directory :path does not exist, so its disk could not be measured.',
                'warning_unknown' => 'The free space of :path could not be measured.',
            ],
        ],

        // --- Backups ---------------------------------------------------------

        'backup' => [
            'probe' => $probe,
            'last_good_copy' => [
                'ok' => 'The last verified backup is from :verified_at UTC (:hours h ago).',
                'failure_stale' => 'The last verified backup is from :verified_at UTC, :hours hours ago. One should '
                    .'be made and verified every night (at :daily_at UTC): the nightly backups are not being made '
                    .'or are not passing verification.',
                'failure_last_failed' => 'The last backup ended with an error. The previous good one is still in '
                    .'place, but there has been no new backup since.',
                'failure_verify_failed' => 'The last backup verification failed: the backup cannot be decrypted or '
                    .'cannot be restored. Treat it as if it did not exist.',
                'warning_never' => 'There is no record of any verified backup. That is normal until the first '
                    .'nightly backup after installing (at :daily_at UTC); after that night, it is not.',
                'warning_unknown' => ':path, where the backup records its result, cannot be read.',
            ],
        ],

        'files' => [
            'probe' => $probe,
            'storage_volume' => [
                'ok' => 'storage/app is a volume of its own and is writable: exports, reports and purges see the '
                    .'same files in the three containers.',
                'ok_not_checked' => 'storage/app is writable. Whether it is a volume of its own is not checked '
                    .'because this is not a production installation.',
                'failure' => 'The application cannot write to :path. Without it no exports, deferred reports or '
                    .'diagnostics bundles can be generated.',
                'failure_not_mounted' => ':path is NOT a volume of its own: each container has its own copy and '
                    .'loses it on update. Exports requested from the panel cannot be downloaded and the purges do not '
                    .'see the files with personal data.',
            ],
            'retention_reports' => [
                'ok' => 'Retention reports are written to :path, next to the backups.',
                'warning' => 'The application cannot write to :path: the weekly retention proposal and the purge '
                    .'will not be able to leave their report.',
                'warning_missing' => 'The retention report directory :path does not exist. The weekly retention '
                    .'proposal and the purge will not be able to leave their readable report.',
                'warning_inside_storage' => 'COMPLIANCE_RETENTION_REPORT_PATH points to :path, inside storage/app.',
                'ok_read_only' => 'The retention reports in :path can be read and this container cannot write them: '
                    .'only the weekly proposal and the purge write them.',
                'warning_horizon_writable' => 'This container (horizon) can write to :path, where the retention '
                    .'reports are kept. Only the weekly proposal and the purge should be able to: the '
                    .'docker-compose.yml in use is not the one of this version.',
            ],
            'class_roots' => [
                'ok' => 'Each class of generated file has its own directory and none overlaps another.',
                'failure_overlap' => ':first and :second point to the same directory or one contains the other: the '
                    .'purge of one could delete files of the other.',
                'failure_storage_root' => ':name points to :path, which is storage/app or contains it: its purge '
                    .'would see the files of every other class.',
                'failure_backup_path' => ':name points to :path, which overlaps the backup directory. Backups would '
                    .'keep for months files that expire in days.',
                'warning_outside_volume' => 'These directories are outside storage/app: :names. Outside the shared '
                    .'volume, what one container writes another does not see.',
                'failure_outside_volume' => 'These directories are outside storage/app: :names. In production, '
                    .'outside the shared volume, exports requested from the panel cannot be downloaded and the purges '
                    .'do not see the files with personal data.',
            ],
            'stray_entries' => [
                'ok' => 'No abandoned generated files in storage/app outside the configured directories.',
                'warning' => 'There are generated files in :names, inside storage/app but outside the configured '
                    .'directories. No purge looks at them: they are usually left over from a root that was changed.',
            ],
            'legal_exports_console' => [
                'ok' => 'No console legal export has been on the server for more than :days days.',
                'warning' => 'There are :count console legal export(s) older than :days days in :path. They contain '
                    .'personal data of the workforce and are not deleted automatically.',
            ],
        ],

        'app' => [
            'probe' => $probe,
            'timezone_utc' => [
                'ok' => 'The application runs in UTC, which is correct.',
                'failure' => 'The application is running in the «:timezone» time zone instead of UTC. Every '
                    .'timestamp stored from now on will be shifted, AND THAT CANNOT BE UNDONE afterwards. Local '
                    .'time is derived from UTC on its own: you do not need to change this for the screens to '
                    .'show the hotel time.',
            ],
            'debug_in_production' => [
                'ok' => 'Debug mode is off.',
                'failure' => 'Debug mode (APP_DEBUG) is on in production. Any error shows the database '
                    .'passwords and the signing keys of the system to whoever triggers it.',
            ],
            'error_history' => [
                'ok' => 'The error history is a normal size (:total lines in total; the source with the most '
                    .'unresolved errors has :open).',
                'warning' => 'Source ":source" has :open unresolved errors, and the maximum is :cap. Once it '
                    .'reaches the maximum, NEW errors from that source will stop being stored separately.',
                'failure' => 'Source ":source" has reached the maximum of :cap unresolved errors. Its new errors '
                    .'are NO LONGER stored separately: they are all counted together in a single line saying '
                    .'"the cap has been reached", so you are losing detail about what is failing.',
                'warning_busy' => 'The error history has :total distinct lines. That is not a problem in itself, '
                    .'but a normal installation does not accumulate that many in 90 days.',
                'warning_unavailable' => 'The error history could not be queried (:failure). Every other check did '
                    .'run.',
            ],
        ],

        'settings' => [
            'probe' => $probe,
            'invalid_keys' => [
                'ok' => 'All stored configuration is valid.',
                'warning' => 'Some stored settings could not be applied and were replaced by their factory '
                    .'value: :keys. They affect how the system looks, not the recorded hours.',
                'failure' => 'Some stored settings could not be applied and were replaced by their factory '
                    .'value: :keys. AT LEAST ONE OF THEM AFFECTS HOW HOURS ARE CALCULATED, so the system is '
                    .'calculating with a value other than the one you think you have set.',
            ],
            'env_differs_from_db' => [
                'ok' => 'The .env file and the stored configuration agree.',
                'warning' => 'These settings have different values in the .env file and in the system: :keys. '
                    .'What the system has stored wins, which is what the panel edits. This is the usual '
                    .'explanation for «but I have it set to something else».',
            ],
        ],

        'kiosk' => [
            'probe' => $probe,
            'service_code' => [
                'ok' => 'The tablets ask for a service code before opening their diagnostics screen.',
                'warning' => 'No service code is configured, so anyone standing in front of a tablet can open '
                    .'its diagnostics screen. That screen shows no employee data and never the tablet key, but '
                    .'it does say whether there is network, how many clock-ins are still unsent and which '
                    .'version is running. Nothing is broken: this is how the product ships.',
                'warning_unavailable' => 'Could not check whether a service code is configured for the tablet '
                    .'diagnostics screen. Clocking in is unaffected.',
            ],
            'app_version' => [
                'ok' => 'None of the tablets sending heartbeats (:count) is behind the server version (:minimum).',
                'ok_ahead' => 'Tablets with an app version newer than the server\'s (:minimum): :devices. '
                    .'This happens after rolling the server back to an earlier version; the tablets will move to '
                    .'the server\'s version on their own. Nothing to do.',
                'ok_unchecked' => 'This development server has no published version, so the tablets\' version '
                    .'is not compared.',
                'warning_unchecked' => 'The server version is not valid (unknown or a development build): the '
                    .'outdated-tablet warning is switched off. Clocking in is unaffected.',
                'warning' => 'Tablets on an app version older than the server\'s (:minimum), with an empty, '
                    .'on-disk queue: :devices. They keep clocking in as normal.',
                'warning_draining' => 'Tablets on an app version older than the server\'s (:minimum) that still '
                    .'have unsent clock-ins or an in-memory queue: :waiting. They keep clocking in as normal, '
                    .'but they must not be touched yet.',
                'warning_mixed' => 'Tablets on an app version older than the server\'s (:minimum). With an empty, '
                    .'on-disk queue: :devices. With unsent clock-ins or an in-memory queue, not to be touched yet: '
                    .':waiting. All of them keep clocking in as normal.',
                'warning_unavailable' => 'Could not check the app version of the tablets. Clocking in is unaffected.',
            ],
        ],

        'license' => [
            'probe' => $probe,
            'state' => [
                'ok' => 'The licence is valid.',
                'warning_plan_exceeded' => 'The licence is valid, but the installation is over one of the '
                    .'contracted plan figures. It has not blocked any record and it will not.',
                'warning_expiring_soon' => 'The licence expires in :days days. Once it does, clocking in and '
                    .'reading the record keep working normally; only optional features such as period reports '
                    .'become unavailable.',
                'warning_expired' => 'The licence expired :days days ago. CLOCKING IN AND EXPORTING THE RECORD '
                    .'FOR A LABOUR INSPECTION KEEP WORKING normally: only optional features are unavailable.',
                'warning_absent' => 'No licence has been activated. Clocking in and reading the record work '
                    .'normally; only the optional features are missing.',
                'warning_not_yet_valid' => 'The licence is correct but its validity period starts later. '
                    .'Nothing to do.',
                'warning_unverifiable' => 'The stored licence cannot be verified (:reason). Clocking in and '
                    .'reading the record work normally.',
            ],
            'white_label_without_plan' => [
                'ok' => 'The configured branding and the contracted plan are consistent.',
                'warning' => 'Custom branding is configured (name, colour or logo) but the contracted plan does '
                    .'not include it, so the applications are showing the product branding.',
            ],
        ],
    ],

    'fixes' => [

        'database' => [
            'probe' => $probeFix,
            'connection' => [
                'failure' => "Check that the database container is up:\n"
                    ."  docker compose ps\n"
                    ."  docker compose logs --tail=50 postgres\n"
                    ."If it does not start, it is almost always a full disk or credentials changed in .env\n"
                    .'(DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD).',
            ],
            'migrations_pending' => [
                'failure' => "Apply them with:\n"
                    ."  php artisan migrate --force\n"
                    ."If this shows up right after an update, the update did not finish: check the report in\n"
                    .'the backup directory, under reports/update-*.log.',
            ],
            'audit_log_privileges' => [
                'failure' => "Revoke those privileges from the application user, connected as a database\n"
                    ."administrator:\n"
                    ."  REVOKE UPDATE, DELETE ON audit_log FROM :user;\n"
                    ."This usually happens after restoring a backup with the wrong user. If you do not know how\n"
                    .'they got there, tell support before changing anything: it may be relevant.',
                'warning_unknown' => "Run `php artisan product:doctor` again once the database responds. If it\n"
                    .'still cannot be checked, generate the diagnostics bundle.',
            ],
            'audit_chain' => [
                'failure' => "Verify the whole chain to find where the break is:\n"
                    ."  php artisan compliance:verify-audit-chain\n"
                    ."DO NOT delete or edit anything. Save that output, generate the diagnostics bundle and\n"
                    .'tell support: this may have legal consequences and when it happened must be documented.',
                'warning_unknown' => 'Run it again once the database responds.',
            ],
        ],

        'queue' => [
            'probe' => $probeFix,
            'redis' => [
                'failure' => "Check the Redis container:\n"
                    ."  docker compose ps\n"
                    ."  docker compose logs --tail=50 redis\n"
                    ."  docker compose restart redis\n"
                    .'People can keep clocking in meanwhile; the panel and the portal come back once Redis responds.',
            ],
            'backlog' => [
                'warning' => "Check whether the queue worker is alive:\n"
                    ."  docker compose ps horizon\n"
                    .'If it is, this is a normal spike and it will drain on its own. Check again in ten minutes.',
                'failure' => "Restart the queue worker:\n"
                    ."  docker compose restart horizon\n"
                    ."  docker compose logs --tail=100 horizon\n"
                    ."If jobs are failing rather than piling up, list the failed ones with:\n"
                    .'  php artisan queue:failed',
                'warning_unknown' => 'Check that Redis responds and run this command again.',
            ],
            'worker' => [
                'warning' => "Start or restart the queue worker:\n"
                    ."  docker compose ps horizon\n"
                    ."  docker compose restart horizon\n"
                    .'Clocking in does not depend on it; notifications and reports do.',
                'failure_stopped' => "Start the queue worker and find out why it stopped:\n"
                    ."  docker compose ps horizon\n"
                    ."  docker compose up -d horizon\n"
                    ."  docker compose logs --tail=100 horizon\n"
                    .'If you have just updated, it may not have started yet: check again in a minute.',
                'warning_paused' => "Unless someone paused it on purpose for maintenance, resume it:\n"
                    .'  docker compose exec horizon php artisan horizon:continue',
                'warning_unknown' => 'Check that Redis responds and run this command again.',
            ],
        ],

        'scheduler' => [
            'probe' => $probeFix,
            'heartbeat' => [
                'warning' => "Check that the scheduler is running and start it if it is not:\n"
                    ."  docker compose ps scheduler\n"
                    ."  docker compose up -d scheduler\n"
                    ."If it was already running, what fails is the database archiving measurement.\n"
                    ."Run it by hand and read its message:\n"
                    ."  docker compose exec scheduler php artisan backup:wal-metrics\n"
                    .'See docs/runbooks/restaurar-backup.md.',
                'warning_never' => "Check that the scheduler is running and start it if it is not:\n"
                    ."  docker compose ps scheduler\n"
                    ."  docker compose up -d scheduler\n"
                    ."If it is and the warning is still there after a few minutes, run the measurement by hand:\n"
                    .'  docker compose exec scheduler php artisan backup:wal-metrics',
                'warning_unknown' => "The metrics directory must belong to user 1000:\n"
                    ."  ls -l :path\n"
                    .'See docs/runbooks/restaurar-backup.md §4.3.',
            ],
        ],

        'mail' => [
            'probe' => $probeFix,
            'transport' => [
                'warning' => "Put the hotel mail server details in the .env file:\n"
                    ."  MAIL_MAILER=smtp\n"
                    ."  MAIL_HOST=...\n"
                    ."  MAIL_PORT=587\n"
                    ."then restart the application:\n"
                    ."  docker compose up -d app\n"
                    .'If you do not want email notifications, leave it as it is: nothing else is affected.',
            ],
            'reachable' => [
                'warning' => "Check MAIL_HOST and MAIL_PORT in the .env file and that the hotel firewall allows\n"
                    ."outbound traffic on that port. Then:\n"
                    .'  docker compose up -d app',
                'warning_not_configured' => "If you want email notifications, fill in MAIL_MAILER, MAIL_HOST and\n"
                    ."MAIL_PORT in the .env file and restart with `docker compose up -d app`.\n"
                    .'If you do not want them, there is nothing to do.',
            ],
            'alert_recipients' => [
                'warning' => "Put a destination in the .env file for EACH missing recipient (:roles).\n"
                    ."Each one accepts an email address, a webhook or both; one of the two is enough:\n"
                    ."  it-cliente  ->  ALERT_EMAIL_IT=it@yourhotel.example\n"
                    ."                  ALERT_WEBHOOK_IT=\n"
                    ."  rrhh        ->  ALERT_EMAIL_RRHH=hr@yourhotel.example\n"
                    ."                  ALERT_WEBHOOK_RRHH=\n"
                    ."  seguridad   ->  ALERT_EMAIL_SEGURIDAD=management@yourhotel.example\n"
                    ."                  ALERT_WEBHOOK_SEGURIDAD=\n"
                    ."If it is the same person at your hotel, put the same address in all three: what does\n"
                    ."not work is leaving one empty, because nobody receives its alerts.\n"
                    ."Then reload the alert routing:\n"
                    ."  docker compose up -d alertmanager\n"
                    .'If you would rather not receive alerts, switch monitoring off by leaving COMPOSE_PROFILES empty.',
            ],
        ],

        'network' => [
            'probe' => $probeFix,
            'portal' => [
                'warning_open' => "If opening it to the internet is what you want, there is nothing to fix: note it\n"
                    ."in the installation record and review docs/cliente/endurecimiento.md (in Spanish).\n"
                    ."If not, put the hotel network or your VPN range in the .env file and apply the change:\n"
                    ."  PORTAL_INTERNAL_CIDR=10.20.0.0/16\n"
                    ."  docker compose up -d nginx\n"
                    ."While the portal is open to the internet, issue 8-digit PINs and close the panel\n"
                    .'to your network: see the «access.pin_length» and «network.admin» checks.',
                'warning_public' => "If it must only open from the hotel network, put its private range in the .env\n"
                    ."file and apply the change:\n"
                    ."  PORTAL_INTERNAL_CIDR=10.20.0.0/16\n"
                    ."  docker compose up -d nginx\n"
                    ."If those public addresses are the ones you want, there is nothing to fix.\n"
                    ."With the portal reachable from the internet, issue 8-digit PINs and close the\n"
                    .'panel to your network: see the «access.pin_length» and «network.admin» checks.',
                'warning_sample' => "Find out which IP nginx sees for an employee (docs/runbooks/portal-403.md, in\n"
                    ."Spanish) and put its network in the .env file. Then apply the change:\n"
                    ."  PORTAL_INTERNAL_CIDR=10.20.0.0/16\n"
                    .'  docker compose up -d nginx',
                'failure_invalid' => "Fix it in the .env file: one single range in the a.b.c.d/n format, for\n"
                    ."example 10.20.0.0/16 (a single address is written with /32, and IPv6 is not accepted).\n"
                    ."Then apply the change:\n"
                    .'  docker compose up -d nginx',
            ],
            'admin' => [
                'warning_unfiltered' => "If you want the panel open, there is nothing to fix: note it in the installation\n"
                    ."record (docs/cliente/endurecimiento.md, section 1.2, in Spanish).\n"
                    ."To accept it only from the hotel network or from your VPN, put its range in the\n"
                    .".env file and apply the change:\n"
                    ."  ADMIN_INTERNAL_CIDR=10.20.0.0/16\n"
                    ."  docker compose up -d nginx\n"
                    ."First check that your own computer is inside that range: outside it, the panel\n"
                    .'answers 403 and you would have to fix it from the server console.',
                'failure_invalid' => "Fix it in the .env file: one single range in the a.b.c.d/n format, for\n"
                    ."example 10.20.0.0/16, or leave it empty not to filter the panel by network.\n"
                    ."Then apply the change:\n"
                    .'  docker compose up -d nginx',
            ],
            'kiosk_vlan' => [
                'warning_open' => "Put only the tablets' network in the .env file and apply the change:\n"
                    ."  KIOSK_VLAN_CIDR=10.0.20.0/24\n"
                    .'  docker compose up -d nginx',
                'failure_invalid' => "Fix it in the .env file: one single range in the a.b.c.d/n format, for\n"
                    ."example 10.0.20.0/24 (a single address is written with /32, and IPv6 is not accepted).\n"
                    ."Then apply the change:\n"
                    .'  docker compose up -d nginx',
            ],
            'metrics' => [
                'warning_open' => "Put only the Prometheus network in the .env file (default 172.29.0.20/32) and\n"
                    ."apply the change:\n"
                    ."  METRICS_ALLOW_CIDR=172.29.0.20/32\n"
                    .'  docker compose up -d nginx',
                'failure_invalid' => "Fix it in the .env file: one single range in the a.b.c.d/n format, for\n"
                    ."example 172.29.0.20/32. Then apply the change:\n"
                    .'  docker compose up -d nginx',
            ],
        ],

        'access' => [
            'probe' => $probeFix,
            'pin_length' => [
                'warning' => "Switch to 8-digit PINs from the panel, with an administration account:\n"
                    ."  «Operational settings» -> «Access» -> «PIN length» -> 8 digits\n"
                    ."The change is recorded in the audit log. PINs already handed out keep\n"
                    ."working: HR resets them as they hand them out in person (check\n"
                    ."«access.short_pins»). If the portal should not be open to the internet, fix\n"
                    .'PORTAL_INTERNAL_CIDR (check «network.portal»).',
            ],
            'short_pins' => [
                'warning' => "HR resets each person's PIN and hands it over in person, when they come by the\n"
                    ."office; there is no need to do everyone on the same day:\n"
                    ."  Panel -> «Workforce» -> the person -> «Reset the PIN»\n"
                    ."The new PIN has 8 digits and the old one stops working at once.\n"
                    .'This number goes down with every reset; run this diagnosis again to follow it.',
            ],
            'two_factor_roles' => [
                'warning' => "Unless it is a deliberate decision, put the four roles in the .env file and\n"
                    ."apply the change:\n"
                    ."  IDENTITY_2FA_REQUIRED_ROLES=admin,rrhh,auditor,responsable_departamento\n"
                    ."  docker compose up -d app\n"
                    .'Anyone without a second factor yet will enrol it on their next panel sign-in.',
            ],
            'two_factor_pending' => [
                'warning' => "Ask those people to sign in to the panel as soon as possible: on that first\n"
                    ."sign-in the panel asks them to enrol the second factor with the app on their phone.\n"
                    ."Then review the «auth.two_factor_enabled» entries in the audit log: each\n"
                    ."enrolment carries the time and the IP it was made from. If the account holder does\n"
                    ."not recognise one, remove that second factor and change the account password:\n"
                    ."  docker compose exec app php artisan identity:2fa-reset <account-uuid>\n"
                    ."  docker compose exec app php artisan identity:reset-password <account-email>\n"
                    ."An account nobody uses any more, deactivate it:\n"
                    .'  docker compose exec app php artisan identity:deactivate-user <account-email>',
            ],
        ],

        'tls' => [
            'probe' => $probeFix,
            'certificate' => [
                'warning' => "Renew the certificate before it expires. Copy the new pair of files into the\n"
                    ."certificate directory (TLS_CERT_DIR in the .env file) and reload the web server:\n"
                    .'  docker compose restart nginx',
                'failure' => "Renew the certificate NOW. Copy the new pair of files into the certificate\n"
                    ."directory (TLS_CERT_DIR in the .env file) and reload the web server:\n"
                    ."  docker compose restart nginx\n"
                    ."The tablets will flush their queue on their own as soon as they reconnect: no clock-in is\n"
                    .'lost.',
                'warning_no_url' => 'Check APP_URL in the .env file: it must be a full address, such as '
                    .'https://timeclock.myhotel.local',
                'warning_not_https' => 'In production, APP_URL must start with https://. Fix it in the .env file '
                    .'and restart with `docker compose up -d app`.',
                'warning_unreachable' => "Check that the web server is up:\n"
                    ."  docker compose ps nginx\n"
                    .'If you are running this during an installation, this is expected: check again at the end.',
                'warning_unreadable' => 'Check the certificate files in TLS_CERT_DIR: the file may be truncated '
                    .'or not be a certificate at all.',
                'warning_self_signed' => "Install a certificate issued by an authority the tablets trust, or\n"
                    ."accept the self-signed one by setting in the .env file:\n"
                    ."  TLS_ALLOW_SELF_SIGNED=true\n"
                    .'On an internal hotel network, the second option is reasonable.',
            ],
        ],

        'permissions' => [
            'probe' => $probeFix,
            'storage' => [
                'failure' => "Give those directories back to the application user. From the installation\n"
                    ."directory:\n"
                    ."  docker compose exec -u root app chown -R www-data:www-data storage bootstrap/cache\n"
                    .'This usually happens after running a command as root.',
            ],
            'backup_path' => [
                'failure_missing' => "Create the directory and give it to the application user (uid 1000):\n"
                    ."  sudo install -d -o 1000 -g 1000 -m 0750 :path\n"
                    ."Check as well that BACKUP_PATH in the .env file points where you want. Then run\n"
                    ."./update.sh, which creates inside it the directories each container needs, or create them\n"
                    .'yourself as explained in docs/cliente/en/installation.md.',
                'failure_unreadable' => "Give the directory back to the application user (uid 1000) from the server:\n"
                    ."  sudo chown 1000:1000 :path && sudo chmod 0750 :path\n"
                    .'If it is a network share, check that the mount lets that user read it.',
                'warning_writable' => "Use the docker-compose.yml of this version, which mounts the backup directory\n"
                    ."read-only and gives each container write access only where it needs it. From the installation\n"
                    ."directory:\n"
                    ."  ./update.sh\n"
                    .'If you edited docker-compose.yml by hand, compare its mounts with the one in the package.',
            ],
            'backup_metrics' => [
                'failure' => "Give the directory back to the application user (uid 1000) from the server:\n"
                    ."  sudo chown 1000:1000 :path && sudo chmod 0750 :path\n"
                    .'If it is a network share, check that the mount lets that user write to it.',
                'failure_missing' => "Create it on the server as the application user (uid 1000):\n"
                    ."  sudo -u '#1000' mkdir -m 0750 -- :path\n"
                    .'Then recreate the containers: docker compose up -d',
            ],
            'backup_copies' => [
                'failure' => "Give those directories back to the application user (uid 1000) from the server:\n"
                    ."  sudo chown 1000:1000 <directory> && sudo chmod 0750 <directory>\n"
                    ."Then run a manual backup to confirm:\n"
                    .'  docker compose exec scheduler php artisan backup:run',
                'failure_missing' => "Create them on the server as the application user (uid 1000), one by one:\n"
                    ."  sudo -u '#1000' mkdir -m 0750 -- <directory>\n"
                    ."Then recreate the containers and run a manual backup to confirm:\n"
                    ."  docker compose up -d\n"
                    .'  docker compose exec scheduler php artisan backup:run',
                'warning_writable' => "Use the docker-compose.yml of this version, which lets only the backup task\n"
                    ."write the backups. From the installation directory:\n"
                    ."  ./update.sh\n"
                    .'If you edited docker-compose.yml by hand, compare its mounts with the one in the package.',
            ],
            'branding_root' => [
                'warning' => 'Check that the directory :path exists and that the application user can read it. '
                    .'If you do not use a custom logo, there is nothing to do.',
            ],
            'branding_logo' => [
                'warning_path' => 'Copy the PNG or the SVG to the BRANDING_PATH folder on the server (if it is '
                    .'empty, ./branding next to docker-compose.yml) and enter in the panel, Brand screen, its '
                    .'path as seen from inside the container: /var/kronoqr/branding/<file>. Detail: '
                    .'docs/cliente/en/configuration.md, section 2.2.',
                'warning_missing' => 'Check that the file is in the BRANDING_PATH folder on the server and that it '
                    ."can be read (chmod 0644). If this command does not show it:\n"
                    ."  docker compose exec app ls -l /var/kronoqr/branding\n"
                    .'the volume is not mounted: check BRANDING_PATH in the .env and recreate the three containers '
                    .'that use it with docker compose up -d app horizon scheduler. Detail: '
                    .'docs/cliente/en/configuration.md, section 2.2.',
                'warning_content' => 'Replace the file with a PNG, or with an SVG without a script (<script>), of '
                    .'moderate size and dimensions, in the same BRANDING_PATH folder, and save the path again in '
                    .'the panel, Brand screen: it is checked on saving and the panel gives the exact reason. '
                    .'Detail: docs/cliente/en/configuration.md, section 2.2.',
                'warning_unknown' => 'Run this command again once the database responds.',
            ],
        ],

        'disk' => [
            'probe' => $probeFix,
            'storage' => [
                'warning' => "Free up space before it becomes urgent. The usual culprits are the technical log\n"
                    ."and old Docker images:\n"
                    ."  docker system prune -a\n"
                    .'Check as well whether the backup directory lives on the same disk.',
                'failure' => "Free up space NOW. In order of usefulness:\n"
                    ."  docker system prune -a\n"
                    ."  du -sh :path/*  |  sort -h  |  tail -20\n"
                    .'If the disk fills up, the database stops accepting writes and nobody can clock in.',
                'warning_missing' => 'Check that the path :path exists and is mounted.',
                'warning_unknown' => 'Check that the path :path is mounted and accessible.',
            ],
            'backup' => [
                'warning' => "Review how many days of backups you keep (BACKUP_RETENTION_DAYS in the .env file)\n"
                    .'and whether the backup disk is large enough for that period.',
                'failure' => "Grow the backup disk or shorten the retention period (BACKUP_RETENTION_DAYS in the\n"
                    .".env file). DO NOT delete backups by hand without checking how many are left first: the\n"
                    .'minimum is in BACKUP_MIN_COPIES.',
                'warning_missing' => 'Check that the path :path exists and is mounted.',
                'warning_unknown' => 'Check that the path :path is mounted and accessible.',
            ],
        ],

        'backup' => [
            'probe' => $probeFix,
            'last_good_copy' => [
                'failure_stale' => "Check that the scheduler, which is what makes the backups, is running, and\n"
                    ."run a backup by hand: its output says exactly what is failing.\n"
                    ."  docker compose ps scheduler\n"
                    ."  docker compose exec scheduler php artisan backup:run\n"
                    .'The full procedure is in docs/runbooks/restaurar-backup.md. Clocking in does not depend on this.',
                'failure_last_failed' => "Run a backup by hand: its output says exactly what is failing.\n"
                    ."  docker compose exec scheduler php artisan backup:run\n"
                    .'The full procedure is in docs/runbooks/restaurar-backup.md. Clocking in does not depend on this.',
                'failure_verify_failed' => "Verify the last backup by hand to see the reason, and make a new one:\n"
                    ."  docker compose exec scheduler php artisan backup:verify\n"
                    ."  docker compose exec scheduler php artisan backup:run\n"
                    .'If the encryption key was changed, follow docs/runbooks/restaurar-backup.md.',
                'warning_never' => "If you have just installed, nothing: the first backup runs on its own tonight.\n"
                    ."If the installation is more than a day old, run a backup by hand and read its output:\n"
                    ."  docker compose ps scheduler\n"
                    .'  docker compose exec scheduler php artisan backup:run',
                'warning_unknown' => "The metrics directory must belong to user 1000:\n"
                    ."  ls -ld :path\n"
                    .'See docs/runbooks/restaurar-backup.md §4.3.',
            ],
        ],

        'files' => [
            'probe' => $probeFix,
            'storage_volume' => [
                'failure' => "Give the directory back to the application user. From the installation directory:\n"
                    .'  docker compose exec -u root app chown app:app :path',
                'failure_not_mounted' => "The docker-compose.yml does not mount the app-storage volume. Use the one\n"
                    ."shipped with this version, which mounts it in app, horizon and scheduler, and recreate them:\n"
                    ."  docker compose up -d app horizon scheduler\n"
                    .'Then run ./doctor.sh, which checks that the three see the same files.',
            ],
            'retention_reports' => [
                'warning' => "Give the application user (uid 1000) write access to :path and check that the\n"
                    .'backup directory is not mounted read-only.',
                'warning_missing' => "Create it on the server as the application user (uid 1000), its parent reports\n"
                    ."first if that does not exist either:\n"
                    ."  sudo -u '#1000' mkdir -m 0750 -- :path\n"
                    .'Then recreate the containers (docker compose up -d). Without it the readable copy of the report is '
                    .'not kept, and the purge still leaves its entry in the audit log.',
                'warning_horizon_writable' => "Use the docker-compose.yml of this version, which does not mount that\n"
                    ."directory writable in horizon. From the installation directory:\n"
                    ."  ./update.sh\n"
                    .'If you edited docker-compose.yml by hand, compare its mounts with the one in the package.',
                'warning_inside_storage' => "Retention reports belong in BACKUP_PATH/reports/retention, where a person\n"
                    ."reads them without entering the container. Remove the key from the .env file to use the default,\n"
                    .'and recreate the containers.',
            ],
            'class_roots' => [
                'failure_overlap' => "Leave each variable at its default (remove it from .env) or give it a\n"
                    ."directory of its own inside /var/www/html/storage/app. Then recreate the containers:\n"
                    .'  docker compose up -d app horizon scheduler',
                'failure_storage_root' => "Remove :name from .env to return to its default, or point it to a\n"
                    .'subdirectory of its own in /var/www/html/storage/app. Then recreate the containers.',
                'failure_backup_path' => "Remove :name from .env to return to its default: these files are not\n"
                    .'kept with the backups. Then recreate the containers.',
                'warning_outside_volume' => "Remove those variables from .env to return to their defaults, or point\n"
                    .'them to a subdirectory of /var/www/html/storage/app. Then recreate the containers.',
                'failure_outside_volume' => "Remove those variables from .env to return to their defaults, or point\n"
                    .'them to a subdirectory of /var/www/html/storage/app. Then recreate the containers.',
            ],
            'stray_entries' => [
                'warning' => "If you changed PRODUCT_DATA_EXPORT_PATH or REPORTING_EXPORT_PATH, empty the old\n"
                    ."folder: it holds personal data nobody is going to delete anymore. To see it:\n"
                    .'  docker compose exec app ls -la /var/www/html/storage/app',
            ],
            'legal_exports_console' => [
                'warning' => "If you have already handed those exports to the Labour Inspectorate, delete them.\n"
                    ."From the installation directory, to list them:\n"
                    ."  docker compose exec app ls -l :path\n"
                    ."and to delete one:\n"
                    ."  docker compose exec app rm :path/<file>\n"
                    .'Details: docs/runbooks/requerimiento-inspeccion.md, section 7.',
            ],
        ],

        'app' => [
            'probe' => $probeFix,
            'timezone_utc' => [
                'failure' => "Set in the .env file:\n"
                    ."  APP_TIMEZONE=UTC\n"
                    ."and restart the application:\n"
                    ."  docker compose up -d app\n"
                    ."The screens will keep showing the hotel time: the site time zone is configured in the\n"
                    .'panel, under Settings, not here.',
            ],
            'debug_in_production' => [
                'failure' => "Set in the .env file:\n"
                    ."  APP_DEBUG=false\n"
                    ."and restart the application:\n"
                    .'  docker compose up -d app',
            ],
            'error_history' => [
                'warning' => "Open the panel, go to Settings -> Errors, and deal with or mark as resolved the\n"
                    ."errors from that source. To see them from here:\n"
                    ."  php artisan product:errors --since=30d --source=:source\n"
                    .'Raising the maximum fixes nothing: what those errors need is someone looking at them.',
                'failure' => "You are losing detail about what is failing. Open the panel, go to Settings ->\n"
                    ."Errors, filter by source \":source\" and mark as resolved the ones you have already\n"
                    ."dealt with; as soon as they drop below the maximum, new errors are stored separately\n"
                    ."again. To see them from here:\n"
                    ."  php artisan product:errors --since=30d --source=:source\n"
                    ."If you really need to store more, raise the limit in the .env file:\n"
                    ."  PRODUCT_ERRORS_MAX_OPEN_GROUPS_PER_SOURCE=1000\n"
                    .'and restart with  docker compose up -d app',
                'warning_busy' => "Check whether an error repeats with a different text each time: that creates\n"
                    ."a new line per repetition instead of adding up in one. Look at it with:\n"
                    ."  php artisan product:errors --since=7d\n"
                    ."Clearing out the ones older than 90 days happens on its own every night; to run it\n"
                    ."now:\n"
                    .'  php artisan product:errors:prune',
                'warning_unavailable' => "Check that the database responds and that the installation is fully\n"
                    ."up to date:\n"
                    .'  php artisan migrate --force',
            ],
        ],

        'settings' => [
            'probe' => $probeFix,
            'invalid_keys' => [
                'warning' => 'Go to the panel, under Settings, and save those settings again: :keys',
                'failure' => "Go to the panel, under Settings, and save those settings again: :keys\n"
                    ."Until you do, the system is calculating hours with the factory value. Afterwards, check\n"
                    .'that the hours of the last few days are what you expect.',
            ],
            'env_differs_from_db' => [
                'warning' => "Nothing is broken. If the value you want is the one in the .env file, change it in\n"
                    ."the panel under Settings, which is what wins. If the one you want is the panel value,\n"
                    .'remove or fix those lines in the .env file so they stop misleading whoever reads it.',
            ],
        ],

        'kiosk' => [
            'probe' => $probeFix,
            'service_code' => [
                'warning' => "Go to the panel, under Operational settings, and type a code of 8 to 12 digits in\n"
                    ."«Kiosk service code». The tablets pick it up on their own in under a minute.\n"
                    ."Write it down wherever whoever maintains the kiosks keeps it, and do not stick it on the\n"
                    .'tablet itself. If you would rather leave the screen open to everyone, ignore this warning.',
                'warning_unavailable' => "Run `php artisan product:doctor` again once the database responds.\n"
                    .'If it persists, look first at the database checks in this same report.',
            ],
            'app_version' => [
                'warning' => "Find each tablet by its identifier in the panel, under Kiosks.\n"
                    ."If it reports 2.2.1 or later, do nothing: it updates itself during the update window\n"
                    ."(KIOSK_UPDATE_WINDOW, 03:00 to 05:00 by default) with an empty queue and no recent clock-ins.\n"
                    ."If it reports 2.2.0 or earlier (or 0.0.0), it will not update itself. ONLY with the tablet online,\n"
                    ."its pending queue at 0 in the panel and no «in-memory queue» warning: on the tablet, open\n"
                    ."chrome://serviceworker-internals and press Unregister on the KronoQR one (or F12 > Application >\n"
                    ."Service workers > Unregister) and reload the page.\n"
                    .'Do NOT clear the site data: it holds the queue of unsent clock-ins and the tablet pairing.',
                'warning_draining' => "Wait. Do NOT reload these tablets, do NOT unregister their service worker and do NOT\n"
                    ."clear the site data: with an in-memory queue, reloading deletes the clock-ins not yet sent.\n"
                    ."Check in the panel, under Kiosks, that they are online and that their pending queue drops to 0;\n"
                    ."if the «in-memory queue» warning shows, follow the stuck queue runbook, section 7.\n"
                    .'Once the queue is at 0 and on disk, run `php artisan product:doctor` again.',
                'warning_mixed' => "Those with unsent clock-ins or an in-memory queue: wait. Do NOT reload them, do NOT\n"
                    ."unregister their service worker and do NOT clear the site data, or clock-ins would be lost; if the\n"
                    ."«in-memory queue» warning shows, follow the stuck queue runbook, section 7.\n"
                    ."Those with an empty, on-disk queue: if they report 2.2.1 or later, they update themselves during the\n"
                    ."update window (KIOSK_UPDATE_WINDOW, 03:00 to 05:00 by default). If they report 2.2.0 or earlier\n"
                    ."(or 0.0.0), and ONLY with the tablet online and the queue at 0: open chrome://serviceworker-internals,\n"
                    ."press Unregister on the KronoQR one (or F12 > Application > Service workers > Unregister) and reload.\n"
                    .'Do NOT clear the site data: it holds the clock-in queue and the tablet pairing.',
                'warning_unchecked' => "Check the server's APP_VERSION (or IMAGE_TAG) variable: it must be the published\n"
                    ."version, for example 2.2.1. An image built without it reports 0.0.0-dev.\n"
                    .'Redeploy with the image of the published version and run `php artisan product:doctor` again.',
                'warning_unavailable' => "Run `php artisan product:doctor` again once the database responds.\n"
                    .'If it persists, look first at the database checks in this same report.',
            ],
        ],

        'license' => [
            'probe' => $probeFix,
            'state' => [
                'warning_plan_exceeded' => 'Talk to your provider about extending the plan whenever it suits '
                    .'you. There is no hurry and nothing is blocked.',
                'warning_expiring_soon' => "Ask your provider for a renewal. Once the new key arrives:\n"
                    ."  php artisan license:activate \"KQL1....\"\n"
                    .'or paste it in the panel, under Settings > Licence.',
                'warning_expired' => "Ask your provider for a renewal and activate the new key:\n"
                    ."  php artisan license:activate \"KQL1....\"\n"
                    .'Meanwhile clocking in and exporting the record keep working normally.',
                'warning_absent' => "Activate the key your provider gave you:\n"
                    ."  php artisan license:activate \"KQL1....\"\n"
                    .'If you cannot find it, ask for it: it is a string starting with KQL1.',
                'warning_not_yet_valid' => 'Nothing. The optional features switch on by themselves on the day '
                    .'the validity period starts.',
                'warning_unverifiable' => "Run `php artisan license:show`, which explains the exact reason and\n"
                    .'what to do about it. If it says the key is truncated, copy it again in full and activate it.',
            ],
            'white_label_without_plan' => [
                'warning' => 'If you want your branding on the screens, talk to your provider about including '
                    .'it in the plan. If not, clear the name, colour and logo in the panel under Settings so '
                    .'this warning stops showing.',
            ],
        ],
    ],
];
