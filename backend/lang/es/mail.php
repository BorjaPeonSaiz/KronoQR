<?php

declare(strict_types=1);

/*
 * Lo comun a todos los correos del producto (R6-PL-07, V6-PL-05).
 *
 * La despedida de `mail.branded-notification`, que va seguida del nombre de la
 * instalacion. Sustituye al «Regards,» de Laravel, que salia en ingles aunque el
 * destinatario estuviera en castellano.
 */

return [
    'salutation' => 'Un saludo,',
];
