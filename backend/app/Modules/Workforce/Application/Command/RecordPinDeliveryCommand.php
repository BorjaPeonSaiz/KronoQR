<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Command;

/**
 * Registrar la entrega presencial del PIN (RF-ID-09).
 *
 * **El momento no viaja aqui y el responsable no se elige.** La entrega se anota
 * cuando ocurre y consta a nombre de quien esta autenticado: dejar que el
 * cliente fije las dos cosas convertiria el acuse —que existe para poder
 * afirmar quien entrego y cuando— en una declaracion sin valor.
 *
 * El responsable viaja por su UUID publico y nunca por su clave interna
 * (regla dura 21): es el unico identificador de una persona que puede aparecer
 * en un registro.
 */
final readonly class RecordPinDeliveryCommand
{
    public function __construct(
        public string $employeeUuid,
        public string $deliveredByUserUuid,
    ) {}
}
