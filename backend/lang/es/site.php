<?php

declare(strict_types=1);

/*
 * Textos del centro de trabajo: el `409` de la zona horaria ya fija y la orden
 * `workforce:correct-site-timezone` (RN-23, ADR-056).
 *
 * La orden la ejecuta la persona de informatica del hotel en el servidor, casi
 * siempre a peticion de RRHH. Cada frase dice que pasa y que no pasa: lo que
 * esta en juego es como se presenta el registro horario de toda la plantilla.
 */

return [

    'errors' => [
        'timezone_locked' => 'Ya hay fichajes registrados con :timezone; la zona no se cambia desde la API.',
    ],

];
