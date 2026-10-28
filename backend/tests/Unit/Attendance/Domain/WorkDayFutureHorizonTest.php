<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\ShiftEntryMarkInFuture;
use App\Modules\Attendance\Domain\Model\ShiftEntry;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryTimes;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\ContinuationCeilingFactory;
use Tests\Support\Factory\CorrectionFactory;
use Tests\Support\Factory\ManualEntryHorizonFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Time\Instants;

/*
 * **RN-24 en el agregado** (R3-AR-05 de la re-verificacion 2.2.0; tarea 20.2 de
 * la 2.3.0): un tramo escrito a mano nunca va hacia el futuro, y lo exige
 * `WorkDay` y no solo los dos casos de uso que hoy lo llaman. Un tercer camino
 * —una importacion, por ejemplo— no puede saltarselo.
 *
 * El horizonte llega resuelto, como la politica y la cota: aqui la hora del
 * servidor es el 14-03-2026 a las 10:00 UTC (11:00 en Madrid) con 5 minutos de
 * margen, asi que el ultimo instante admisible son las 10:05:00 UTC. Los bordes
 * de la propia politica estan en `ManualEntryHorizonTest`; aqui se prueba que el
 * agregado la aplica, en que orden y sin tocar nada cuando rechaza.
 */

const WORK_DAY_FUTURE_HORIZON_NOW = '2026-03-14 10:00:00';

function workDayFutureHorizonAdd(WorkDay $workDay, ShiftEntryTimes $times): ShiftEntry
{
    return $workDay->addEntry(
        'shift-entry-nuevo',
        $times,
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
        ContinuationCeilingFactory::standard(),
        ManualEntryHorizonFactory::at(WORK_DAY_FUTURE_HORIZON_NOW),
    );
}

function workDayFutureHorizonCorrect(WorkDay $workDay, ShiftEntryTimes $times): ShiftEntry
{
    return $workDay->correctEntry(
        'shift-entry-1',
        'shift-entry-1-v2',
        $times,
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
        ContinuationCeilingFactory::standard(),
        ManualEntryHorizonFactory::at(WORK_DAY_FUTURE_HORIZON_NOW),
    );
}

function workDayFutureHorizonRejection(Closure $action): ShiftEntryMarkInFuture
{
    try {
        $action();
    } catch (ShiftEntryMarkInFuture $rejection) {
        return $rejection;
    }

    throw new RuntimeException('El agregado admitio una marca por delante del horizonte.');
}

it('no da de alta un tramo de una jornada futura aunque sus marcas sean pasadas', function (): void {
    // A las 11:05 en Madrid la jornada admisible es la del 14: la del 15, no.
    // Las marcas son anteriores al horizonte, asi que solo la jornada lo impide.
    $jornada = WorkDayFactory::new()->onWorkDate('2026-03-15')->build();

    $rechazo = workDayFutureHorizonRejection(fn (): ShiftEntry => workDayFutureHorizonAdd(
        $jornada,
        ShiftEntryTimes::closed(Instants::utc('2026-03-14 08:00'), Instants::utc('2026-03-14 09:00')),
    ));

    expect($rechazo->mark)->toBe(FutureMark::WorkDate)
        ->and($jornada->entries())->toBe([])
        ->and($jornada->releaseEvents())->toBe([]);
})->group('RN-24', 'RF-PA-04');

it('no da de alta un tramo cuya entrada o salida va por delante del horizonte', function (string $from, ?string $to, FutureMark $mark): void {
    $jornada = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();
    $times = $to === null
        ? ShiftEntryTimes::open(Instants::utc($from))
        : ShiftEntryTimes::closed(Instants::utc($from), Instants::utc($to));

    $rechazo = workDayFutureHorizonRejection(fn (): ShiftEntry => workDayFutureHorizonAdd($jornada, $times));

    expect($rechazo->mark)->toBe($mark)
        ->and($jornada->entries())->toBe([])
        ->and($jornada->releaseEvents())->toBe([]);
})->with([
    'salida prevista' => ['2026-03-14 07:00', '2026-03-14 15:00', FutureMark::ClockOut],
    'salida un microsegundo despues del limite' => ['2026-03-14 07:00', '2026-03-14 10:05:00.000001', FutureMark::ClockOut],
    'entrada futura sin salida' => ['2026-03-14 10:06', null, FutureMark::ClockIn],
])->group('RN-24', 'RF-PA-04');

it('da de alta el tramo cuyas marcas llegan justo al ultimo instante admisible', function (): void {
    $jornada = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();

    $tramo = workDayFutureHorizonAdd(
        $jornada,
        ShiftEntryTimes::closed(Instants::utc('2026-03-14 07:00'), Instants::utc('2026-03-14 10:05')),
    );

    expect($tramo->clockedOutAt())->toEqual(Instants::utc('2026-03-14 10:05'))
        ->and($jornada->entries())->toHaveCount(1);
})->group('RN-24', 'RF-PA-04');

it('no corrige un tramo dejandole la salida por delante del horizonte, y no toca nada', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 09:00')
        ->build();
    $jornada->releaseEvents();

    $rechazo = workDayFutureHorizonRejection(fn (): ShiftEntry => workDayFutureHorizonCorrect(
        $jornada,
        $jornada->entry('shift-entry-1')->times()->withClockOut(Instants::utc('2026-03-14 15:00')),
    ));

    expect($rechazo->mark)->toBe(FutureMark::ClockOut)
        ->and($jornada->entry('shift-entry-1')->clockedOutAt())->toEqual(Instants::utc('2026-03-14 09:00'))
        ->and($jornada->retiredEntries())->toBe([])
        ->and($jornada->releaseEvents())->toBe([]);
})->group('RN-24', 'RF-PA-04');

it('corrige un tramo hasta el ultimo instante admisible', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withOpenShiftSince('2026-03-14 07:00')
        ->build();

    $corregido = workDayFutureHorizonCorrect(
        $jornada,
        $jornada->entry('shift-entry-1')->times()->withClockOut(Instants::utc('2026-03-14 10:05')),
    );

    expect($corregido->clockedOutAt())->toEqual(Instants::utc('2026-03-14 10:05'));
})->group('RN-24', 'RF-PA-04');

it('mira el horizonte antes que el resto de guardas', function (): void {
    // Un tramo que pisaria a otro y ademas va hacia el futuro: el problema que
    // se dice es el futuro, que es el que la persona tiene que corregir primero.
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 09:00')
        ->build();

    $rechazo = workDayFutureHorizonRejection(fn (): ShiftEntry => workDayFutureHorizonAdd(
        $jornada,
        ShiftEntryTimes::closed(Instants::utc('2026-03-14 08:00'), Instants::utc('2026-03-14 12:00')),
    ));

    expect($rechazo->mark)->toBe(FutureMark::ClockOut);
})->group('RN-24', 'RN-02');
