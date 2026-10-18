<?php

declare(strict_types=1);

/*
 * The signature of console commands with legal weight (2.3.0 block 3, doc 01
 * §7.2 "Console signatures"). Read by whoever types the command on the server.
 */

return [
    'missing' => '--responsible is missing: the uuid or e-mail of the management account that signs. Nothing was written.',
    'numeric_id' => '--responsible no longer accepts the internal account id (":value"): since 2.3.0 it takes the account uuid or e-mail, for example --responsible=person@hotel.com. Nothing was written.',
    'malformed' => '--responsible must be the uuid or e-mail of the signing management account; ":value" is neither. Nothing was written.',
    'not_found' => 'There is no management account with ":value". Nothing was written.',
    'inactive' => 'Account :email is deactivated and cannot sign. Nothing was written.',
    'wrong_role' => 'Account :email has none of the roles that sign this action (:roles): it is signed by whoever can do the same from the panel. Nothing was written.',
    'signature' => 'Signed by: :name <:email> · :roles · :uuid',
    'confirm_missing' => 'Without a terminal or with --force, confirm who signs with --responsible-confirm=<account e-mail>. Nothing was written.',
    'confirm_mismatch' => '--responsible-confirm does not match the e-mail of the signing account (:email). Nothing was written.',
    'cancelled' => 'Cancelled. Nothing was written.',
    'no_longer_allowed' => 'The --responsible account is no longer an active management account with the role that signs this action: it was deactivated or changed role while confirming. Nothing was written.',
];
