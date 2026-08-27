<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Model\ShiftEntry;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use Tests\Support\Domain\RecordedEvents;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\ShiftEntryFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Time\Instants;

/*
 * RN-06 y RF-AT-04: jornada partida sin limite de tramos, y total del dia
 * **recalculado** como suma de los tramos vigentes.
 *
 * Recalculado, no incrementado (regla dura 7, ADR-007). Una prueba que solo
 * sume no distingue las dos cosas: aqui hay una que anula un tramo y comprueba
 * que el total BAJA, que es lo que un acumulador no puede hacer.
 */

it('suma los cuatro tramos de una jornada partida', function (): void {
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();
    $policy = ClockingPolicyFactory::standard();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-03-14 09:00'), ScanOrigin::QR_KIOSK, $policy);
    $workDay->clockIn('shift-entry-2', Instants::utc('2026-03-14 10:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-03-14 12:00'), ScanOrigin::QR_KIOSK, $policy);
    $workDay->clockIn('shift-entry-3', Instants::utc('2026-03-14 16:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-03-14 17:30'), ScanOrigin::QR_KIOSK, $policy);
    $workDay->clockIn('shift-entry-4', Instants::utc('2026-03-14 19:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-03-14 20:30'), ScanOrigin::QR_KIOSK, $policy);

    // 180 + 120 + 90 + 90
    expect($workDay->totalWorked()->minutes)->toBe(480)
        ->and($workDay->shiftCount())->toBe(4);
})->group('RN-06', 'RF-AT-04', 'RQ-01');

it('cuadra el total del dia con los tramos que lo componen', function (): void {
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();
    $policy = ClockingPolicyFactory::standard();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-03-14 09:00'), ScanOrigin::QR_KIOSK, $policy);
    $workDay->clockIn('shift-entry-2', Instants::utc('2026-03-14 10:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-03-14 12:00'), ScanOrigin::QR_KIOSK, $policy);

    $perEntry = array_map(
        static fn (ShiftEntry $entry): int => $entry->workedDuration()->minutes,
        $workDay->entries(),
    );

    expect($perEntry)->toBe([180, 120])
        ->and($workDay->totalWorked()->minutes)->toBe(array_sum($perEntry));
})->group('RN-06', 'RF-AT-04');

it('acumula sobre el tramo previo ya cerrado de la jornada', function (): void {
    // Del doc 01 §11: 120 minutos ya registrados mas un tramo de 240 dan 360.
    $workDay = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 08:00')
        ->build();

    $workDay->clockIn('shift-entry-2', Instants::utc('2026-03-14 09:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-03-14 13:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    expect($workDay->totalWorked()->minutes)->toBe(360);
})->group('RN-06', 'RF-AT-04');

it('transporta el total completo en el evento de recalculo y no un incremento', function (): void {
    // Un evento que dijera «suma 240 minutos» no permitiria reconstruir
    // daily_totals desde cero ni sobrevivir a una correccion que baja el total.
    $workDay = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 08:00')
        ->build();

    $workDay->clockIn('shift-entry-2', Instants::utc('2026-03-14 09:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-03-14 13:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $totals = RecordedEvents::dailyTotals($workDay->releaseEvents());

    expect($totals->total->minutes)->toBe(360)
        ->and($totals->shiftCount)->toBe(2)
        ->and($totals->hasOpenShift)->toBeFalse()
        ->and($totals->hasAnomaly)->toBeFalse();
})->group('RN-06', 'RQ-01');

it('recalcula el total sin arrastrar el de la jornada anterior a la anulacion', function (): void {
    // La misma jornada, una vez con los dos tramos vigentes y otra con el
    // primero anulado. Un total acumulado seguiria en 360; el recalculo da 240,
    // que son los minutos que quedan de verdad (RN-06, ADR-026).
    $morning = ShiftEntryFactory::new()->withUuid('shift-entry-1')->worked('2026-03-14 06:00', '2026-03-14 08:00');
    $afternoon = ShiftEntryFactory::new()->withUuid('shift-entry-2')->worked('2026-03-14 09:00', '2026-03-14 13:00');

    $before = WorkDayFactory::new()->withShift($morning)->withShift($afternoon)->reconstituted();
    $after = WorkDayFactory::new()->withShift($afternoon)->reconstituted();

    expect($before->totalWorked()->minutes)->toBe(360)
        ->and($after->totalWorked()->minutes)->toBe(240);
})->group('RN-06', 'RN-13', 'RQ-01');

it('recalcula el total a la baja cuando la correccion acorta un tramo', function (): void {
    // RN-13: la correccion no borra, crea una version nueva. El total del dia no
    // puede quedarse con los minutos de la version sustituida.
    $original = ShiftEntryFactory::new()->withUuid('shift-entry-1')->worked('2026-03-14 06:00', '2026-03-14 14:00');
    $corrected = ShiftEntryFactory::new()->withUuid('shift-entry-1b')->worked('2026-03-14 06:00', '2026-03-14 10:00')->atVersion(2);

    $before = WorkDayFactory::new()->withShift($original)->reconstituted();
    $after = WorkDayFactory::new()->withShift($corrected)->reconstituted();

    expect($before->totalWorked()->minutes)->toBe(480)
        ->and($after->totalWorked()->minutes)->toBe(240);
})->group('RN-06', 'RN-13');

it('emite el recalculo del total tambien al abrir un tramo, con el turno abierto declarado', function (): void {
    $workDay = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 08:00')
        ->build();

    $workDay->clockIn('shift-entry-2', Instants::utc('2026-03-14 09:00'), ScanOrigin::QR_KIOSK);
    $totals = RecordedEvents::dailyTotals($workDay->releaseEvents());

    expect($totals->total->minutes)->toBe(120)
        ->and($totals->hasOpenShift)->toBeTrue()
        ->and($totals->lastClockOutAt?->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T08:00:00+00:00')
        ->and($totals->firstClockInAt?->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T06:00:00+00:00');
})->group('RN-06', 'RF-AT-04');

it('declara la anomalia de la jornada en el recalculo del total', function (): void {
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-14')->withOpenShiftSince('2026-03-14 06:00')->build();

    $workDay->clockOut(
        Instants::utc('2026-03-14 19:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withAnomalousAfterHours(12)->build(),
    );
    $totals = RecordedEvents::dailyTotals($workDay->releaseEvents());

    expect($totals->hasAnomaly)->toBeTrue()
        ->and($totals->total->minutes)->toBe(780);
})->group('RN-06', 'RN-08');

it('fecha el recalculo en el instante del fichaje que lo provoco', function (): void {
    // No en el de la escritura: en un fichaje offline pueden distar horas
    // (regla dura 9, RF-AT-09).
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();

    $workDay->clockIn('shift-entry-1', Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $totals = RecordedEvents::dailyTotals($workDay->releaseEvents());

    expect($totals->recalculatedAt->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T06:00:00+00:00')
        ->and($totals->occurredAt()->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T06:00:00+00:00');
})->group('RN-06', 'RF-AT-09');

it('admite tantos tramos como haga falta sin limite configurado', function (): void {
    // RF-AT-04: «N tramos por jornada sin limite configurado».
    $workDay = WorkDayFactory::new()
        ->onWorkDate('2026-03-14')
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 07:00')
        ->withClosedShift('2026-03-14 08:00', '2026-03-14 09:00')
        ->withClosedShift('2026-03-14 10:00', '2026-03-14 11:00')
        ->withClosedShift('2026-03-14 12:00', '2026-03-14 13:00')
        ->withClosedShift('2026-03-14 14:00', '2026-03-14 15:00')
        ->withClosedShift('2026-03-14 16:00', '2026-03-14 17:00')
        ->reconstituted();

    expect($workDay->shiftCount())->toBe(6)
        ->and($workDay->totalWorked()->minutes)->toBe(360);
})->group('RF-AT-04');
