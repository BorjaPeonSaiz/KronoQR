<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\CorrectionChangesNothing;
use App\Modules\Attendance\Domain\Exception\CorrectionWouldChangeWorkDate;
use App\Modules\Attendance\Domain\Exception\OverlappingShiftEntry;
use App\Modules\Attendance\Domain\Exception\ShiftAlreadyOpen;
use App\Modules\Attendance\Domain\Exception\ShiftEntryNotInWorkDay;
use App\Modules\Attendance\Domain\Model\ShiftEntry;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\CorrectionFactory;
use Tests\Support\Factory\ShiftEntryFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Time\Instants;

/*
 * Lo que una correccion NO puede hacer.
 *
 * Corregir es la unica operacion del producto que escribe en el registro legal
 * sin que haya ocurrido nada en el quiosco, asi que es tambien la unica por la
 * que pueden colarse las cuatro cosas que ninguna otra puede producir: dos
 * turnos abiertos (RN-01), dos tramos que se pisan (RN-02), una jornada que
 * cambia de dia (RN-05) y una version nueva que no rectifica nada (RN-13).
 *
 * Las cuatro se comprueban ANTES de tocar el agregado, y la ultima prueba de
 * este fichero es la que lo verifica: un agregado que lanzara a mitad de la
 * operacion dejaria al caso de uso trabajando sobre un estado que nadie decidio.
 */

/** Corrige un tramo dejando las marcas que se le digan. */
function correcting(WorkDay $workDay, string $entryUuid, ShiftTimes $times): callable
{
    return static fn (): ShiftEntry => $workDay->correctEntry(
        $entryUuid,
        $entryUuid.'-v2',
        $times,
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
    );
}

it('rechaza una correccion que deja el tramo como estaba', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 15:00')
        ->build();

    $mismasMarcas = $jornada->entry('shift-entry-1')->times();

    // Una version nueva identica a la anterior obliga a quien lea el historico
    // a buscar una diferencia que no existe. Ademas es el doble clic en
    // «Guardar», que no debe escribir dos veces.
    expect(correcting($jornada, 'shift-entry-1', $mismasMarcas))
        ->toThrow(CorrectionChangesNothing::class);
})->group('RN-13', 'RF-PA-04');

it('rechaza corregir un tramo que no es de esta jornada', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 15:00')
        ->build();

    expect(correcting($jornada, 'shift-entry-de-otro-dia', ShiftTimes::closed(
        Instants::utc('2026-03-14 07:00'),
        Instants::utc('2026-03-14 14:00'),
    )))->toThrow(ShiftEntryNotInWorkDay::class);
})->group('RN-13');

it('rechaza corregir un tramo ya anulado', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 15:00')
        ->build();

    $jornada->voidEntry('shift-entry-1', CorrectionFactory::standard());

    // Un tramo anulado es historia: no se corrige, y quien lo intente esta
    // trabajando sobre una version que ya no es la vigente (ADR-026). Es lo que
    // pasa cuando dos responsables tocan el mismo tramo a la vez.
    expect(correcting($jornada, 'shift-entry-1', ShiftTimes::closed(
        Instants::utc('2026-03-14 07:00'),
        Instants::utc('2026-03-14 14:00'),
    )))->toThrow(ShiftEntryNotInWorkDay::class);
})->group('RN-13');

it('rechaza una correccion que pisaria a otro tramo vigente', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 11:00')
        ->withClosedShift('2026-03-14 12:00', '2026-03-14 15:00')
        ->build();

    // Alargar el primero hasta las 13:00 lo mete dentro del segundo.
    expect(correcting($jornada, 'shift-entry-1', ShiftTimes::closed(
        Instants::utc('2026-03-14 07:00'),
        Instants::utc('2026-03-14 13:00'),
    )))->toThrow(OverlappingShiftEntry::class);
})->group('RN-02', 'RN-13');

it('permite que la correccion toque justo el borde del tramo siguiente', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 11:00')
        ->withClosedShift('2026-03-14 12:00', '2026-03-14 15:00')
        ->build();

    // `[inicio, fin)`, igual que el `tstzrange` de la restriccion de exclusion:
    // salir a las 12:00 y entrar a las 12:00 no es solaparse. Si el dominio
    // fuera mas estricto que la base de datos, rechazaria correcciones que
    // PostgreSQL acepta.
    $corregido = $jornada->correctEntry(
        'shift-entry-1',
        'shift-entry-1-v2',
        ShiftTimes::closed(Instants::utc('2026-03-14 07:00'), Instants::utc('2026-03-14 12:00')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
    );

    expect($corregido->workedDuration()->minutes)->toBe(300)
        ->and($jornada->totalWorked()->minutes)->toBe(480);
})->group('RN-02', 'RN-13');

it('rechaza una correccion que dejaria dos turnos abiertos', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 11:00')
        ->withShift(ShiftEntryFactory::new()->openSince('2026-03-14 12:00'))
        ->build();

    // Quitarle la salida al primero lo dejaria abierto teniendo ya otro abierto:
    // la misma invariante que el indice unico parcial (RN-01).
    expect(correcting($jornada, 'shift-entry-1', ShiftTimes::open(Instants::utc('2026-03-14 07:00'))))
        ->toThrow(ShiftAlreadyOpen::class);
})->group('RN-01', 'RN-13');

it('rechaza mover a otro dia la entrada que abre la jornada', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 22:00'),
            Instants::inMadrid('2026-03-15 06:00'),
        ))
        ->build();

    // Las 00:30 del dia 15 en Madrid: la jornada pasaria a ser la del 15 y esas
    // ocho horas cambiarian de fila en `daily_totals`. Eso es mover el tramo a
    // otro agregado, y este no puede proteger las invariantes de aquel (RN-05).
    expect(correcting($jornada, 'shift-entry-1', ShiftTimes::closed(
        Instants::inMadrid('2026-03-15 00:30'),
        Instants::inMadrid('2026-03-15 06:00'),
    )))->toThrow(CorrectionWouldChangeWorkDate::class);
})->group('RN-05', 'RN-13');

it('permite corregir la vuelta de una pausa de madrugada sin mover la jornada', function (): void {
    // ADR-024: la pausa son dos tramos, y el segundo hereda la jornada del
    // primero aunque empiece en otro dia natural. Corregirlo no puede confundirse
    // con mover la jornada.
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 22:00'),
            Instants::inMadrid('2026-03-15 02:00'),
        ))
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-15 02:30'),
            Instants::inMadrid('2026-03-15 06:00'),
        ))
        ->build();

    $corregido = $jornada->correctEntry(
        'shift-entry-2',
        'shift-entry-2-v2',
        ShiftTimes::closed(
            Instants::inMadrid('2026-03-15 02:45'),
            Instants::inMadrid('2026-03-15 06:00'),
        ),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
    );

    expect($corregido->workDate()->isoDate)->toBe('2026-03-14')
        ->and($jornada->workDate()->isoDate)->toBe('2026-03-14')
        ->and($jornada->totalWorked()->minutes)->toBe(435);
})->group('RN-05', 'RN-13');

it('rechaza anular un tramo que no esta en la jornada', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 15:00')
        ->build();

    expect(fn (): ShiftEntry => $jornada->voidEntry('shift-entry-9', CorrectionFactory::standard()))
        ->toThrow(ShiftEntryNotInWorkDay::class);
})->group('RN-13');

it('deja la jornada intacta cuando la correccion se rechaza', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 11:00')
        ->withClosedShift('2026-03-14 12:00', '2026-03-14 15:00')
        ->build();

    try {
        correcting($jornada, 'shift-entry-1', ShiftTimes::closed(
            Instants::utc('2026-03-14 07:00'),
            Instants::utc('2026-03-14 13:00'),
        ))();
    } catch (OverlappingShiftEntry) {
        // La guarda salto; lo que se comprueba es que no dejo nada a medias.
    }

    expect($jornada->shiftCount())->toBe(2)
        ->and($jornada->retiredEntries())->toBe([])
        ->and($jornada->totalWorked()->minutes)->toBe(420)
        ->and($jornada->entry('shift-entry-1')->version())->toBe(1)
        // Y sin eventos: nadie tiene que enterarse de una correccion que no
        // ocurrio.
        ->and($jornada->releaseEvents())->toBe([]);
})->group('RN-02', 'RN-13');
