<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * Una cuenta de gestion vista desde la bandeja: la que responde de una
 * incidencia o la que la cerro (RF-PA-05).
 *
 * **Por UUID publico y no por `users.id`**, igual que el autor de una correccion
 * en el detalle de jornada. El dominio si maneja la clave interna —es la que
 * escribe `incidents.assigned_to_user_id` y la que apunta `audit_log.actor_id`—
 * pero lo que sale por la API es el identificador publico, que es el unico que un
 * cliente puede guardar sin acabar dependiendo del orden en que se dieron de alta
 * las cuentas del hotel.
 */
final readonly class IncidentActor
{
    public function __construct(
        public string $uuid,
        public string $name,
    ) {}
}
