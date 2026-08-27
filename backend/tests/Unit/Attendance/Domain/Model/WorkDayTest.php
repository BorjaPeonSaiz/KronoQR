<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\ClockOutBeforeClockIn;
use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Exception\NoOpenShiftEntry;
use App\Modules\Attendance\Domain\Exception\OverlappingShiftEntry;
use App\Modules\Attendance\Domain\Exception\ShiftAlreadyOpen;
use App\Modules\Attendance\Domain\Exception\ShiftEntryDoesNotBelongToWorkDay;
use App\Modules\Attendance\Domain\Model\ShiftEntry;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftAnomaly;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use Tests\Support\Domain\RecordedEvents;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\ShiftEntryFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Time\Instants;

/*
 * El agregado que protege las cinco invariantes del fichaje: RN-01 (un turno
 * abierto), RN-02 (sin solapes), RN-03 (orden de las marcas), RN-04 (UTC) y
 * RN-06 (total recalculado).
 *
 * La prueba de que la BASE DE DATOS tambien las rechaza es de la tarea 1.3, en
 * nivel de integracion. Aqui no hay base de datos ni framework.
 */

it('abre un tramo cuando el empleado no tiene ninguno abierto', function (): void {
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();

    $entry = $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);

    expect($entry->isOpen())->toBeTrue()
        ->and($workDay->shiftCount())->toBe(1)
        ->and($workDay->hasOpenEntry())->toBeTrue();
})->group('RF-AT-02', 'RQ-01');

it('no abre un segundo turno cuando ya hay uno abierto', function (): void {
    // RN-01. La misma invariante que el indice unico parcial
    // one_open_shift_per_employee, aqui con un mensaje del negocio.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 21:00')->build();

    expect(fn (): ShiftEntry => $workDay->clockIn('shift-entry-2', Instants::utc('2026-03-14 22:00'), ScanOrigin::QR_KIOSK))
        ->toThrow(ShiftAlreadyOpen::class);
})->group('RN-01', 'RQ-01');

it('no deja el segundo tramo a medio anadir cuando rechaza abrirlo', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 21:00')->build();

    expect(fn (): ShiftEntry => $workDay->clockIn('shift-entry-2', Instants::utc('2026-03-14 22:00'), ScanOrigin::QR_KIOSK))
        ->toThrow(ShiftAlreadyOpen::class)
        ->and($workDay->shiftCount())->toBe(1)
        ->and($workDay->releaseEvents())->toBe([]);
})->group('RN-01');

it('se niega a rehidratarse con dos turnos abiertos', function (): void {
    // Un tramo puede llegar aqui desde una importacion o una consulta mal
    // filtrada; cargar dos turnos abiertos sin protestar convertiria RN-01 en
    // una sugerencia el dia que hiciera falta de verdad.
    $twoOpen = WorkDayFactory::new()
        ->withShift(ShiftEntryFactory::new()->withUuid('shift-entry-1')->openSince('2026-03-14 07:00'))
        ->withShift(ShiftEntryFactory::new()->withUuid('shift-entry-2')->openSince('2026-03-14 09:00'));

    expect(fn (): WorkDay => $twoOpen->reconstituted())->toThrow(ShiftAlreadyOpen::class);
})->group('RN-01');

it('se rehidrata con un solo turno abierto', function (): void {
    $workDay = WorkDayFactory::new()
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 10:00')
        ->withOpenShiftSince('2026-03-14 11:00')
        ->reconstituted();

    expect($workDay->hasOpenEntry())->toBeTrue()
        ->and($workDay->shiftCount())->toBe(2);
})->group('RN-01');

it('rechaza una entrada anterior al fin de un tramo ya registrado', function (): void {
    // RN-02 en su caso real: un lote offline que se sincroniza tarde y trae un
    // escaneo anterior a lo que ya esta escrito.
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 08:00', '2026-03-14 14:00')->build();

    expect(fn (): ShiftEntry => $workDay->clockIn('shift-entry-2', Instants::utc('2026-03-14 13:00'), ScanOrigin::QR_KIOSK))
        ->toThrow(OverlappingShiftEntry::class);
})->group('RN-02', 'RN-15', 'RQ-01');

it('admite una entrada a la misma hora exacta de la salida anterior', function (): void {
    // El borde es semiabierto [inicio, fin), igual que el tstzrange de
    // PostgreSQL: salir a las 14:00 y entrar a las 14:00 no es solape. Si el
    // dominio y la base de datos discreparan aqui, el fichaje pasaria por una
    // comprobacion y lo rechazaria la otra.
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 08:00', '2026-03-14 14:00')->build();

    $entry = $workDay->clockIn('shift-entry-2', Instants::utc('2026-03-14 14:00'), ScanOrigin::QR_KIOSK);

    expect($entry->isOpen())->toBeTrue()
        ->and($workDay->shiftCount())->toBe(2);
})->group('RN-02', 'RQ-01');

it('rechaza una entrada un solo segundo antes de la salida anterior', function (): void {
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 08:00', '2026-03-14 14:00:00')->build();

    expect(fn (): ShiftEntry => $workDay->clockIn('shift-entry-2', Instants::utc('2026-03-14 13:59:59'), ScanOrigin::QR_KIOSK))
        ->toThrow(OverlappingShiftEntry::class);
})->group('RN-02');

it('se niega a rehidratarse con dos tramos cerrados que se solapan', function (): void {
    $overlapping = WorkDayFactory::new()
        ->withClosedShift('2026-03-14 08:00', '2026-03-14 14:00')
        ->withClosedShift('2026-03-14 13:00', '2026-03-14 20:00');

    expect(fn (): WorkDay => $overlapping->reconstituted())->toThrow(OverlappingShiftEntry::class);
})->group('RN-02');

it('se niega a rehidratarse con un tramo abierto que pisa a uno cerrado', function (): void {
    $overlapping = WorkDayFactory::new()
        ->withClosedShift('2026-03-14 08:00', '2026-03-14 14:00')
        ->withOpenShiftSince('2026-03-14 13:00');

    expect(fn (): WorkDay => $overlapping->reconstituted())->toThrow(OverlappingShiftEntry::class);
})->group('RN-02');

it('se niega a rehidratarse con un tramo cerrado que pisa a uno abierto ya cargado', function (): void {
    // El mismo par en el orden inverso: la comprobacion no puede depender del
    // orden en que la consulta devuelva las filas.
    $overlapping = WorkDayFactory::new()
        ->withOpenShiftSince('2026-03-14 13:00')
        ->withClosedShift('2026-03-14 08:00', '2026-03-14 14:00');

    expect(fn (): WorkDay => $overlapping->reconstituted())->toThrow(OverlappingShiftEntry::class);
})->group('RN-02');

it('se rehidrata con dos tramos que solo se tocan en el borde', function (): void {
    $workDay = WorkDayFactory::new()
        ->withClosedShift('2026-03-14 08:00', '2026-03-14 14:00')
        ->withClosedShift('2026-03-14 14:00', '2026-03-14 20:00')
        ->reconstituted();

    expect($workDay->shiftCount())->toBe(2)
        ->and($workDay->totalWorked()->minutes)->toBe(720);
})->group('RN-02');

it('rechaza una salida anterior a la entrada del turno abierto', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 08:00')->build();

    expect(fn (): ShiftEntry => $workDay->clockOut(
        Instants::utc('2026-03-14 07:59'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::standard(),
    ))->toThrow(ClockOutBeforeClockIn::class);
})->group('RN-03', 'RQ-01');

it('rechaza una salida exactamente a la hora de la entrada', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 08:00')->build();

    expect(fn (): ShiftEntry => $workDay->clockOut(
        Instants::utc('2026-03-14 08:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::standard(),
    ))->toThrow(ClockOutBeforeClockIn::class);
})->group('RN-03', 'RQ-01');

it('rechaza una entrada que no viene en UTC', function (): void {
    $workDay = WorkDayFactory::new()->build();

    expect(fn (): ShiftEntry => $workDay->clockIn(
        'shift-entry-1',
        new DateTimeImmutable('2026-03-14 08:00', Instants::madrid()),
        ScanOrigin::QR_KIOSK,
    ))->toThrow(InstantIsNotUtc::class);
})->group('RN-04', 'RQ-01');

it('rechaza una salida que no viene en UTC', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 08:00')->build();

    expect(fn (): ShiftEntry => $workDay->clockOut(
        new DateTimeImmutable('2026-03-14 16:00', Instants::madrid()),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::standard(),
    ))->toThrow(InstantIsNotUtc::class);
})->group('RN-04');

it('no cierra nada cuando no hay ningun tramo abierto', function (): void {
    // Llegar aqui significa que el estado cambio entre la lectura y la
    // escritura: es la senal de una carrera, y por eso se lanza en vez de
    // convertirse en una entrada silenciosa.
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 08:00', '2026-03-14 14:00')->build();

    expect(fn (): ShiftEntry => $workDay->clockOut(
        Instants::utc('2026-03-14 20:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::standard(),
    ))->toThrow(NoOpenShiftEntry::class);
})->group('RF-AT-03');

it('cierra el tramo abierto con sus marcas reales', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 07:00')->build();

    $entry = $workDay->clockOut(Instants::utc('2026-03-14 15:00'), ScanOrigin::PIN_KIOSK, ClockingPolicyFactory::standard());

    expect($entry->status())->toBe(ShiftEntryStatus::CLOSED)
        ->and($entry->clockedOutAt()?->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T15:00:00+00:00')
        ->and($entry->clockOutSource())->toBe(ScanOrigin::PIN_KIOSK)
        ->and($entry->workedDuration()->minutes)->toBe(480);
})->group('RF-AT-03', 'RQ-01');

it('se niega a rehidratar un tramo de otro empleado', function (): void {
    $foreign = WorkDayFactory::new()
        ->forEmployee('employee-1')
        ->withShift(ShiftEntryFactory::new()->forEmployee('employee-2')->worked('2026-03-14 08:00', '2026-03-14 14:00'));

    expect(fn (): WorkDay => $foreign->reconstituted())->toThrow(ShiftEntryDoesNotBelongToWorkDay::class);
})->group('RN-01');

it('se niega a rehidratar un tramo atribuido a otra jornada', function (): void {
    $foreign = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(
            ShiftEntryFactory::new()
                ->onWorkDay(WorkDate::fromIsoDate('2026-03-15', Instants::madrid()))
                ->worked('2026-03-15 08:00', '2026-03-15 14:00')
        );

    expect(fn (): WorkDay => $foreign->reconstituted())->toThrow(ShiftEntryDoesNotBelongToWorkDay::class);
})->group('RN-05');

it('se niega a rehidratar un tramo anulado como si fuera parte de la jornada', function (): void {
    // ADR-026: un tramo «voided» es historia. Cargarlo duplicaria los minutos
    // del dia en el recalculo de RN-06.
    $withHistory = WorkDayFactory::new()->withVoidedShift('2026-03-14 08:00', '2026-03-14 14:00');

    expect(fn (): WorkDay => $withHistory->reconstituted())->toThrow(ShiftEntryDoesNotBelongToWorkDay::class);
})->group('RN-06', 'RN-13');

it('se niega a rehidratar un tramo sustituido como si fuera parte de la jornada', function (): void {
    $withHistory = WorkDayFactory::new()
        ->withShift(ShiftEntryFactory::new()->worked('2026-03-14 08:00', '2026-03-14 14:00')->superseded());

    expect(fn (): WorkDay => $withHistory->reconstituted())->toThrow(ShiftEntryDoesNotBelongToWorkDay::class);
})->group('RN-13');

it('emite la entrada y el recalculo del total al abrir un tramo', function (): void {
    $workDay = WorkDayFactory::new()->atSite(7)->onWorkDate('2026-03-14')->build();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $events = $workDay->releaseEvents();

    expect(RecordedEvents::names($events))->toBe([
        'attendance.employee_clocked_in',
        'attendance.daily_totals_recalculated',
    ]);
})->group('RF-AT-02');

it('emite la salida con la duracion del tramo y el total del dia', function (): void {
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 06:00', '2026-03-14 08:00')->build();
    $workDay->clockIn('shift-entry-2', Instants::utc('2026-03-14 09:00'), ScanOrigin::QR_KIOSK);
    $workDay->releaseEvents();

    $workDay->clockOut(Instants::utc('2026-03-14 13:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $clockedOut = RecordedEvents::clockedOut($workDay->releaseEvents());

    expect($clockedOut->worked->minutes)->toBe(240)
        ->and($clockedOut->dailyTotal->minutes)->toBe(360)
        ->and($clockedOut->anomalies)->toBe([]);
})->group('RN-06', 'RF-AT-05');

it('entrega los eventos una sola vez', function (): void {
    // El caso de uso los publica DESPUES de que la transaccion confirme; un
    // evento emitido dos veces dejaria a la auditoria contando dos fichajes.
    $workDay = WorkDayFactory::new()->build();
    $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);

    $first = $workDay->releaseEvents();
    $second = $workDay->releaseEvents();

    expect($first)->toHaveCount(2)
        ->and($second)->toBe([]);
})->group('RF-AT-02');

it('marca el tramo para revision cuando la politica lo clasifica como anomalo', function (): void {
    // RN-08: el tramo queda CERRADO con sus marcas reales. La anomalia se marca
    // para que una persona lo revise, nunca para deshacer lo que el empleado
    // ficho ni para cerrarlo por su cuenta.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 06:00')->build();

    $entry = $workDay->clockOut(
        Instants::utc('2026-03-14 19:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withAnomalousAfterHours(12)->build(),
    );

    expect($entry->status())->toBe(ShiftEntryStatus::ANOMALOUS)
        ->and($entry->workedDuration()->minutes)->toBe(780)
        ->and($workDay->hasAnomaly())->toBeTrue();
})->group('RN-08', 'RQ-01');

it('lleva la anomalia clasificada en el evento de salida', function (): void {
    // Compliance abre la incidencia al recibirlo, sin volver a calcular la
    // duracion ni conocer los umbrales.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 06:00')->build();

    $workDay->clockOut(
        Instants::utc('2026-03-14 19:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withAnomalousAfterHours(12)->build(),
    );
    $clockedOut = RecordedEvents::clockedOut($workDay->releaseEvents());

    expect($clockedOut->anomalies)->toBe([ShiftAnomaly::LONG_SHIFT]);
})->group('RN-08');

it('no tiene anomalia cuando todos sus tramos son corrientes', function (): void {
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 08:00', '2026-03-14 14:00')->reconstituted();

    expect($workDay->hasAnomaly())->toBeFalse();
})->group('RN-08');

it('conoce la primera entrada y la ultima salida de la jornada', function (): void {
    // Son first_in_at y last_out_at de daily_totals, y se calculan sobre los
    // tramos, no sobre el orden en que llegaron.
    $workDay = WorkDayFactory::new()
        ->withClosedShift('2026-03-14 14:00', '2026-03-14 20:00')
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 10:00')
        ->reconstituted();

    expect($workDay->firstClockInAt()?->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T06:00:00+00:00')
        ->and($workDay->lastClockOutAt()?->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T20:00:00+00:00');
})->group('RN-06');

it('toma como ultima salida la mas tardia y no la primera que encuentra', function (): void {
    // El mismo par de tramos que la prueba anterior, en el orden contrario. Es
    // lo que distingue «la salida mas tardia» de «la salida del primer tramo de
    // la lista», y last_out_at es lo que ve quien revisa la jornada.
    $workDay = WorkDayFactory::new()
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 10:00')
        ->withClosedShift('2026-03-14 14:00', '2026-03-14 20:00')
        ->reconstituted();

    expect($workDay->lastClockOutAt()?->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T20:00:00+00:00');
})->group('RN-06');

it('abre el tramo en su primera version', function (): void {
    // RN-13: una correccion crea la version 2 y conserva la 1. Un tramo recien
    // fichado tiene que ser la 1, o el historial de versiones empieza torcido.
    $workDay = WorkDayFactory::new()->build();

    $entry = $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);

    expect($entry->version())->toBe(1);
})->group('RN-13', 'RF-AT-02');

it('no tiene primera entrada ni ultima salida mientras la jornada esta vacia', function (): void {
    $workDay = WorkDayFactory::new()->build();

    expect($workDay->firstClockInAt())->toBeNull()
        ->and($workDay->lastClockOutAt())->toBeNull()
        ->and($workDay->totalWorked()->minutes)->toBe(0);
})->group('RN-06');

it('no anuncia una ultima salida mientras quede un tramo abierto', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 06:00')->build();

    expect($workDay->lastClockOutAt())->toBeNull()
        ->and($workDay->firstClockInAt()?->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T06:00:00+00:00');
})->group('RN-06');

it('conserva el empleado, el centro y la jornada con los que se abrio', function (): void {
    $workDay = WorkDayFactory::new()
        ->forEmployee('employee-9')
        ->atSite(7)
        ->onWorkDate('2026-03-14')
        ->build();

    expect($workDay->employeeUuid())->toBe('employee-9')
        ->and($workDay->siteId())->toBe(7)
        ->and($workDay->workDate()->isoDate)->toBe('2026-03-14')
        ->and($workDay->entries())->toBe([]);
})->group('RN-05');

it('no tiene tramo abierto cuando todos estan cerrados', function (): void {
    $workDay = WorkDayFactory::new()->withClosedShift('2026-03-14 08:00', '2026-03-14 14:00')->reconstituted();

    expect($workDay->openEntry())->toBeNull()
        ->and($workDay->hasOpenEntry())->toBeFalse();
})->group('RN-01');
