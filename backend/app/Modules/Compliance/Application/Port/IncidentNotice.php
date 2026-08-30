<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;

/**
 * Una incidencia abierta que todavia no se ha avisado a su responsable
 * (RF-PR-01, `incidents.notified_at IS NULL`).
 *
 * **Lleva el codigo del empleado y su nombre, y esto no contradice la regla dura
 * 21.** Esa regla gobierna los LOGS TECNICOS y `error_events`, que viajan al
 * fabricante en el paquete de diagnostico. Esto es un aviso dirigido al
 * responsable del departamento de esa persona —quien ya ve su jornada en el
 * panel— y decirle «hay una incidencia del empleado 3f2a…» le obligaria a
 * buscarla a mano para saber a quien llamar. El nombre no sale de aqui hacia
 * ningun log: lo consume {@see IncidentNotifier} y muere en el correo.
 */
final readonly class IncidentNotice
{
    public function __construct(
        public int $incidentId,
        public IncidentType $type,
        public IncidentSeverity $severity,
        /** Identificador publico del empleado: es el que la bandeja usa en su URL. */
        public string $employeeUuid,
        /** Nombre completo, solo para el correo al responsable autorizado. */
        public string $employeeName,
        /** Jornada afectada, en `Y-m-d`. */
        public string $workDate,
    ) {}
}
