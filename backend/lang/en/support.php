<?php

declare(strict_types=1);

/*
 * Support access texts (RF-PD-11, RL-18, ADR-020, task 5.9).
 *
 * WRITTEN FOR THE CUSTOMER, NOT FOR US. They are read by whoever is about to let
 * the vendor into their installation, with an open incident and in a hurry. Each
 * message says what happened and what to do, without jargon.
 *
 * `errors` are the reasons a grant cannot be created. They are composed from
 * `Product\Domain\Exception\InvalidSupportGrant`, which carries the key and not
 * the text: the domain does not know which language it will be read in.
 */

return [

    'errors' => [
        'reason_length' => 'Say which incident you are granting access for, between 3 and 200 characters. That text goes into the audit trail and is what later proves the access was used for what was asked.',
        'duration' => 'The duration must be between 1 and :maximum hours, and you asked for :hours. Your installation sets the maximum; if you need longer, revoke this access when you are done and grant another one.',
    ],

];
