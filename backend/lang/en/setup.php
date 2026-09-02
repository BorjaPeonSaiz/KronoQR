<?php

declare(strict_types=1);

/*
 * Start-up wizard texts (RF-PD-03, task 5.5). See `lang/es/setup.php` for the
 * reasoning: every message says what to do, not only what failed, because the
 * person reading it is setting the system up and has no console at hand.
 */

return [

    'unknown_step' => 'The start-up wizard has no step called ":step". '
        .'See the ones it does have in GET /api/v1/setup/status.',

    'step_is_derived' => 'Step ":step" is not marked by hand: it completes by being done. '
        .'The administrator step completes once an account has its second factor active, '
        .'and the site step once the site exists.',

    'step_is_not_skippable' => 'Step ":step" cannot be skipped. The thresholds of the compliance profile '
        .'must be checked against the collective agreement that applies to you before any hours are '
        .'calculated: review and confirm them, even if you leave them as they come.',

    'steps_still_pending' => 'Some steps are still unresolved: :steps. Complete them, or skip the ones '
        .'that can be skipped, before closing the wizard.',

    'already_completed' => 'The start-up wizard is finished and does not reopen. '
        .'Anything you need to change is changed under Settings, and is recorded with its author and date.',

    // Names the sign-in route on purpose. See `lang/es/setup.php`.
    'administrator_exists' => 'This installation already has a management account, so the wizard does not '
        .'create the first one again. Sign in with your email and password at /api/v1/auth/login (or on the '
        .'panel sign-in screen); if you have not activated your second factor yet, the response will ask for it.',

    // No key for "the site already exists": that case is `SiteAlreadyConfigured`,
    // rendered by the generic `WorkforceConflict` handler with the exception's
    // own text, like every other workforce conflict. See `lang/es/setup.php`.
];
