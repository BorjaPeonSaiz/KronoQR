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
 * **`context` son hechos, no prosa.** Guarda los minutos medidos y el umbral
 * aplicado, que es lo que permite a la bandeja explicar el hallazgo sin
 * recalcularlo y lo que deja constancia del umbral **vigente en el momento de la
 * deteccion**: el perfil de cumplimiento puede cambiar despues (RF-PD-07) y una
 * incidencia sin el numero con el que se abrio no se puede defender.
 *
 * Nacio admitiendo **solo enteros** —era la forma mas corta de garantizar que no
 * lleva datos personales— y RN-18 lo amplio a **entero o cadena**: el fichaje
 * irreconciliable no tiene ningun numero que explicar, sino un `scan_id` y un
 * `occurred_at` con los que una persona encuentra el fichaje en el log para
 * corregirlo. La garantia no se apoya ya en el tipo, sino donde de verdad estaba:
 * en que quien construye el hallazgo no tiene por donde alcanzar un nombre —aqui
 * la persona es un UUID— y en la prueba de la deteccion, que afirma clave por
 * clave lo que lleva cada tipo de hallazgo. Sigue siendo **escalar**: ni objetos
 * ni listas, de modo que no hay donde alojar una estructura con un nombre dentro.
 *
 * **Sin persona, solo un descarte del servidor** (2.3.0, ADR-061 A2): un
 * `discarded_scan` que no se pudo atribuir ni por la firma ni por la huella se
 * entrega **por su quiosco** (`deviceUuid`), una por quiosco y jornada. Es el
 * unico hallazgo que puede no tener persona; cualquier otro la exige.
 */
final readonly class DetectedAnomaly
{
    /**
     * @param  string|null  $shiftEntryUuid  El tramo que lo explica, o `null` cuando el hallazgo es de la jornada entera.
     * @param  array<string, int|string>  $context  Los hechos que lo sostienen —minutos, umbral, el `scan_id` de RN-18—. Nunca datos personales.
     */
    public function __construct(
        public AnomalyType $type,
        /** Nulo solo en el `discarded_scan` sin atribuir, que va por su quiosco. */
        public ?string $employeeUuid,
        public int $siteId,
        public WorkDate $workDate,
        public ?string $shiftEntryUuid,
        public DateTimeImmutable $detectedAt,
        public array $context = [],
        /** `devices.uuid` del quiosco de un `discarded_scan` sin persona; nulo en todo lo demas. */
        public ?string $deviceUuid = null,
    ) {
        TimeRange::assertUtc('detectedAt', $detectedAt);

        if ($employeeUuid === null) {
            if ($type !== AnomalyType::DISCARDED_SCAN || $deviceUuid === null || trim($deviceUuid) === '') {
                throw new InvalidArgumentException('Solo un descarte del servidor sin atribuir va sin persona, y entonces por su quiosco.');
            }

            return;
        }

        if (trim($employeeUuid) === '') {
            throw new InvalidArgumentException('Un hallazgo sin empleado no se puede asignar a nadie.');
        }

        if ($deviceUuid !== null) {
            throw new InvalidArgumentException('Un hallazgo con persona no va por su quiosco.');
        }
    }
}
