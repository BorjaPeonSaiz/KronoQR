<?php

declare(strict_types=1);

/*
 * Lo comun a todos los correos del producto.
 *
 * La despedida de `mail.branded-notification`, que va seguida del nombre de la
 * instalacion. Sustituye al «Regards,» de Laravel, que salia en ingles aunque el
 * destinatario estuviera en castellano.
 */

return [
    'salutation' => 'Un saludo,',
];
