<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Event\ShiftCorrected;
use App\Modules\Attendance\Domain\Model\ShiftEntry;
use App\Modules\Attendance\Domain\ValueObject\CorrectionAction;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReasonCode;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use App\Modules\Shared\Domain\Event\DomainEvent;
use Tests\Support\Domain\RecordedEvents;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\CorrectionFactory;
use Tests\Support\Factory\ShiftEntryFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Time\Instants;

/*
 * RN-13, RL-04 y RF-PA-04 — la correccion crea una version nueva y conserva la
 * anterior con su autor, su momento y su motivo.
 *
 * Es la regla dura 5 y es lo que separa un registro horario defendible de una
 * hoja de calculo: quien mira el historico dentro de dos anos tiene que poder
 * ver que decia el registro antes, que dice ahora, quien lo cambio y por que.
 * Una correccion que sobrescribiera la fila haria imposible las cuatro cosas a
 * la vez.
 *
 * Junto a RN-13 se comprueban aqui las tres invariantes que una correccion
 * puede romper y que nadie mas protege: RN-06 —el total se RECALCULA, y una
 * correccion es el unico camino por el que puede bajar—, RN-05 —un turno de
 * noche corregido no se parte ni cambia de jornada— y RN-07/RN-08 —una version
 * corregida se vuelve a clasificar.
 *
 * Dominio puro: sin base de datos, sin framework y sin reloj. El momento de la
 * correccion llega en el objeto `Correction`, que es lo que permite probar una
 * correccion hecha en octubre sobre un turno de marzo sin esperar a ninguna de
 * las dos fechas.
 */

/**
 * El evento de correccion del lote, ya con su tipo.
 *
 * @param  list<DomainEvent>  $events
 */
function recordedCorrection(array $events): ShiftCorrected
{
    foreach ($events as $event) {
        if ($event instanceof ShiftCorrected) {
            return $event;
        }
    }

    throw new RuntimeException('El lote de eventos no contiene ninguna correccion.');
}

it('crea una version nueva y no muta la anterior', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 15:00')
        ->build();

    $anterior = $jornada->entry('shift-entry-1');

    $corregido = $jornada->correctEntry(
        'shift-entry-1',
        'shift-entry-1-v2',
        $anterior->times()->withClockOut(Instants::utc('2026-03-14 14:30')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
    );

    expect($corregido->uuid())->toBe('shift-entry-1-v2')
        ->and($corregido->version())->toBe(2)
        ->and($corregido->clockedOutAt()?->format('H:i'))->toBe('14:30')
        // La version anterior conserva SUS horas: es lo unico que la hace
        // servir de prueba de lo que el registro decia antes.
        ->and($anterior->version())->toBe(1)
        ->and($anterior->clockedInAt()->format('H:i'))->toBe('07:00')
        ->and($anterior->clockedOutAt()?->format('H:i'))->toBe('15:00');
})->group('RN-13', 'RF-PA-04');

it('encadena la version anterior con la nueva y la saca del conjunto vigente', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 15:00')
        ->build();

    $anterior = $jornada->entry('shift-entry-1');

    $jornada->correctEntry(
        'shift-entry-1',
        'shift-entry-1-v2',
        $anterior->times()->withClockOut(Instants::utc('2026-03-14 14:30')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
    );

    $vigentes = array_map(static fn (ShiftEntry $entry): string => $entry->uuid(), $jornada->entries());
    $retirados = array_map(static fn (ShiftEntry $entry): string => $entry->uuid(), $jornada->retiredEntries());

    expect($anterior->status())->toBe(ShiftEntryStatus::SUPERSEDED)
        // `superseded_by_id`: es lo que hace recorrible el historico (RL-04).
        ->and($anterior->supersededByUuid())->toBe('shift-entry-1-v2')
        // La jornada tiene UN tramo, no dos: la version vieja es historia y no
        // puede contar dos veces en el total (ADR-026).
        ->and($vigentes)->toBe(['shift-entry-1-v2'])
        // Pero sigue en el agregado para que el repositorio la escriba: nada se
        // borra (regla dura 5).
        ->and($retirados)->toBe(['shift-entry-1']);
})->group('RN-13', 'RF-PA-04');

it('recalcula el total del dia tras corregir la hora de salida', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 15:00')
        ->build();

    expect($jornada->totalWorked()->minutes)->toBe(480);

    $jornada->correctEntry(
        'shift-entry-1',
        'shift-entry-1-v2',
        $jornada->entry('shift-entry-1')->times()->withClockOut(Instants::utc('2026-03-14 14:30')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
    );

    $eventos = $jornada->releaseEvents();

    // 450, no 930. Si el total se incrementara —o si la version sustituida
    // siguiera contando— el dia valdria la suma de las dos versiones, que es el
    // dato de nomina erroneo que ADR-026 existe para evitar.
    expect($jornada->totalWorked()->minutes)->toBe(450)
        ->and(RecordedEvents::dailyTotals($eventos)->total->minutes)->toBe(450)
        ->and(RecordedEvents::dailyTotals($eventos)->shiftCount)->toBe(1)
        ->and(recordedCorrection($eventos)->dailyTotal->minutes)->toBe(450);
})->group('RN-06', 'RN-13');

it('no genera ningun tramo artificial al corregir un turno que cruza la medianoche', function (): void {
    // 22:00 -> 06:00 en Madrid, en marzo (CET, +01:00). La correccion mueve la
    // salida a las 06:30 locales.
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withShift(ShiftEntryFactory::new()->workedBetween(
            Instants::inMadrid('2026-03-14 22:00'),
            Instants::inMadrid('2026-03-15 06:00'),
        ))
        ->build();

    $corregido = $jornada->correctEntry(
        'shift-entry-1',
        'shift-entry-1-v2',
        $jornada->entry('shift-entry-1')->times()->withClockOut(Instants::inMadrid('2026-03-15 06:30')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
    );

    $marcas = array_map(
        static fn (ShiftEntry $entry): string => $entry->clockedInAt()->format(DateTimeImmutable::ATOM)
            .' -> '.($entry->clockedOutAt()?->format(DateTimeImmutable::ATOM) ?? 'abierto'),
        $jornada->entries(),
    );

    // UN tramo, dos marcas, y ni una mas: nada se parte a las 23:59 (RN-05,
    // ADR-006, regla dura 4).
    expect($marcas)->toBe(['2026-03-14T21:00:00+00:00 -> 2026-03-15T05:30:00+00:00'])
        ->and($jornada->shiftCount())->toBe(1)
        ->and($corregido->workedDuration()->minutes)->toBe(510)
        ->and($jornada->totalWorked()->minutes)->toBe(510)
        // La jornada sigue siendo la del dia en que el turno empezo.
        ->and($corregido->workDate()->isoDate)->toBe('2026-03-14')
        ->and($jornada->workDate()->isoDate)->toBe('2026-03-14');
})->group('RN-05', 'RF-AT-08');

it('cierra un tramo abierto con motivo y deja constancia de como estaba', function (): void {
    // El escenario Gherkin del doc 01 §11: «un tramo abierto de la empleada
    // Ana; el responsable lo cierra a las 15:00 con motivo olvido de fichaje».
    $jornada = WorkDayFactory::new()
        ->forEmployee('ana')
        ->onWorkDate('2026-03-14')
        ->withOpenShiftSince('2026-03-14 07:00')
        ->build();

    $cerrado = $jornada->correctEntry(
        'shift-entry-1',
        'shift-entry-1-v2',
        $jornada->entry('shift-entry-1')->times()->withClockOut(Instants::utc('2026-03-14 15:00')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::new()->by(42)->at('2026-03-14 16:20')->because(CorrectionReasonCode::OLVIDO_FICHAJE_SALIDA)->build(),
        ClockingPolicyFactory::standard(),
    );

    $correccion = recordedCorrection($jornada->releaseEvents());
    $anterior = $jornada->retiredEntries()[0];

    expect($cerrado->status())->toBe(ShiftEntryStatus::CLOSED)
        ->and($cerrado->clockedOutAt()?->format('H:i'))->toBe('15:00')
        // Cerrar un turno olvidado y cambiar la hora de uno cerrado son dos
        // hechos distintos, y el trail tiene que poder contarlos por separado.
        ->and($correccion->action)->toBe(CorrectionAction::CLOSED)
        ->and($correccion->correction->performedByUserId)->toBe(42)
        ->and($correccion->correction->performedAt->format('H:i'))->toBe('16:20')
        ->and($correccion->correction->reason->code)->toBe(CorrectionReasonCode::OLVIDO_FICHAJE_SALIDA)
        // El valor anterior viaja en el evento y de ahi va a `shift_corrections.
        // before`: el registro original permanece consultable.
        ->and($correccion->before?->isOpen())->toBeTrue()
        ->and($correccion->after?->clockedOutAt?->format('H:i'))->toBe('15:00')
        ->and($anterior->clockedOutAt())->toBeNull()
        ->and($anterior->status())->toBe(ShiftEntryStatus::SUPERSEDED)
        // Y el total del dia se recalcula, no se incrementa.
        ->and($jornada->totalWorked()->minutes)->toBe(480);
})->group('RN-13', 'RN-06', 'RF-PA-04');

it('distingue modificar la hora de cerrar un tramo abierto', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 15:00')
        ->build();

    $jornada->correctEntry(
        'shift-entry-1',
        'shift-entry-1-v2',
        $jornada->entry('shift-entry-1')->times()->withClockOut(Instants::utc('2026-03-14 14:30')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
    );

    expect(recordedCorrection($jornada->releaseEvents())->action)->toBe(CorrectionAction::MODIFIED);
})->group('RF-PA-04');

it('marca como manual solo la marca que se rectifico', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 15:00')
        ->build();

    $corregido = $jornada->correctEntry(
        'shift-entry-1',
        'shift-entry-1-v2',
        $jornada->entry('shift-entry-1')->times()->withClockOut(Instants::utc('2026-03-14 14:30')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
    );

    // La entrada la ficho la persona con su tarjeta y eso no ha dejado de ser
    // verdad porque el responsable rectificara la salida.
    expect($corregido->clockInSource())->toBe(ScanOrigin::QR_KIOSK)
        ->and($corregido->clockOutSource())->toBe(ScanOrigin::MANUAL_ADMIN);
})->group('RN-13', 'RF-PA-04');

it('vuelve a clasificar la duracion de la version corregida', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 15:00')
        ->build();

    // 07:00 -> 21:00 son catorce horas: por encima del umbral de 12 h de RN-08.
    $corregido = $jornada->correctEntry(
        'shift-entry-1',
        'shift-entry-1-v2',
        $jornada->entry('shift-entry-1')->times()->withClockOut(Instants::utc('2026-03-14 21:00')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::standard(),
        ClockingPolicyFactory::standard(),
    );

    $correccion = recordedCorrection($jornada->releaseEvents());

    // Se marca para revision humana; no se rechaza ni se recorta (RN-08: nunca
    // se cierra ni se ajusta automaticamente).
    expect($corregido->status())->toBe(ShiftEntryStatus::ANOMALOUS)
        ->and($correccion->anomalies)->toHaveCount(1)
        ->and($jornada->hasAnomaly())->toBeTrue()
        ->and($jornada->totalWorked()->minutes)->toBe(840);
})->group('RN-08', 'RN-13');

it('anula un tramo sin crear version nueva y descuenta sus minutos', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 11:00')
        ->withClosedShift('2026-03-14 12:00', '2026-03-14 15:00')
        ->build();

    expect($jornada->totalWorked()->minutes)->toBe(420);

    $anulado = $jornada->voidEntry(
        'shift-entry-2',
        CorrectionFactory::new()->because(CorrectionReasonCode::ERROR_DE_ESCANEO_DUPLICADO)->build(),
    );

    $correccion = recordedCorrection($jornada->releaseEvents());

    expect($anulado->status())->toBe(ShiftEntryStatus::VOIDED)
        // Un tramo que no ocurrio no tiene version posterior (ADR-026).
        ->and($anulado->version())->toBe(1)
        ->and($anulado->supersededByUuid())->toBeNull()
        ->and($correccion->action)->toBe(CorrectionAction::VOIDED)
        ->and($correccion->after)->toBeNull()
        ->and($correccion->before?->clockedOutAt?->format('H:i'))->toBe('15:00')
        // El total BAJA. Es el caso que un acumulador se equivocaria.
        ->and($jornada->totalWorked()->minutes)->toBe(240)
        ->and($jornada->shiftCount())->toBe(1)
        ->and($correccion->dailyTotal->minutes)->toBe(240);
})->group('RN-06', 'RN-13', 'RF-PA-04');

it('libera el turno abierto al anular el tramo que lo tenia', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withOpenShiftSince('2026-03-14 07:00')
        ->build();

    $jornada->voidEntry('shift-entry-1', CorrectionFactory::standard());

    // RN-01 se aplica al conjunto vigente: anular libera el hueco y el empleado
    // puede volver a fichar entrada.
    expect($jornada->hasOpenEntry())->toBeFalse();

    $jornada->clockIn('shift-entry-2', Instants::utc('2026-03-14 08:00'), ScanOrigin::QR_KIOSK);

    expect($jornada->hasOpenEntry())->toBeTrue()
        ->and($jornada->shiftCount())->toBe(1);
})->group('RN-01', 'RN-13');

it('da de alta un tramo que nunca se ficho', function (): void {
    $jornada = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();

    $entry = $jornada->addEntry(
        'shift-entry-manual',
        ShiftTimes::closed(Instants::utc('2026-03-14 07:00'), Instants::utc('2026-03-14 15:00')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::new()->because(CorrectionReasonCode::CREDENCIAL_NO_ENTREGADA)->build(),
        ClockingPolicyFactory::standard(),
    );

    $correccion = recordedCorrection($jornada->releaseEvents());

    expect($entry->version())->toBe(1)
        ->and($entry->status())->toBe(ShiftEntryStatus::CLOSED)
        ->and($entry->clockInSource())->toBe(ScanOrigin::MANUAL_ADMIN)
        ->and($entry->clockOutSource())->toBe(ScanOrigin::MANUAL_ADMIN)
        ->and($correccion->action)->toBe(CorrectionAction::CREATED)
        // Antes no habia nada; despues, ocho horas.
        ->and($correccion->before)->toBeNull()
        ->and($correccion->after?->duration()->minutes)->toBe(480)
        ->and($jornada->totalWorked()->minutes)->toBe(480);
})->group('RF-PA-04', 'RN-13');

it('fecha el recalculo en el momento de la correccion y no en el del trabajo', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 15:00')
        ->build();

    $jornada->correctEntry(
        'shift-entry-1',
        'shift-entry-1-v2',
        $jornada->entry('shift-entry-1')->times()->withClockOut(Instants::utc('2026-03-14 14:30')),
        ScanOrigin::MANUAL_ADMIN,
        CorrectionFactory::new()->at('2026-04-02 11:15')->build(),
        ClockingPolicyFactory::standard(),
    );

    $eventos = $jornada->releaseEvents();

    // Tres semanas despues. El asiento de auditoria se fecha cuando se
    // rectifico; las horas trabajadas siguen siendo las de marzo.
    expect(recordedCorrection($eventos)->occurredAt()->format('Y-m-d H:i'))->toBe('2026-04-02 11:15')
        ->and(RecordedEvents::dailyTotals($eventos)->recalculatedAt->format('Y-m-d H:i'))->toBe('2026-04-02 11:15');
})->group('RN-13', 'RQ-01');

it('nombra el evento de correccion una sola vez y para siempre', function (): void {
    $jornada = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 07:00', '2026-03-14 15:00')
        ->build();

    $jornada->voidEntry('shift-entry-1', CorrectionFactory::standard());

    // El nombre viaja al bus y de ahi a los listeners de Compliance y
    // Reporting: cambiarlo rompe a quien escucha sin que nada mas se entere.
    expect(RecordedEvents::names($jornada->releaseEvents()))
        ->toBe(['attendance.shift_corrected', 'attendance.daily_totals_recalculated']);
})->group('RN-13');
