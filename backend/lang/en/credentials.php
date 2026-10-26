<?php

declare(strict_types=1);

/*
 * Credential issuance texts read by a person in the panel or the console
 * (RF-QR-01, RN-14).
 */

return [

    /*
     * The reason the system writes when it revokes the card of someone who
     * leaves (RN-14). It is always stored with the same text —the one of the
     * legal record and the audit entry— and translated when shown.
     */
    'revoked_reason' => [
        'offboarded' => 'Employee left (RN-14)',
    ],

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
