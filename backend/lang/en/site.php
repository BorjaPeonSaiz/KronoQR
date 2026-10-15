<?php

declare(strict_types=1);

/*
 * Texts for the work site: the `409` for a time zone already locked and the
 * `workforce:correct-site-timezone` command (RN-23, ADR-056).
 *
 * The command is run on the server by the hotel's IT person, usually at the
 * request of HR. Each sentence says what happens and what does not: what is at
 * stake is how the whole staff's work record is presented.
 */

return [

    'errors' => [
        'timezone_locked' => 'There are clock-ins recorded with :timezone; the time zone cannot be changed through the API.',
    ],

];
