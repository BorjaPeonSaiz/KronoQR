<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use InvalidArgumentException;

/**
 * La marca de incidencia que el detalle de jornada incrusta (RF-PA-03, RF-PA-05).
 *
 * ## Por que aqui hay cadenas y no enums
 *
 * El catalogo de tipos, severidades y estados vive en
 * `Compliance\Domain\ValueObject`, y `Reporting` no puede importarlo: el §1.6 no
 * concede esa arista y Deptrac la verifica. Lo mismo ocurre ya con el `status` de
 * un tramo, que es `string` en {@see JournalShiftEntry} teniendo un enum en
 * `Attendance`. La forma de esos valores la fija el **contrato**, que los declara
 * como enums de OpenAPI, y una prueba ata las dos listas; no la buena fe.
 *
 * ## Es la ficha minima y no la incidencia entera
 *
 * Cuatro campos: lo justo para pintar la marca junto a la jornada y saltar a la
 * bandeja. La nota de resolucion, el contexto y el responsable **no** estan aqui,
 * y no es un olvido: el detalle de jornada lo lee tambien el propio empleado
 * desde su portal (`GET /api/v1/me/workdays`, ADR-015), y el flujo de trabajo
 * interno de RF-PA-05 no es parte de su registro horario.
 */
final readonly class JournalIncident
{
    public function __construct(
        /** `incidents.id`. Es el que va en `POST /api/v1/incidents/{id}/resolve`. */
        public int $id,
        public string $type,
        public string $severity,
        public string $status,
    ) {
        if ($id < 1) {
            throw new InvalidArgumentException('Una incidencia del detalle necesita su identificador.');
        }

        if ($type === '' || $severity === '' || $status === '') {
            throw new InvalidArgumentException(
                'Una marca de incidencia sin tipo, severidad o situacion no dice nada que el panel pueda pintar.'
            );
        }
    }
}
