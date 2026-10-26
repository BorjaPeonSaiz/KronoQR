<?php

declare(strict_types=1);

/*
 * Texts of `compliance:apply-retention` when the purge fails against the
 * database (RF-PR-03, RL-02, ADR-057 §3). Told apart by SQLSTATE, not by the
 * driver message, which includes the whole statement.
 */

return [

    'responsible_not_active' => 'The --responsible account is no longer an active management account with the admin or rrhh role: it cannot answer for the purge. Nothing has been deleted.',
    'confirm_signature' => 'Run THIS purge, signed by this account?',

    'failure' => [
        'title' => 'The purge could not be completed against the database. Nothing has been deleted.',
        'cutoff' => [
            'PostgreSQL rejected the work record cutoff: it is later than the compliance profile allows with the UTC date of the database server.',
            'The function takes the longest period of all the sites in the installation (each one never below the legal floor of its jurisdiction), not only the site you expected.',
            'Check the time on both servers and that the profile has not changed since the simulation, then simulate again.',
        ],
        'lock' => [
            'Another transaction held rows of the work record and the purge stopped waiting.',
            'Run the same command again in a few minutes, preferably outside a shift change.',
        ],
        'profile' => [
            'There is no compliance profile to compute the cutoff with: the purge cannot know how much must be kept.',
            'Review the default profile (php artisan product:doctor) before trying again.',
        ],
        'generic' => [
            'If it is the «pgsql_maintenance» connection, check that DB_MAINTENANCE_PASSWORD is set and that the role has a credential: infra/docker/postgres/initdb/02-application-roles.sh assigns it.',
        ],
        'detail' => 'Technical detail: :detail',
    ],

    /*
     * What the command writes on screen when simulating or running the purge.
     */
    'console' => [
        'cutoff' => 'Work record cutoff: before :date (:years years, profile of site :site).',
        'columns' => ['Store', 'Count', 'Range'],
        'report' => 'Report: :path',
        'purged' => ':count records purged. The audit entry is in audit_log.',
        'nothing_due' => 'Nothing is due: nothing would be purged.',
        'how_to_execute' => 'Nothing has been deleted. To run THIS purge, with the authorisation of the person responsible:',
    ],

    /*
     * The report archived as the record of each run. It is written in the
     * installation language; its file name is not (scripts look for it).
     */
    'report' => [
        'title' => 'KronoQR — retention report (RL-02, RL-11, RF-PR-03)',
        'mode' => 'Mode:              :mode',
        'modes' => [
            'simulation' => 'SIMULATION (nothing has been deleted)',
            'execution' => 'REAL RUN',
        ],
        'generated' => 'Generated:         :at UTC',
        'site' => 'Site:              :site',
        'terms' => 'Periods applied:   work record and audit :years years · technical log :log_days days · '
            .'error history :error_days days',
        'cutoff' => 'Work record cutoff: before :date (that day is NOT purged: the period includes it)',
        'scopes' => [
            'work_records' => 'Work record',
            'audit_log' => 'Audit chain',
            'technical_log' => 'Technical log',
            'error_history' => 'Error history',
            'personnel_records' => 'Personnel records',
            'management_accounts' => 'Management accounts',
        ],
        'units' => [
            'work_records' => 'rows',
            'audit_log' => 'rows',
            'technical_log' => 'files',
            'error_history' => 'rows',
            'personnel_records' => 'people',
            'management_accounts' => 'accounts',
        ],
        'term' => [
            'years' => ':years years',
            'years_since_leaving' => ':years years since leaving',
            'days' => ':days days',
        ],
        'not_installed' => 'not installed',
        'subtotal' => 'subtotal',
        'total_to_purge' => 'TOTAL records to purge: :count',
        'total_purged' => 'TOTAL records purged: :count',
        'notes' => 'Notes',
        'nothing_deleted' => 'Nothing has been deleted. To run THIS purge, with the confirmation of the person responsible:',
        'phrase_expires' => [
            'The phrase expires when the cutoff or the compliance profile changes: what runs is what',
            'was approved, not whatever is due today.',
        ],
        'executed' => 'Purge run with confirmation :token.',
        'executed_entries' => 'The audit entry is in audit_log; the released partitions are sealed in audit_chain_anchors.',
        'stays_on_server' => 'This report stays on the customer\'s server (ADR-020). It contains no personal data.',
    ],

];
