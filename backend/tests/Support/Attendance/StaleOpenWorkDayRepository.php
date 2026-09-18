<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use DateTimeImmutable;

/**
 * El repositorio real con **una lectura vieja** metida a proposito: la primera
 * llamada a `findOpenWorkDayFor()` responde «no hay ningun turno abierto»
 * aunque lo haya.
 *
 * ## Que reproduce
 *
 * La carrera del cambio de turno, y la reproduce **de forma determinista**. En
 * produccion ocurre sola: `RegisterScanHandler` decide si el escaneo abre o
 * cierra con esa consulta, y carga la jornada destino **despues**, con otra
 * instantanea; entre las dos puede confirmar el escaneo de otra tablet. El
 * perdedor decide entonces «abrir» y se encuentra con el tramo del ganador ya
 * abierto.
 *
 * `ScanIdempotencyConcurrencyTest` lo provoca con diez procesos de verdad, pero
 * solo aparece en una parte de las ejecuciones: es una prueba que **detecta** el
 * defecto, no una que lo describa. Esta lo describe, y por eso el desenlace
 * puede afirmarse en una sola asercion en lugar de en una estadistica.
 *
 * ## Solo la primera, y solo esa consulta
 *
 * El reintento del caso de uso tiene que ver el mundo real —si no, no habria
 * nada que probar—, asi que a partir de la segunda llamada delega. Y las otras
 * cuatro operaciones del puerto delegan siempre: lo que se simula es una lectura
 * que llego tarde, no un repositorio roto.
 */
final class StaleOpenWorkDayRepository implements WorkDayRepository
{
    private bool $served = false;

    public function __construct(private readonly WorkDayRepository $real) {}

    public function findOpenWorkDayFor(string $employeeUuid): ?WorkDay
    {
        if (! $this->served) {
            $this->served = true;

            return null;
        }

        return $this->real->findOpenWorkDayFor($employeeUuid);
    }

    public function findWorkDayFor(string $employeeUuid, WorkDate $workDate): ?WorkDay
    {
        return $this->real->findWorkDayFor($employeeUuid, $workDate);
    }

    public function findWorkDayOfShiftEntry(string $shiftEntryUuid): ?WorkDay
    {
        return $this->real->findWorkDayOfShiftEntry($shiftEntryUuid);
    }

    public function findWorkDayOfAnyShiftEntry(string $shiftEntryUuid): ?WorkDay
    {
        return $this->real->findWorkDayOfAnyShiftEntry($shiftEntryUuid);
    }

    public function save(WorkDay $workDay): void
    {
        $this->real->save($workDay);
    }

    public function closedEntryEndingAfter(string $employeeUuid, DateTimeImmutable $at): ?TimeRange
    {
        return $this->real->closedEntryEndingAfter($employeeUuid, $at);
    }
}
