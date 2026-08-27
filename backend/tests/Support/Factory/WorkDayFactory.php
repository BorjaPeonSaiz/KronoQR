<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

use App\Modules\Attendance\Domain\Model\ShiftEntry;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use DateTimeZone;
use Tests\Support\Time\Instants;

/**
 * Jornadas de dominio puro. Sin Eloquent, sin contenedor y sin base de datos:
 * las factorias de persistencia son otras y llegan en la tarea 1.3.
 *
 * Se lee como el escenario que describe:
 *
 *     WorkDayFactory::new()
 *         ->onWorkDate('2026-03-14')
 *         ->withOpenShiftSince('2026-03-14 21:00')
 *         ->build();
 */
final class WorkDayFactory
{
    private string $employeeUuid = 'employee-1';

    private int $siteId = 1;

    private string $isoDate = '2026-03-14';

    private DateTimeZone $timezone;

    /** @var list<ShiftEntryFactory> */
    private array $shifts = [];

    private function __construct()
    {
        $this->timezone = Instants::madrid();
    }

    public static function new(): self
    {
        return new self;
    }

    public function forEmployee(string $employeeUuid): self
    {
        $clone = clone $this;
        $clone->employeeUuid = $employeeUuid;

        return $clone;
    }

    public function atSite(int $siteId): self
    {
        $clone = clone $this;
        $clone->siteId = $siteId;

        return $clone;
    }

    public function onWorkDate(string $isoDate): self
    {
        $clone = clone $this;
        $clone->isoDate = $isoDate;

        return $clone;
    }

    public function inTimezone(string $timezone): self
    {
        $clone = clone $this;
        $clone->timezone = new DateTimeZone($timezone);

        return $clone;
    }

    /**
     * La jornada llega con un turno ya abierto desde ese instante UTC.
     */
    public function withOpenShiftSince(string $utcWallClock): self
    {
        return $this->withShift(ShiftEntryFactory::new()->openSince($utcWallClock));
    }

    /**
     * La jornada llega con un tramo cerrado entre esos dos instantes UTC.
     */
    public function withClosedShift(string $utcFrom, string $utcTo): self
    {
        return $this->withShift(ShiftEntryFactory::new()->worked($utcFrom, $utcTo));
    }

    /**
     * Varios tramos cerrados de golpe, descritos como pares de instantes UTC.
     *
     * Lo usan las pruebas basadas en propiedades, donde el numero de tramos lo
     * decide la muestra: el bucle vive aqui y no dentro de la prueba (§3.5).
     *
     * @param  list<array{string, string}>  $shifts
     */
    public function withClosedShifts(array $shifts): self
    {
        $clone = clone $this;

        foreach ($shifts as [$from, $to]) {
            $clone = $clone->withClosedShift($from, $to);
        }

        return $clone;
    }

    /**
     * Un tramo cerrado que ya fue anulado: sigue en la tabla, y ni cuenta para
     * el total ni protege invariante alguna (ADR-026).
     */
    public function withVoidedShift(string $utcFrom, string $utcTo): self
    {
        return $this->withShift(ShiftEntryFactory::new()->worked($utcFrom, $utcTo)->voided());
    }

    /**
     * Cualquier otro tramo, descrito por su propia factoria.
     */
    public function withShift(ShiftEntryFactory $shift): self
    {
        $clone = clone $this;
        $clone->shifts = [...$this->shifts, $shift];

        return $clone;
    }

    public function workDate(): WorkDate
    {
        return WorkDate::fromIsoDate($this->isoDate, $this->timezone);
    }

    /**
     * La jornada tal como la ve el caso de uso.
     *
     * Sin tramos usa `start()`, que es lo que ocurre con el primer fichaje del
     * dia; con tramos usa `reconstitute()`, que es la puerta del repositorio y
     * vuelve a comprobar las invariantes.
     */
    public function build(): WorkDay
    {
        if ($this->shifts === []) {
            return WorkDay::start($this->employeeUuid, $this->siteId, $this->workDate());
        }

        return $this->reconstituted();
    }

    /**
     * Rehidratacion explicita, para las pruebas que van a por sus guardas.
     */
    public function reconstituted(): WorkDay
    {
        return WorkDay::reconstitute($this->employeeUuid, $this->siteId, $this->workDate(), $this->entries());
    }

    /**
     * @return list<ShiftEntry>
     */
    public function entries(): array
    {
        $entries = [];
        $position = 0;

        foreach ($this->shifts as $shift) {
            $position++;
            $entries[] = $shift->attributedTo($this->employeeUuid, $this->workDate(), 'shift-entry-'.$position)->build();
        }

        return $entries;
    }
}
