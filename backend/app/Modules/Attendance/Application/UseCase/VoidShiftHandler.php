<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\VoidShiftCommand;
use App\Modules\Attendance\Application\Exception\ShiftEntryNotFound;
use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Attendance\Application\Port\ShiftCorrectionLedger;
use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Application\Support\Corrections;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\Correction;
use App\Modules\Shared\Application\Port\Clock;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * **Declara que un tramo no ocurrio** (RF-PA-04, ADR-026).
 *
 * El caso tipico es el doble escaneo que produjo dos tramos donde solo hubo uno,
 * o el fichaje de alguien que se equivoco de tarjeta. La fila no se borra —regla
 * dura 5, RL-04—: sale del conjunto vigente con su motivo y su firma, y por eso
 * deja de contar para el total (RN-06), libera el hueco de la restriccion de
 * exclusion (RN-02) y, si estaba abierto, deja al empleado sin turno abierto
 * (RN-01).
 *
 * **No crea version nueva**, y esa es toda la diferencia con corregir: un tramo
 * anulado no tiene una version posterior porque no hay ningun hecho que
 * rectificar. Ante Inspeccion se distingue `voided` de `superseded` justamente
 * para poder afirmar cual de las dos cosas paso.
 *
 * **El endpoint es de rrhh+, no de manager+** (plan 1.15, paso 6). No lo decide
 * este caso de uso —eso es la policy, regla dura 18— pero explica por que existe
 * separado del `PATCH`: anular quita horas de la nomina de una persona, y no es
 * la misma responsabilidad que ajustar diez minutos.
 */
final readonly class VoidShiftHandler
{
    public function __construct(
        private ConnectionInterface $connection,
        private WorkDayRepository $workDays,
        private ShiftCorrectionLedger $ledger,
        private EventPublisher $events,
        private Clock $clock,
    ) {}

    /**
     * @throws ShiftEntryNotFound el tramo no existe o ya fue anulado o sustituido
     */
    public function handle(VoidShiftCommand $command): CorrectedShift
    {
        $performedAt = $this->clock->now();

        return $this->connection->transaction(
            fn (): CorrectedShift => $this->void($command, $performedAt),
        );
    }

    private function void(VoidShiftCommand $command, DateTimeImmutable $performedAt): CorrectedShift
    {
        $workDay = $this->workDays->findWorkDayOfShiftEntry($command->shiftEntryUuid);

        if (! $workDay instanceof WorkDay) {
            throw ShiftEntryNotFound::withUuid($command->shiftEntryUuid);
        }

        $voided = $workDay->voidEntry(
            $command->shiftEntryUuid,
            Correction::by($command->performedByUserId, $performedAt, $command->reason),
        );

        // Recalcula `daily_totals` dentro de esta transaccion (RN-06, regla dura
        // 7). Es el caso en que el total **baja**, que es exactamente el que un
        // acumulador se equivocaria.
        $this->workDays->save($workDay);

        $events = $workDay->releaseEvents();
        $correction = Corrections::in($events);

        $this->ledger->record($correction);
        $this->events->publish(...$events);

        return CorrectedShift::of($workDay, $voided, $correction);
    }
}
