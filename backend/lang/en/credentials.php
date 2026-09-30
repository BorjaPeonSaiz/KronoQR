<?php

declare(strict_types=1);

/*
 * Credential issuance texts read by a person in the panel or the console
 * (RF-QR-01, RN-14).
 */

return [

    'errors' => [
        // RN-14: offboarding revokes their cards and no new one is issued.
        'holder_offboarded' => 'This person has left the company: a card cannot be issued or reissued to them.',
    ],

];
