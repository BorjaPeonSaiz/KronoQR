<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use InvalidArgumentException;

/**
 * La cuenta de gestion que concedio un acceso de soporte (RF-PD-11, RL-18).
 *
 * ## Por que lleva el nombre, si la regla dura 21 dice que no
 *
 * Porque esa regla habla de **logs tecnicos y de `error_events`** —lo que viaja
 * al fabricante— y esto es lo contrario: es la respuesta de
 * `GET /api/v1/support/grants`, que **solo lee el cliente** y que el contrato
 * declara con `granted_by: {uuid, name}`. La mitad «visible para el cliente» de
 * RF-PD-11 no se cumple enseñando un UUID: quien mira esa pantalla necesita
 * saber que fue Marta quien autorizo el acceso del martes.
 *
 * **Al asiento de auditoria y al paquete de diagnostico no va el nombre**: alli
 * el actor es `user#id` y la concesion es su `grant_id`. Son dos destinos
 * distintos con dos reglas distintas, y esta clase se usa solo para el primero.
 */
final readonly class SupportGrantAuthor
{
    public function __construct(
        /** `users.id`. Es lo que va a la clave ajena y a `audit_log.actor_id`. */
        public int $id,
        /** `users.uuid`. El identificador publico, el unico que sale a un log. */
        public string $uuid,
        /** Para la pantalla del cliente, y para ningun otro sitio. */
        public string $name,
    ) {
        if ($id < 1) {
            throw new InvalidArgumentException('Una concesion de soporte la concede siempre una cuenta.');
        }
    }
}
