<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Una persona del alcance con su situacion de presencia (**RF-PA-01**).
 *
 * Es la fila del panel de presencia **y** el cuerpo del mensaje que se difunde
 * por WebSocket cuando esa persona ficha (ADR-011). Una sola forma para los dos
 * caminos, a proposito: si el sondeo y la difusion entregaran objetos distintos,
 * el panel tendria dos maneras de pintar la misma fila y una de las dos se
 * quedaria atras.
 *
 * ## Los cinco campos de tramo son nulos a la vez
 *
 * Con {@see PresenceStatus::Absent} no hay tramo abierto, asi que no hay
 * identificador, ni hora de entrada, ni origen, ni quiosco. El constructor lo
 * exige en vez de confiarlo: un `absent` con hora de entrada es un estado que
 * ninguna consulta produce y que la pantalla pintaria como si la persona
 * estuviera dentro.
 *
 * ## El nombre viaja aqui y no a un log
 *
 * `fullName` es un dato personal y su unico destino es la pantalla de una cuenta
 * autorizada. Ni este objeto ni nada que lo transporte puede acabar en un log
 * tecnico ni en `error_events` (regla dura 21): para eso esta `employeeUuid`.
 *
 * ## El instante esta en UTC
 *
 * `clockedInAt` es el momento **real** de la entrada —el `occurred_at` del
 * escaneo, no el `recorded_at` (regla dura 9)— y sale en UTC (regla dura 3). El
 * tiempo transcurrido lo calcula la presentacion contra el `generatedAt` del
 * tablero, nunca contra el reloj del cliente.
 */
final readonly class PresenceEntry
{
    public function __construct(
        public string $employeeUuid,
        public string $fullName,
        /** `null` para quien no tiene departamento: solo lo alcanza un ambito sin restriccion. */
        public ?int $departmentId,
        public ?string $departmentName,
        public PresenceStatus $status,
        /** Identificador de la VERSION vigente del tramo abierto (ADR-035). */
        public ?string $shiftEntryUuid,
        public ?DateTimeImmutable $clockedInAt,
        /** `shift_entries.clock_in_source`: `qr_kiosk`, `pin_kiosk`, `manual_admin` o `import`. */
        public ?string $origin,
        /** Quiosco donde se ficho la entrada. Nulo si el tramo no nacio de un escaneo. */
        public ?string $deviceUuid,
        public ?string $deviceName,
    ) {
        $this->guardPerson();
        $this->guardDepartment();
        $this->guardShift();
        $this->guardDevice();
    }

    private function guardPerson(): void
    {
        if ($this->employeeUuid === '') {
            throw new InvalidArgumentException('Una fila de presencia necesita el identificador publico del empleado.');
        }

        if (trim($this->fullName) === '') {
            throw new InvalidArgumentException('Una fila de presencia necesita un nombre que pintar.');
        }
    }

    /**
     * El departamento va entero o no va: sin nombre, la pantalla enseñaria un
     * numero, y sin identificador el panel no podria filtrar por el.
     */
    private function guardDepartment(): void
    {
        if (($this->departmentId === null) !== ($this->departmentName === null)) {
            throw new InvalidArgumentException('El departamento va entero o no va: identificador y nombre juntos.');
        }

        if ($this->departmentId !== null && $this->departmentId < 1) {
            throw new InvalidArgumentException('El departamento de una fila de presencia es un identificador valido.');
        }
    }

    /**
     * Los tres datos del tramo acompañan a `present` y faltan en `absent`, los
     * tres a la vez. Un `absent` con hora de entrada lo pintaria la pantalla como
     * si la persona estuviera dentro.
     */
    private function guardShift(): void
    {
        $open = $this->hasOpenShift();

        if ($open !== ($this->shiftEntryUuid !== null)
            || $open !== ($this->clockedInAt !== null)
            || $open !== ($this->origin !== null)) {
            throw new InvalidArgumentException(
                'Una fila «present» describe su tramo abierto y una «absent» no tiene ninguno que describir.'
            );
        }
    }

    /**
     * El quiosco es opcional incluso con tramo abierto —un alta manual no tiene
     * ninguno—, pero no puede aparecer sin tramo: nadie ficha en un quiosco sin
     * abrir un tramo.
     */
    private function guardDevice(): void
    {
        if (($this->deviceUuid === null) !== ($this->deviceName === null)) {
            throw new InvalidArgumentException('El quiosco va entero o no va: identificador y nombre juntos.');
        }

        if ($this->deviceUuid !== null && ! $this->hasOpenShift()) {
            throw new InvalidArgumentException('Quien no tiene tramo abierto no ficho en ningun quiosco.');
        }
    }

    private function hasOpenShift(): bool
    {
        return $this->status === PresenceStatus::Present;
    }
}
