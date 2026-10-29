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

    'identity_accounts' => [
        'temporary_password' => 'It is a TEMPORARY password: it expires on :expires UTC and its holder will have to '
            .'change it when signing in. Write it down now: it cannot be looked up again. It is handed over in person, '
            .'never by email or messaging.',
        'create' => [
            'ask_name' => 'Name',
            'ask_email' => 'Email',
            'ask_role' => 'Role',
            'email_taken' => 'There is already a management account with that email, active or deactivated.',
            'created' => 'Management account created with role :role and UUID :uuid.',
        ],
        'deactivate' => [
            'not_found' => 'There is no management account with that email.',
            'already_inactive' => 'That account was already deactivated. Nothing has changed and no audit entry has been written.',
            'own_account' => 'You cannot deactivate your own account.',
            'last_admin' => 'It is the last active admin account of the installation. Create another with identity:create-user '
                .'before deactivating this one. Nothing has changed.',
            'done' => 'Account deactivated. Its open sessions are no longer valid and it will not be able to sign in again.',
            'not_deleted' => 'The account has NOT been deleted: its history and audit entries are still there, and it still counts '
                .'to keep the public sign-up of the first administrator closed.',
        ],
        'list' => [
            'invalid_filter' => 'Invalid filter. --status: active or deactivated; --role: a management role.',
            'columns' => ['UUID', 'Email', 'Role', 'Status', '2FA', 'Password', 'Last sign-in (UTC)'],
            'yes' => 'yes',
            'no' => 'no',
            'total' => ':count account(s).',
        ],
        'reset_password' => [
            'not_found' => 'There is no active management account with that email.',
            'done' => 'Password reset. The open sessions of that account are no longer valid.',
        ],
        'reset_two_factor' => [
            'not_enrolled' => 'That account had no active second factor: there is nothing to remove. No audit entry has been written.',
            'not_found' => 'There is no active management account with that UUID.',
            'done' => 'Second factor removed. Account :uuid will have to enrol it on its next sign-in.',
        ],
    ],

    'identity' => [
        'origin_unlock' => [
            'invalid' => 'It is not an IPv4 or IPv6 address.',
            'unlocked' => 'Lock and failure count of that origin cleared. It is recorded in the audit log.',
            'nothing' => 'That origin had no lock or pending failures. It is recorded in the audit log.',
        ],
        'dormant' => ':count active management account(s) without signing in for more than :days days:roles.',
    ],

    'credentials' => [
        'employee_required' => 'The employee UUID is required.',
        'employee_not_found' => 'There is no employee with that UUID.',
        'credential_required' => 'The credential UUID is required.',
        'credential_not_found' => 'There is no credential with that UUID.',
        'issue' => [
            'use_reissue' => 'Use --reissue --reason="reason" to replace it.',
            'issued' => 'Credential issued: :uuid',
            'reissued' => 'Credential reissued: :uuid',
            'pending' => 'State: pending print. Nobody can clock with it yet.',
            'next' => 'The QR is minted when printing: php artisan credentials:print :employee',
        ],
        'revoke' => [
            'reason_required' => 'A revocation must state its reason: --reason="lost".',
            'revoked' => 'Credential revoked: :uuid',
            'reason' => 'Reason: :reason',
        ],
        'deliver' => [
            'delivered' => 'Delivery recorded: :uuid',
            'responsible' => 'Responsible: :email',
            'moment' => 'At: :at',
            'reminder' => 'Remember to hand over the portal PIN and the instructions sheet as well: docs/runbooks/alta-nuevo-empleado.md',
            'by_retired' => '--by was removed in 2.3.0: the delivery is signed by the account in --responsible (its uuid or its email), which confirms who it is. Nothing has been recorded.',
            'confirm' => 'Record the delivery of card :uuid with this signature?',
        ],
        'print' => [
            'out_required' => '--out=/path/credential.pdf is required: this command does not choose where to leave a card PDF.',
            'no_reprint' => 'There is no reprint (ADR-034). To replace a card: revoke, reissue and print the new one.',
            'no_active_credential' => 'That employee does not exist or has no active credential.',
            'issue_first' => 'Issue it first: php artisan credentials:issue :employee',
            'not_written' => 'The PDF was generated but could not be written to «:path».',
            'printed_one' => 'The credential is recorded as PRINTED and its token cannot be recovered.',
            'runbook' => 'Follow docs/runbooks/tarjeta-perdida-o-rota.md: revoke with reason «failed print» and reissue.',
            'generated' => 'Card generated: :path',
            'credential' => 'Credential: :uuid',
            'handle_with_care' => 'Print, laminate and DELETE the file: it is a bearer instrument.',
            'next' => 'Then record the delivery: php artisan credentials:deliver :uuid --responsible=<email>',
            'pdf_busy' => 'The PDF engine has no free slot right now: no card has been printed. Run the command again in :seconds seconds.',
            'pdf_unavailable' => 'The PDF engine cannot work because Redis is not responding: no card has been printed. Check Redis on the installation and run the command again.',
        ],
        'print_batch' => [
            'all_or_nothing' => 'The batch is all or nothing: no card has been printed. Run it again.',
            'nothing_pending' => 'There is no credential pending print.',
            'printed_many' => 'The :count credentials are recorded as PRINTED and their tokens cannot be recovered.',
            'generated' => ':count card(s) on a single sheet: :path',
            'handle_with_care' => 'Print, cut along the guides and DELETE the file.',
            'next' => 'Then record each delivery: php artisan credentials:deliver <credential> --responsible=<email>',
            'pending_required' => '--pending is required. It is the only possible selection: there is no reprint (ADR-034).',
            'out_required' => '--out=/path/credentials.pdf is required: this command does not choose where to leave a card PDF.',
        ],
        'retire_key' => [
            'key_required' => 'The key_id of the key being retired is required.',
            'retired' => 'Key :key no longer signs any active credential (:count cards in total over its lifetime).',
            'last_step' => [
                'Last step, on the server:',
                '  - Empty QR_SIGNING_KEY_PREVIOUS_ID and QR_SIGNING_KEY_PREVIOUS.',
                '  - Restart the application and check that clocking works as usual.',
                '  - Destroy the copy of the retired key in the secrets manager.',
            ],
        ],
        'rotate_key' => [
            'keys' => 'Retiring key: :retiring · current key: :current',
            'cards' => 'Active cards signed with :retiring: :count',
            'dry_run' => 'Dry run: :count credentials would be reissued.',
            'dry_run_pending' => ':count already have a reissue pending print and would not be duplicated.',
            'reissued' => ':count credentials reissued, pending print. No valid card has been invalidated.',
            'already_pending' => ':count were already pending and have not been duplicated.',
            'next_steps' => [
                'Next steps (docs/runbooks/rotacion-clave-qr.md):',
                '  1. php artisan credentials:print-batch --pending --out=/tmp/credentials.pdf',
                '  2. Hand over in person and record: php artisan credentials:deliver <uuid> --responsible=<email>',
                '  3. php artisan credentials:status --key-id=:key   # who is left',
                '  4. php artisan credentials:retire-key :key        # when nobody is left',
            ],
        ],
        'revoke_offboarded' => [
            'failed' => 'The cards of the people who left could not be revoked: :error',
            'one_failed' => 'One of the people who left could not be revoked: :error',
        ],
        'status' => [
            'no_site' => 'The installation has no site yet: complete the setup wizard in the panel first. Until then there are no credentials to show.',
            'print_hint' => 'To print them: php artisan credentials:print-batch --pending --out=/tmp/credentials.pdf',
            'key_unused' => 'Nobody clocks any more with a card signed by key :key.',
            'can_retire' => 'It can be retired: php artisan credentials:retire-key :key',
            'all_delivered' => 'Everyone has their card delivered.',
            'columns' => ['Employee', 'Code', 'Department', 'State', 'Credential'],
            'states' => [
                'no_credential' => 'No credential',
                'pending_print' => 'Pending print',
                'pending_delivery' => 'Pending delivery',
                'delivered' => 'Delivered',
                'revoked' => 'Revoked',
            ],
            'coverage' => ':site — without a delivered card: :without of :employees · pending print: :pending',
            'unknown_key' => 'WARNING: :count active card(s) signed with key :key, which is no longer configured. '
                .'Those people cannot clock with a card.',
            'unknown_key_help' => [
                '  Who they are:    php artisan credentials:status --key-id=:key',
                '  How to fix it:   docs/runbooks/rotacion-clave-qr.md §6',
            ],
            'rotation' => 'Rotation in progress: :pending of :employees still clock with key :key',
        ],
    ],

    'verify_audit_chain' => [
        'sealed_purge' => 'Sealed purge recognised: partition :year (ADR-027).',
        'unknown_action' => 'Action unknown to this version: :action — it is not a break (the hash matches). '
            .'Written by a later version; see docs/runbooks/rotura-cadena-auditoria.md §3.',
        'intact' => 'Intact chain: :rows entries verified.',
        'broken' => 'AUDIT CHAIN BREAK: :failures finding(s) over :rows entries.',
        'incident' => 'Security incident. Procedure: docs/runbooks/rotura-cadena-auditoria.md',
    ],

    'ensure_audit_partitions' => [
        'procedure' => ' Procedure: :runbook',
        'created' => 'Partition audit_log_:year created.',
        'current_was_missing' => 'The partition for the current year (:year) was missing. It has been created, but until now '
            .'EVERY auditable action was failing. Procedure: :runbook',
        'up_to_date' => 'Partitions up to date: :year present.',
        'up_to_date_with_next' => 'Partitions up to date: :year present and :next ready.',
    ],

    'record_system_event' => [
        'unknown_action' => 'Unknown action: «:action». Only the «system.*» actions of the catalogue are accepted.',
        'invalid_json' => 'The value of --data is not valid JSON: :detail',
        'data_required' => '--data must be a JSON object with the data of the audit entry.',
        'not_recorded' => 'The audit entry could not be written: :detail',
    ],

    'incident_metrics' => [
        'published' => 'Metric published: :count open incident(s).',
    ],

    'reconcile_work_record' => [
        'window_too_short' => 'The window must cover at least one day (--days or compliance.work_record_reconciliation.window_days).',
        'coverage' => [
            'full' => 'full record',
            'recent' => 'last :days days',
        ],
        'consistent' => 'Record and audit match (:coverage): :count shift entries reconciled.',
        'not_counted' => [
            'reviewed_purges' => 'out-of-bounds purges reviewed: :count (not counted)',
            'reviewed_site_timezone' => 'site time zone discrepancies reviewed: :changes from the trail, :entries from unsigned entries (not counted)',
            'unverifiable_site_timezone' => 'site time zone not verifiable: :sites site(s) without a site.* entry, :changes time zone change(s) older than the kept audit or the baseline (not counted)',
        ],
        'mismatch' => 'THE TIME RECORD DOES NOT MATCH ITS AUDIT (:coverage): :count discrepanc(y/ies) over :entries shift entries.',
        'truncated' => '  (the first :count are listed; the counts above are complete)',
        'incident' => 'Possible security incident. Procedure: docs/runbooks/discrepancia-registro-auditoria.md',
    ],

    'legal_export' => [
        'example' => 'Example: php artisan compliance:legal-export --from=2026-01-01 --to=2026-01-31',
        'generated' => 'Legal export generated.',
        'columns' => ['Item', 'Value'],
        'rows' => [
            'period' => 'Period',
            'scope' => 'Scope',
            'shift_entries' => 'Shift entries',
            'corrections' => 'Corrections',
            'employees' => 'Employees',
            'file' => 'File',
        ],
        'whole_staff' => 'whole staff',
    ],

    'apply_scheduled_offboardings' => [
        'no_site' => 'There is no work site yet: without one no offboarding could have been scheduled (RF-PD-03).',
        'summary' => 'Today is :today at the site. Scheduled offboardings applied: :applied. Deferred because the '
            .'last working day can still grow: :deferred.',
        'overdue' => ':count scheduled offboarding(s) are still not applied more than a day after their termination '
            .'date. Nobody has been able to clock in after it; check the log («workforce.scheduled_offboarding_failed»).',
        'failures' => ':count scheduled offboarding(s) could not be applied and will be retried on the next run. '
            .'Look for «workforce.scheduled_offboarding_failed» in the log.',
    ],

    'detect_incidents' => [
        'invalid_days' => '--days expects a whole number of days greater than zero and got «:value». '
            .'Without a valid value the window is not guessed: reviewing too much opens incidents on '
            .'working days already handed over, and reviewing too little leaves them unopened.',
        'no_site' => 'There is no work site yet: without a time zone there is no working day to review (RF-PD-03).',
        'inspected' => ':work_days working days of the last :days days reviewed, plus the open shifts.',
        'none' => 'No findings.',
        'safe_to_repeat' => 'Findings already open are not duplicated: running the command again is safe. '
            .'No shift entry has been closed (RN-08).',
        'failures' => ':count finding(s) could not be opened as an incident. The rest could. '
            .'Look for «attendance.incident_not_opened» in the log to see which.',
        'recovered' => ':count working day(s) with a rejected PIN already recovered: any incident still open is closed, '
            .'unless it had a PIN lockout (RN-19). Each closure leaves its system audit entry.',
        'relapsed' => ':count working day(s) with a rejected PIN that again have an attempt not made good after another one was: '
            .'the incident is reopened if the system closed it; one closed by a person is left alone (RN-19). '
            .'Each reopening leaves its system audit entry.',
    ],

    'detect_patterns' => [
        'invalid_days' => '--days expects a whole number of days greater than zero and got «:value». '
            .'Without a valid value the window is not guessed: a pattern is stated over the days looked at, '
            .'and looking at too few misses it.',
        'no_site' => 'There is no work site yet: without a time zone there is no calendar day to group by (RF-PD-03).',
        'inspected' => ':scans kiosk scans of the last :days days reviewed.',
        'none' => 'No anomalous patterns.',
        'hints_only' => 'They are hints for human review, not a conclusion: no clocking has been voided or flagged '
            .'as fraudulent (RF-PR-06). Running the command again is safe: findings already open are not duplicated.',
        'failures' => ':count finding(s) could not be opened as an incident. The rest could. '
            .'Look for «attendance.pattern_incident_not_opened» in the log to see which.',
    ],

    'prune_scan_failures' => [
        'pruned' => 'scan_processing_failures: :count attempts older than :days days deleted.',
    ],

    'reconcile_projections' => [
        'invalid_dates' => '--from and --to expect a date in YYYY-MM-DD format. '
            .'Without valid dates the range is not guessed: the default run is yesterday\'s working day.',
        'no_site' => 'There is no work site yet: without a time zone there is no working day to reconcile (RF-PD-03).',
        'inspected' => ':work_days working days checked from :from to :to (:days days).',
        'clean' => 'No divergences: the projection matches the current shift entries (RN-06).',
        'missing_row' => 'missing row',
        'divergences' => ':count divergence(s), :corrected corrected. The projection had drifted from its source events: '
            .'it is not routine maintenance. Follow docs/runbooks/divergencia-proyeccion.md.',
        'failures' => ':count working day(s) could not be reconciled. Look for «attendance.projection_not_corrected» '
            .'and «attendance.projection_reconciliation_failed» in the log to see which.',
        'self_resolved' => ':count working day(s) resolved themselves: the read crossed a clocking and when read again '
            .'under lock they already matched. Nothing has been written to them.',
    ],

    'kiosk_pairing_code' => [
        'name_required' => '--name with the kiosk name is missing, for example --name="Reception".',
        'no_site' => 'The installation has no site yet: complete the setup wizard first.',
        'name_taken' => 'There is already an active kiosk with that name. Choose another or unpair the old one.',
        'rejected' => 'The code could not be confirmed. Ask the tablet for another code and try again.',
        'paired' => 'Kiosk «:name» paired (:uuid).',
        'reactivated' => 'Kiosk «:name» reactivated (:uuid).',
        'next' => 'The tablet will collect its token in a few seconds. Check with: php artisan kiosk:health',
    ],

    'reporting_metrics' => [
        'absences' => [
            'no_site' => 'There is no work site yet: no time zone to resolve today with (RF-PD-03).',
            'published' => 'Absence metrics published.',
        ],
        'adoption' => [
            'no_site' => 'There is no work site yet: no working day to measure (RF-PD-03).',
            'published' => 'Adoption metrics published.',
        ],
        'compliance' => [
            'no_site' => 'There is no work site yet: no profile to evaluate with (RF-PD-03).',
            'published' => 'Compliance metrics published.',
        ],
        'presence' => [
            'no_site' => 'There is no work site yet: there is nothing to label (RF-PD-03).',
            'published' => 'Presence metrics published.',
        ],
    ],

    'weekly_summary' => [
        'sent' => 'Week :week: :sent summar(y/ies) sent, :skipped skipped out of :recipients manager(s).',
        'failures' => ':count summar(y/ies) could not be delivered; their week stays pending and will be retried. '
            .'Look for «reporting.weekly_summary_failed» in the log to see which accounts and what kind '
            .'of problem it was.',
    ],

    'backup' => [
        'unverified' => 'UNVERIFIED backup: an unverified backup is not a backup. '
            .'Verify it as soon as you can with: php artisan backup:verify',
        'run_failed' => 'The backup has not completed (code :code). The previous backup is still the good one. '
            .'Procedure: docs/runbooks/restaurar-backup.md',
        'verify_failed' => 'The backup is NOT verified (code :code). Treat it as missing until it is solved: '
            .'docs/runbooks/restaurar-backup.md',
    ],

];
