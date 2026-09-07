<?php

declare(strict_types=1);

/*
 * Kiosk and pairing texts (RF-PD-06, task 5.6).
 *
 * WHAT BELONGS HERE. Only messages a PERSON reads in a management form. The
 * generic rejections —`PairingRejected` and `PairingCodeRejected`— are NOT
 * translated and must not be: their `detail` is pinned field by field in the
 * contract, and any variation, language included, would be a channel to tell the
 * causes apart (hard rule 17, RS-03). Clients render their own i18n text from
 * `type`.
 */

return [

    'errors' => [
        'device_name_taken' => 'A kiosk with that name is already active. Choose another name or unpair the previous one first.',
    ],

];
