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

];
