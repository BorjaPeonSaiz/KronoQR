<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Command;

use DateTimeImmutable;

/**
 * La orden de cerrar la incidencia de una jornada que la persona subsano
 * (RN-19).
 *
 * Habla en escalares e identificadores publicos, como {@see OpenIncidentCommand}:
 * este modulo no puede importar el dominio de `Attendance` (doc 02 §1.6).
 */
final readonly class CloseRecoveredIncidentCommand
{
    public function __construct(
        /** Valor de `incidents.type` del hallazgo subsanado. */
        public string $type,
        public string $employeeUuid,
        /** La jornada en `Y-m-d`, ya resuelta en la zona del centro (RN-05). */
        public string $workDate,
        /** El instante de la pasada que vio la subsanacion, en UTC. */
        public DateTimeImmutable $recoveredAt,
    ) {}
}
