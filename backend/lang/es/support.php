<?php

declare(strict_types=1);

/*
 * Textos de los accesos de soporte (RF-PD-11, RL-18, ADR-020).
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
        'reason_personal_data' => 'El motivo parece llevar un dato personal (un correo, un DNI o NIE o un telefono). Describe la incidencia sin el; para señalar a una persona, usa su uuid. El motivo va tal cual al registro de auditoria y no se puede corregir despues.',
        'duration' => 'La duracion tiene que estar entre 1 y :maximum horas, y has pedido :hours. El maximo lo fija tu instalacion; si necesitas mas, revoca este acceso al terminar y concede otro.',
    ],

    // `product:support-grants:prune-reasons`. Recuentos, nunca un motivo.
    'prune' => [
        'dry_run' => 'Se vaciaria el motivo de :rows concesion(es) de soporte cerradas hace mas de :days dias. No se ha tocado nada.',
        'done' => 'Vaciado el motivo de :rows concesion(es) de soporte cerradas hace mas de :days dias. El motivo original sigue en el registro de auditoria.',
        'failed' => 'No se ha podido vaciar el motivo de las concesiones de soporte (:failure). No se ha cambiado nada; se reintentara en la proxima pasada.',
    ],

    // Junto al campo del motivo y en `support:grant`.
    'reason_hint' => 'No escribas nombres, correos, DNI ni telefonos: si tienes que señalar a una persona, usa su uuid. El motivo va tal cual al registro de auditoria y no se puede corregir despues.',

];
