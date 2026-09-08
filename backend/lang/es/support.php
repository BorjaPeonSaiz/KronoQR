<?php

declare(strict_types=1);

/*
 * Textos de los accesos de soporte (RF-PD-11, RL-18, ADR-020, tarea 5.9).
 *
 * ESTAN ESCRITOS PARA EL CLIENTE, NO PARA NOSOTROS. Los lee quien esta a punto
 * de dejar entrar al fabricante en su instalacion, con una incidencia abierta y
 * con prisa. Cada mensaje dice que pasa y que hacer, sin jerga.
 *
 * `errors` son los motivos por los que una concesion no se puede crear. Se
 * componen desde `Product\Domain\Exception\InvalidSupportGrant`, que lleva la
 * clave y no el texto: el dominio no sabe en que idioma se va a leer.
 */

return [

    'errors' => [
        'reason_length' => 'Escribe para que incidencia concedes el acceso, entre 3 y 200 caracteres. Ese texto queda en el registro de auditoria y es lo que permite comprobar despues que el acceso se uso para lo que se pidio.',
        'duration' => 'La duracion tiene que estar entre 1 y :maximum horas, y has pedido :hours. El maximo lo fija tu instalacion; si necesitas mas, revoca este acceso al terminar y concede otro.',
    ],

];
