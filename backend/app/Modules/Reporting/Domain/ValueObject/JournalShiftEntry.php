<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Un tramo **vigente** del detalle de jornada (RF-PA-03).
 *
 * ## Las dos marcas, y las dos horas de cada una
 *
 * Regla dura 9: `clockedInAt` es cuando la persona ficho —lo que vale para el
 * registro legal— y `clockInRecordedAt` cuando el servidor lo recibio. Si el
 * fichaje viajo en la cola offline del quiosco (RF-KI-04), se diferencian en
 * horas, y esa diferencia es lo que el panel tiene que poder explicar en lugar
 * de esconder.
 *
 * `recordedAt` es una tercera marca y no una repeticion: dice cuando se escribio
 * **esta version de la fila**. En una version corregida es el momento de la
 * correccion; en una fichada, el del fichaje.
 *
 * ## Un turno nocturno es un tramo
 *
 * Nada aqui parte un tramo a medianoche (RN-05, ADR-006, regla dura 4): un
 * 22:00 → 06:00 es una sola instancia de esta clase, en la jornada de su hora de
 * inicio.
 *
 * ## La zona horaria viaja con el tramo
 *
 * Y no con la jornada: un traslado de centro no reescribe donde ocurrieron las
 * jornadas anteriores, asi que el tramo conserva su `siteId` y su zona. La
 * conversion a hora local ocurre en la capa de presentacion (regla dura 3); aqui
 * el instante esta en UTC y la zona es un dato mas.
 */
final readonly class JournalShiftEntry
{
    public function __construct(
        /** Identificador de ESTA version (ADR-035): el que acepta `PATCH /shift-entries/{uuid}`. */
        public string $uuid,
        public int $version,
        /** `open`, `closed` o `anomalous`: las vigentes. Nunca `voided` ni `superseded`. */
        public string $status,
        public int $siteId,
        /** Zona IANA del centro donde se ficho (`sites.timezone`). */
        public string $timeZone,
        public DateTimeImmutable $clockedInAt,
        public ?DateTimeImmutable $clockInRecordedAt,
        public string $clockInSource,
        public ?DateTimeImmutable $clockedOutAt,
        public ?DateTimeImmutable $clockOutRecordedAt,
        public ?string $clockOutSource,
        /** Nulo mientras el turno sigue abierto. Entonces aporta CERO al total del dia. */
        public ?int $durationMinutes,
        /** Cuando el servidor escribio esta version de la fila. */
        public DateTimeImmutable $recordedAt,
    ) {
        if ($uuid === '') {
            throw new InvalidArgumentException('Un tramo del detalle de jornada necesita su identificador publico.');
        }

        if ($version < 1) {
            throw new InvalidArgumentException('La version de un tramo empieza en 1, y llego '.$version.'.');
        }

        if ($siteId < 1) {
            throw new InvalidArgumentException('Un tramo se ficho en un centro concreto.');
        }

        if ($timeZone === '') {
            throw new InvalidArgumentException('Un tramo sin zona horaria obligaria al cliente a adivinarla (regla dura 3).');
        }

        if ($durationMinutes !== null && $durationMinutes < 0) {
            throw new InvalidArgumentException('Un tramo no puede haber durado '.$durationMinutes.' minutos.');
        }
    }

    /**
     * Lo que este tramo aporta al total del dia.
     *
     * Un turno abierto aporta cero: inventarle una duracion seria dar por
     * terminado lo que no ha terminado, y ese numero acaba en una nomina.
     */
    public function contributedMinutes(): int
    {
        return $this->durationMinutes ?? 0;
    }
}
