<?php

declare(strict_types=1);

/*
 * Credential issuance texts read by a person in the panel or the console
 * (RF-QR-01, RN-14).
 */

return [

    /*
     * RV-2: the credential PDF title. It goes to the document metadata and the
     * browser download history, so it never carries names (hard rule 21).
     */
    'document' => [
        'card_title' => 'Credential',
        'sheet_title' => 'Credentials',
    ],

    'errors' => [
        // RN-14: offboarding revokes their cards and no new one is issued.
        'holder_offboarded' => 'This person has left the company: a card cannot be issued or reissued to them.',
    ],

];
