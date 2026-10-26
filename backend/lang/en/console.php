<?php

declare(strict_types=1);

/*
 * The output of the console commands the hotel operator reads.
 *
 * One section per command, named after the command in lower case with
 * underscores. Commands ask for them through `SpeaksInstallationLanguage`, in
 * the installation language (ADR-017), not in the installer's `APP_LOCALE`.
 *
 * What does NOT go here: the `key=value` lines that the operation scripts read
 * (`update.sh` looks for `revoked_credentials=` by pattern) and the `--json`
 * documents. Those are never translated: a script does not speak languages.
 */

return [

    'license_show' => [
        'title' => 'KronoQR licence',
        'unknown' => [
            'state' => 'State: UNKNOWN (the database is not answering; the system keeps working)',
            'advice' => [
                'It does not mean there is no licence. Do not reactivate it: fix the database first',
                '(`docker compose exec app php artisan product:doctor`) and look again.',
            ],
        ],
        'states' => [
            'valid' => 'VALID',
            'expiring_soon' => 'VALID, expires soon',
            'expired' => 'EXPIRED (the system keeps working)',
            'absent' => 'NO LICENCE (the system keeps working)',
            'not_yet_valid' => 'NOT YET VALID',
            'unverifiable' => 'CANNOT BE VERIFIED (the system keeps working)',
        ],
        'summary' => [
            'state' => 'State: :state',
            'customer' => 'Customer: :customer',
            'plan' => 'Plan:     :plan',
            'validity' => 'Valid: from :from to :until (UTC)',
            'expired_ago' => 'Expired :days day(s) ago.',
            'remaining' => ':days day(s) left. Warning from :warning days before.',
            'activated' => 'Key activated on :day, fingerprint :fingerprint',
            'last_verified' => 'Last successful verification: :day',
        ],
        'plan' => [
            'title' => 'Contracted plan versus actual use',
            'row' => 'contracted: :contracted  actual: :actual :exceeded',
            'exceeded' => '  ← EXCEEDED by :excess',
            'not_blocking' => [
                'The excess has NOT prevented any new hire or any pairing, and it never will.',
                'It is recorded in the audit log with the exact date; talk to your supplier',
                'to extend the plan.',
            ],
        ],
        'limits' => [
            'max_employees' => 'People on the staff',
            'max_devices' => 'Active kiosks',
        ],
        'degradation' => [
            'title' => 'Ancillary features',
            'none' => 'Everything contracted is available.',
            'pending' => [
                '(Another :count feature(s) of the catalogue do not exist in this',
                ' version yet, so nothing is lost with them.)',
            ],
        ],
        'features' => [
            'advanced_reports' => 'Period reports and comparison with the contract',
            'impact_dashboard' => 'Impact and adoption dashboard',
            'payroll_export' => 'Payroll export',
            'weekly_email_summary' => 'Weekly email summary',
            'realtime_presence' => 'Real-time presence (falls back to polling)',
            'white_label' => 'Own branding (falls back to the product branding)',
            'telemetry' => 'Optional telemetry',
        ],
        'restrictions' => [
            'since' => ' (since :day)',
            'license_expired' => 'not available because the licence has expired:since',
            'license_absent' => 'not available: no licence has been activated',
            'license_unverifiable' => 'not available: the licence cannot be verified',
            'license_not_yet_valid' => 'not available until the licence period starts:since',
            'not_in_plan' => 'not included in the contracted plan',
            'other' => 'not available',
        ],
        'never_degraded' => [
            'title' => 'What NEVER depends on the licence',
            'body' => [
                'QR and PIN clocking, kiosk queue synchronisation, browsing working days and',
                'shift entries, the employee portal, the export for the Labour Inspectorate,',
                'corrections with their reason, the audit log, backups and their restore,',
                'and the health probes.',
                'They work with the licence expired, missing or unreadable. It is a promise of',
                'the product, not an accident of this version.',
            ],
        ],
        'what_to_do' => [
            'title' => 'What to do',
            'valid' => ['Nothing. Look again when the expiry date gets close.'],
            'expiring_soon' => [
                'Ask your supplier for the renewal. When the new key arrives:',
                '    php artisan license:activate "KQL1...."',
                'or paste it in the panel, under Settings > Licence.',
            ],
            'expired' => [
                'Ask your supplier for the renewal and activate the new key:',
                '    php artisan license:activate "KQL1...."',
                'In the meantime clocking goes on and the record can be exported as usual.',
            ],
            'absent' => [
                'Activate the key your supplier gave you:',
                '    php artisan license:activate "KQL1...."',
                'If you cannot find it, ask for it: it is a string that starts with KQL1.',
            ],
            'not_yet_valid' => [
                'The key is correct but its validity starts later. There is nothing to do:',
                'the ancillary features switch on by themselves on that day.',
            ],
            'exceeded' => [
                'Also, you are above some figure of the plan. There is no hurry and it blocks',
                'nothing: contact your supplier to extend it when it suits you.',
            ],
        ],
        'unverifiable' => [
            'malformed' => [
                'The stored key is incomplete or truncated. It usually happens when copying it from an email.',
                'Copy it again in full —it starts with KQL1. and has no spaces— and activate it again.',
            ],
            'bad_signature' => [
                'The stored key was not issued by the maker of this version, or it has been modified.',
                'Ask your supplier for a new key and activate it.',
            ],
            'invalid_payload' => [
                'The key is signed but some information is missing. It is an issuing fault, not yours:',
                'tell your supplier the fingerprint shown above and ask for a new key.',
            ],
            'no_public_key' => [
                'This installation does not carry the maker\'s public key, so it cannot verify',
                'any licence. It is not a problem with your key: it is the deployment.',
                'Tell your supplier the version returned by GET /api/v1/health.',
            ],
            'other' => ['Tell your supplier: the stored licence cannot be verified.'],
        ],
    ],

    'license_activate' => [
        'no_key' => [
            'title' => 'You have not given any key and LICENSE_KEY is empty in the environment.',
            'advice' => [
                'Usage:  php artisan license:activate "KQL1...."',
                'Your supplier gives you the key: it is a string that starts with KQL1.',
            ],
        ],
        'rejected' => [
            'title' => 'The key has NOT been activated. The previous licence stays as it was.',
            'malformed' => [
                'The key is incomplete or truncated. It usually happens when copying it from an email.',
                'Copy it in full: it starts with KQL1. and has no spaces or line breaks.',
            ],
            'bad_signature' => [
                'The signature does not match: the key was not issued by the maker of this version,',
                'or it has been modified. Ask your supplier for a new key.',
            ],
            'invalid_payload' => [
                'The key is signed but its content is not usable. It is an issuing fault:',
                'tell your supplier and ask for a new key.',
            ],
            'no_public_key' => [
                'This installation does not carry the maker\'s public key, so it cannot',
                'verify any licence. It is not a problem with your key, it is the deployment:',
                'tell your supplier the version returned by GET /api/v1/health.',
            ],
            'clocking_unaffected' => 'None of this affects clocking or access to the record: they keep working.',
        ],
        'invalid' => [
            'title' => 'The key is signed but some information is missing. It is an issuing fault.',
            'detail' => 'Technical detail for the supplier: :detail',
        ],
        'activated' => [
            'title' => 'Licence activated.',
            'customer' => 'Customer: :customer',
            'plan' => 'Plan:     :plan',
            'state' => 'State:    :state',
            'next' => 'Run  php artisan license:show  to see the full detail.',
            'already_expired' => [
                'Watch out: this key HAS ALREADY EXPIRED. It has been stored anyway and is recorded',
                'in the audit log. The ancillary features remain degraded.',
            ],
            'not_yet_valid' => [
                'Watch out: this key becomes valid later. It has been stored and will',
                'switch on by itself on that day.',
            ],
        ],
    ],

    'product_diagnostics' => [
        'deliver' => [
            'not_deferred' => [
                'The bundle has not been generated: the database is not answering and the record',
                'of its generation could not be kept to write it to the audit log later.',
            ],
            'not_written' => 'The bundle could not be written.',
        ],
        'not_audited' => [
            'personal_data_without_database' => [
                'The bundle WITH PERSONAL DATA has not been generated: the database is not answering and',
                'that bundle is not generated because it cannot be audited (RL-19). Nothing has been written.',
            ],
            'personal_data_alternative' => [
                'If you need the bundle now, generate the anonymised one, which works without a database:',
                '  php artisan product:diagnostics',
                'When the database is back, run this command again if you still need the personal data.',
            ],
            'other' => [
                'The bundle has not been generated: its generation could not be recorded in',
                'the audit log (error :error). Nothing has been written.',
                'Run php artisan product:doctor and check the database.* checks; if they are all',
                'green, contact support with that output.',
            ],
        ],
        'pending_recorded' => [
            ':count pending audit entr(y/ies) of bundles generated while the database',
            'was not answering have been written to the audit log.',
        ],
        'period_out_of_range' => [
            'The period must be between 1 and :maximum days; you asked for :days.',
            'Run it again with, for example: php artisan product:diagnostics --with-personal-data --period-days=7',
        ],
        'confirm_personal_data' => 'Are you sure you want to include the personal data?',
        'personal_data_warning' => [
            'title' => 'WARNING: you are about to generate a bundle WITH PERSONAL DATA.',
            'contents' => [
                'Besides the usual technical information, the file will include:',
                '  - The staff: name, employee code, status and department.',
                '  - The clockings and shift entries of the last :days days.',
                '  - The open incidents.',
            ],
            'implications' => [
                'What it means:',
                '  - The file is no longer anonymised and must not be sent without need.',
                '  - The action is recorded in the audit log, signed by the account',
                '    given in --responsible.',
                '  - If you send it to the supplier, they act as data processor',
                '    for that purpose (GDPR art. 28). You need the signed processing agreement.',
            ],
            'alternative' => [
                'If you only want to diagnose a technical problem, you do not need it: run',
                '  php artisan product:diagnostics',
            ],
        ],
        'anonymous' => [
            'yes' => 'yes',
            'no' => 'NO — it carries personal data',
        ],
        'report' => [
            'purged' => [
                ':count older bundle(s) have been deleted for age. A diagnostics bundle',
                'is expired material as soon as it is sent, and the one with personal',
                'data must not stay on the disk (RL-19).',
            ],
            'title' => 'Diagnostics bundle generated.',
            'file' => '  File:      :path',
            'size' => '  Size:      :size KB',
            'digest' => '  Digest:    :digest',
            'sections' => '  Contains:  :sections',
            'anonymous' => '  Anonymous: :answer',
            'entry_pending' => [
                'The database is not answering: the audit entry of this bundle stays',
                'PENDING and will be written by itself as soon as the database is back (you need not do anything).',
                'The bundle itself says so in audit.generation_entry, inside its digest.',
                'The sections that depend on the database come out as "unavailable".',
            ],
            'check_before_sending' => [
                'Before sending it, open it and check it carries nothing you do not want to send:',
                '  less :path',
            ],
            'verify_hint' => [
                'Whoever receives it can check it has not been altered on the way with:',
                '  php artisan product:diagnostics --verify=:path',
            ],
            // In both languages on purpose, in this catalogue too: the reader
            // may be the hotel's IT or the maker's support.
            'anonymized_scope' => [
                'Anonimizado: sin nombres, documentos ni identificadores de empleado; el texto',
                'de los errores, solo con palabras técnicas. Ábrelo antes de enviarlo.',
                'Anonymised: no names, documents or employee identifiers; error text reduced',
                'to technical words. Open it before sending.',
            ],
        ],
        'verify' => [
            'unreadable' => [
                'A diagnostics bundle could not be read at :path.',
                'Check that the path exists, that it is readable and that the file is the complete JSON:',
                'a file truncated by email cannot be verified.',
            ],
            'mismatch' => [
                'title' => 'THE DIGEST DOES NOT MATCH: :path',
                'advice' => [
                    'The file is not the one that was generated, or part of it is missing. The most',
                    'common cause is that it was truncated when sent by email. Ask the customer to',
                    'generate it again and send it compressed, or through the ticket portal.',
                ],
            ],
            'intact' => 'Intact bundle: :path',
            'version' => '  Version:    :version',
            'generated' => '  Generated:  :at by :by',
            'anonymous' => '  Anonymous:  :answer',
            'digest' => '  Digest:     :digest',
            'without_manifest' => [
                '              digest without manifest: it covers the sections, not the header. That it says',
                '              «anonymous» is not checked; look at the sections before treating it as anonymous.',
            ],
            'entry' => '  Entry:      :entry',
            'entry_pending' => 'PENDING: the database was not answering when it was generated; it is written by itself when it comes back',
            'entry_written' => 'written to the audit log when it was generated',
        ],
    ],

    'product_diagnostics_record_pending' => [
        'recorded' => 'Pending diagnostics audit entries written: :count.',
        'remaining' => 'Diagnostics audit entries still pending: :count (error :error).',
        'unknown_error' => 'unknown',
        'retry' => [
            'It will be retried on the next run. If the database is answering and this repeats,',
            'run php artisan product:doctor and check the database.* checks.',
        ],
    ],

    'product_export_all' => [
        'unidentified' => 'unidentified',
        'in_progress' => [
            'There is already a full export in progress (:uuid).',
            'Wait for it to finish and run this again, or look at them with:',
            '  php artisan tinker --execute="dump(DB::table(\'data_exports\')->latest(\'id\')->first());"',
        ],
        'generating' => 'Generating the full export. It walks every table: it may take several minutes.',
        'failed' => [
            'The full export could not be generated: :error.',
            'The most common cause is lack of disk space or permissions on the exports',
            'directory. Check them with «df -h» and «ls -ld» on PRODUCT_DATA_EXPORT_PATH,',
            'and run «php artisan product:doctor» to see the rest of the installation status.',
        ],
        'without_file' => 'The export finished without a file. Check the status with «php artisan product:doctor».',
        'report' => [
            'title' => 'Full export generated.',
            'file' => '  File:       :path',
            'size' => '  Size:       :size',
            'digest' => '  SHA-256:    :digest',
            'uuid' => '  Identifier: :uuid',
            'expires' => '  Expires:    :at UTC',
            'rows' => 'Rows per file:',
            'copy_out' => [
                'To take it out of the container, from the installation directory on the server:',
                '  docker compose cp app::path ./:name',
            ],
            'check' => [
                'To check it arrived complete:',
                '  sha256sum ./:name',
            ],
            'personal_data' => [
                'The file contains ALL the personal data of the staff. Keep it where you keep',
                'the rest of the employment records. Here it is deleted only when it expires.',
            ],
        ],
    ],

    'support_grant' => [
        'unknown_scope' => 'The scope «:scope» does not exist.',
        'valid_scopes' => 'Valid scopes: :scopes.',
        'not_granted' => 'Nothing has been granted.',
        'usage' => 'Usage:  php artisan support:grant --reason="Incident #123" --hours=24',
        'granted' => [
            'title' => 'Support access granted.',
            'grant' => 'Grant:     :uuid',
            'scope' => 'Scope:     :scope',
            'author' => 'On behalf of: :name',
            'expires' => 'Expires:   :at (UTC)',
            'shown_once' => [
                'THIS TOKEN IS SHOWN ONLY ONCE. It is not stored anywhere and cannot',
                'be requested again. If you lose it, revoke this grant and create another.',
            ],
            'delivery' => [
                'Hand it over through the support channel of your contract. To withdraw it',
                'early:  php artisan support:revoke :uuid',
                'The grant, its use and its revocation are recorded in the audit log.',
            ],
        ],
        'author' => [
            'not_found' => 'There is no active management account with the email «:email».',
            'ambiguous' => [
                'title' => 'There are several management accounts (or none) and you must say on whose behalf it is granted.',
                'why' => [
                    'Letting the maker in is an act of a specific person, and that',
                    'is written in the audit log.',
                ],
                'usage' => 'Usage:  php artisan support:grant --reason="..." --as=person@hotel.example',
            ],
            'sole' => 'Granted on behalf of the only active management account: :name.',
        ],
    ],

    'support_revoke' => [
        'missing' => [
            'title' => 'Say which grant you want to revoke, or use --all to revoke them all.',
            'usage' => [
                'Usage:  php artisan support:revoke 0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f60',
                '        php artisan support:revoke --all',
            ],
        ],
        'none_active' => 'There is no active support access. Nothing to revoke.',
        'all' => [
            'one' => 'Revoked: :uuid  (:scope)',
            'title' => ':count support access(es) revoked. Their tokens stop working now.',
            'kept' => 'The grants are kept with their revocation date, and it is in the audit log.',
        ],
        'one' => [
            'revoked' => 'Access revoked. Its token stops working on the next request.',
            'kept' => 'The grant is kept with its revocation date (nothing is deleted).',
            'already_revoked' => 'That grant was already revoked. There was nothing to do.',
            'not_found' => 'There is no grant with the identifier «:uuid».',
            'where' => 'The panel gives the identifiers of the active grants, under Support.',
        ],
    ],

];
