<?php

declare(strict_types=1);

/*
 * Department messages shown to a management user (RF-GP-01, RF-ID-10).
 *
 * The manager message is ONE for three causes —the account does not exist, is
 * deactivated or has another role— on purpose (ADR-051 §5): telling them apart
 * would reveal whether an account exists. It names nobody (hard rule 21).
 */

return [

    'errors' => [

        'manager_not_eligible' => 'Choose an active management account with the department manager role.',
    ],

];
