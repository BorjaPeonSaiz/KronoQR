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
        'reason_personal_data' => 'The reason looks like it carries personal data (an email address, an ID number or a phone number). Describe the incident without it; to point to a person, use their uuid. The reason goes as is into the audit trail and cannot be corrected later.',
        'duration' => 'The duration must be between 1 and :maximum hours, and you asked for :hours. Your installation sets the maximum; if you need longer, revoke this access when you are done and grant another one.',
    ],

    // `product:support-grants:prune-reasons` (task 11.3 C). Counts, never a reason.
    'prune' => [
        'dry_run' => 'The reason of :rows support grant(s) closed more than :days days ago would be cleared. Nothing has been changed.',
        'done' => 'Cleared the reason of :rows support grant(s) closed more than :days days ago. The original reason is still in the audit trail.',
        'failed' => 'The reason of the support grants could not be cleared (:failure). Nothing has been changed; the next run will try again.',
    ],

    // Shown next to the reason field and by `support:grant` (task 11.3 C).
    'reason_hint' => 'Do not write names, emails, ID numbers or phone numbers: if you need to point to a person, use their uuid. The reason goes as is into the audit trail and cannot be corrected later.',

];
