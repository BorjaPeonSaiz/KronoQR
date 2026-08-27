<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

use App\Modules\Attendance\Domain\Model\ShiftEntry;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use DateTimeImmutable;
use Tests\Support\Time\Instants;

/**
 * Tramos de dominio puro para las pruebas unitarias.
 *
 * Rehidrata siempre con `ShiftEntry::reconstitute()`, que es la puerta que usara
 * el repositorio de la tarea 1.4, y **nunca** con `open()`, `close()` ni
 * `markAnomalous()`: los tres son `@internal` de `WorkDay` y
 * `AggregateBoundaryTest` comprueba que nadie los llama desde fuera, tests
 * incluidos. Una factoria que se saltara esa frontera dejaria a la prueba de
 * arquitectura sin nada que proteger.
 *
 * Lo que la prueba fija a mano —el empleado, la jornada, el uuid— queda
 * «anclado» y `WorkDayFactory` ya no lo toca. Es lo que permite construir el
 * tramo de otro empleado sin que la jornada se lo apropie al montarla.
 */
final class ShiftEntryFactory
{
    private string $uuid = 'shift-entry';

    private bool $uuidPinned = false;

    private string $employeeUuid = 'employee-1';

    private bool $employeePinned = false;

    private WorkDate $workDate;

    private bool $workDatePinned = false;

    private DateTimeImmutable $clockedInAt;

    private ?DateTimeImmutable $clockedOutAt = null;

    private ScanOrigin $clockInSource = ScanOrigin::QR_KIOSK;

    private ?ScanOrigin $clockOutSource = null;

    private ShiftEntryStatus $status = ShiftEntryStatus::OPEN;

    private int $version = 1;

    private ?string $supersededByUuid = null;

    private function __construct()
    {
        $this->workDate = WorkDate::fromIsoDate('2026-03-14', Instants::madrid());
        $this->clockedInAt = Instants::utc('2026-03-14 07:00');
    }

    public static function new(): self
    {
        return new self;
    }

    public function withUuid(string $uuid): self
    {
        $clone = clone $this;
        $clone->uuid = $uuid;
        $clone->uuidPinned = true;

        return $clone;
    }

    public function forEmployee(string $employeeUuid): self
    {
        $clone = clone $this;
        $clone->employeeUuid = $employeeUuid;
        $clone->employeePinned = true;

        return $clone;
    }

    public function onWorkDay(WorkDate $workDate): self
    {
        $clone = clone $this;
        $clone->workDate = $workDate;
        $clone->workDatePinned = true;

        return $clone;
    }

    /**
     * Tramo abierto: entrada fichada, salida pendiente.
     */
    public function openSince(string $utcWallClock): self
    {
        return $this->openSinceInstant(Instants::utc($utcWallClock));
    }

    public function openSinceInstant(DateTimeImmutable $clockedInAt): self
    {
        $clone = clone $this;
        $clone->clockedInAt = $clockedInAt;
        $clone->clockedOutAt = null;
        $clone->clockOutSource = null;
        $clone->status = ShiftEntryStatus::OPEN;

        return $clone;
    }

    /**
     * Tramo cerrado entre dos horas de reloj UTC.
     */
    public function worked(string $utcFrom, string $utcTo): self
    {
        return $this->workedBetween(Instants::utc($utcFrom), Instants::utc($utcTo));
    }

    public function workedBetween(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        $clone = clone $this;
        $clone->clockedInAt = $from;
        $clone->clockedOutAt = $to;
        $clone->clockOutSource = ScanOrigin::QR_KIOSK;
        $clone->status = ShiftEntryStatus::CLOSED;

        return $clone;
    }

    /** Cerrado y marcado para revision humana (RN-07, RN-08). */
    public function markedAnomalous(): self
    {
        return $this->withStatus(ShiftEntryStatus::ANOMALOUS);
    }

    /** Anulado: el tramo no ocurrio. Es historia, no jornada (ADR-026). */
    public function voided(): self
    {
        return $this->withStatus(ShiftEntryStatus::VOIDED);
    }

    /** Sustituido por una version posterior (RN-13, ADR-026). */
    public function superseded(): self
    {
        return $this->withStatus(ShiftEntryStatus::SUPERSEDED);
    }

    /**
     * Sustituido por una version concreta: es el `superseded_by_id` de la fila,
     * lo que hace recorrible el historico de RL-04.
     */
    public function supersededBy(string $replacementUuid): self
    {
        $clone = $this->superseded();
        $clone->supersededByUuid = $replacementUuid;

        return $clone;
    }

    public function withStatus(ShiftEntryStatus $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function scannedFrom(ScanOrigin $source): self
    {
        $clone = clone $this;
        $clone->clockInSource = $source;

        return $clone;
    }

    public function leftFrom(ScanOrigin $source): self
    {
        $clone = clone $this;
        $clone->clockOutSource = $source;

        return $clone;
    }

    public function atVersion(int $version): self
    {
        $clone = clone $this;
        $clone->version = $version;

        return $clone;
    }

    /**
     * Los valores por defecto de la jornada que monta este tramo. Lo que la
     * prueba fijo a mano se respeta.
     */
    public function attributedTo(string $employeeUuid, WorkDate $workDate, string $uuid): self
    {
        $clone = clone $this;
        $clone->employeeUuid = $this->employeePinned ? $this->employeeUuid : $employeeUuid;
        $clone->workDate = $this->workDatePinned ? $this->workDate : $workDate;
        $clone->uuid = $this->uuidPinned ? $this->uuid : $uuid;

        return $clone;
    }

    public function build(): ShiftEntry
    {
        return ShiftEntry::reconstitute(
            $this->uuid,
            $this->employeeUuid,
            $this->workDate,
            $this->clockedInAt,
            $this->clockedOutAt,
            $this->clockInSource,
            $this->clockOutSource,
            $this->status,
            $this->version,
            $this->supersededByUuid,
        );
    }
}
