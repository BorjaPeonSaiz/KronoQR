<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\AnomalyDetectionPolicy;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Un hallazgo de la revision diaria: **que** se ha encontrado, **de quien**,
 * **cuando** y con **que numeros** (RF-PR-01).
 *
 * Es el resultado puro de {@see AnomalyDetectionPolicy}
 * y la carga del evento con el que `Compliance` abre la incidencia. Un hallazgo
 * **no es** una incidencia: no tiene severidad, ni estado, ni responsable
 * asignado. Esas tres cosas son de `Compliance` (doc 01 §5.1), y decidirlas aqui
 * obligaria a `Attendance` a conocer la bandeja que las trabaja.
 *
 * **Ni un nombre, ni un codigo de empleado** (regla dura 21): la persona viaja
 * como `employeeUuid`, que es lo que la Inspeccion resuelve contra `employees`
 * cuando de verdad hace falta.
 *
 * **`context` son numeros, no prosa.** Guarda los minutos medidos y el umbral
 * aplicado, que es lo que permite a la bandeja explicar el hallazgo sin
 * recalcularlo y lo que deja constancia del umbral **vigente en el momento de la
 * deteccion**: el perfil de cumplimiento puede cambiar despues (RF-PD-07) y una
 * incidencia sin el numero con el que se abrio no se puede defender.
 */
final readonly class DetectedAnomaly
{
    /**
     * @param  string|null  $shiftEntryUuid  El tramo que lo explica, o `null` cuando el hallazgo es de la jornada entera.
     * @param  array<string, int>  $context  Minutos medidos y umbral aplicado. Nunca datos personales.
     */
    public function __construct(
        public AnomalyType $type,
        public string $employeeUuid,
        public int $siteId,
        public WorkDate $workDate,
        public ?string $shiftEntryUuid,
        public DateTimeImmutable $detectedAt,
        public array $context = [],
    ) {
        TimeRange::assertUtc('detectedAt', $detectedAt);

        if (trim($employeeUuid) === '') {
            throw new InvalidArgumentException('Un hallazgo sin empleado no se puede asignar a nadie.');
        }
    }
}
