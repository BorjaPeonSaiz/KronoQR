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
];

$probeFix = [
    'failure' => "This is a product defect, not a problem with your installation.\n"
        ."Generate the diagnostics bundle and send it to support:\n"
        .'  php artisan product:diagnostics',
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
                'failure' => 'Redis does not respond. Without it the job queue, the cache and the panel sessions '
                    .'do not work.',
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
                'ok' => 'Something is consuming the job queue.',
                'warning' => 'At :checked_at UTC there were :count jobs waiting and none in progress. The process '
                    .'that consumes the queue is probably not running.',
                'warning_unknown' => 'Whether the queue worker is alive could not be determined.',
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
            'backup_path' => [
                'ok' => 'The backup directory is writable.',
                'failure' => 'The backup directory :path is not writable. BACKUPS ARE NOT BEING TAKEN, and '
                    .'there is no other symptom until the day you need one.',
                'failure_missing' => 'The backup directory :path does not exist. BACKUPS ARE NOT BEING TAKEN.',
            ],
            'branding_root' => [
                'ok' => 'The logo directory is accessible.',
                'warning' => 'The logo directory :path cannot be read. The applications will show the product '
                    .'branding instead of the hotel branding. Nothing else is affected.',
            ],
            'branding_logo' => [
                'ok' => 'The configured logo can be read.',
                'warning' => 'The configured logo cannot be used (:reason). The applications will show the '
                    .'product branding. Nothing else is affected.',
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
                    .'People can keep clocking in meanwhile, but the panel may ask to sign in again.',
            ],
            'backlog' => [
                'warning' => "Check whether the queue worker is alive:\n"
                    ."  docker compose ps worker\n"
                    .'If it is, this is a normal spike and it will drain on its own. Check again in ten minutes.',
                'failure' => "Restart the queue worker:\n"
                    ."  docker compose restart worker\n"
                    ."  docker compose logs --tail=100 worker\n"
                    ."If jobs are failing rather than piling up, list the failed ones with:\n"
                    .'  php artisan queue:failed',
                'warning_unknown' => 'Check that Redis responds and run this command again.',
            ],
            'worker' => [
                'warning' => "Start or restart the queue worker:\n"
                    ."  docker compose ps worker\n"
                    ."  docker compose restart worker\n"
                    .'Clocking in does not depend on it; notifications and reports do.',
                'warning_unknown' => 'Check that Redis responds and run this command again.',
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
                'failure' => "Give the application user write permission on :path and check that the volume is\n"
                    ."not mounted read-only. Then run a manual backup to confirm:\n"
                    .'  ./backup.sh',
                'failure_missing' => "Create the directory and give it to the application user:\n"
                    ."  sudo install -d -m 0750 :path\n"
                    ."Check as well that BACKUP_PATH in the .env file points where you want. Then:\n"
                    .'  ./backup.sh',
            ],
            'branding_root' => [
                'warning' => 'Check that the directory :path exists and that the application user can read it. '
                    .'If you do not use a custom logo, there is nothing to do.',
            ],
            'branding_logo' => [
                'warning' => 'Upload the logo again from the panel, under Settings. Accepted formats are PNG '
                    .'and SVG.',
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
