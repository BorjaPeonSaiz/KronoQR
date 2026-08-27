<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\ShiftAnomaly;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryStatus;
use Tests\Support\Domain\RecordedEvents;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Time\Instants;

/*
 * Los limites de duracion de un tramo, vistos desde el agregado: RN-07 (minimo
 * computable) y RN-08 (maximo antes de considerarse anomalo).
 *
 * Lo que hay que demostrar no es solo que se clasifican bien, sino que el tramo
 * **se registra igual**: la anomalia marca para revision humana y no deshace lo
 * que el empleado ficho (regla dura 19, RN-08 literal: «nunca se cierra
 * automaticamente sin intervencion humana»).
 *
 * Los umbrales van escritos en cada prueba: 1 minuto y 12 h son el perfil de
 * serie, no una constante del codigo (regla dura 14).
 */

it('registra el tramo de cero minutos en vez de descartarlo', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 08:00:00')->build();

    $entry = $workDay->clockOut(
        Instants::utc('2026-03-14 08:00:30'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withMinimumComputableMinutes(1)->build(),
    );

    expect($entry->workedDuration()->minutes)->toBe(0)
        ->and($entry->clockedOutAt()?->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T08:00:30+00:00')
        ->and($workDay->shiftCount())->toBe(1);
})->group('RN-07', 'RQ-01');

it('marca como incidencia el tramo de 59 segundos', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 08:00:00')->build();

    $entry = $workDay->clockOut(
        Instants::utc('2026-03-14 08:00:59'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withMinimumComputableMinutes(1)->build(),
    );

    expect($entry->status())->toBe(ShiftEntryStatus::ANOMALOUS)
        ->and($entry->workedDuration()->minutes)->toBe(0);
})->group('RN-07', 'RQ-01');

it('no marca como incidencia el tramo de 60 segundos', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 08:00:00')->build();

    $entry = $workDay->clockOut(
        Instants::utc('2026-03-14 08:01:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withMinimumComputableMinutes(1)->build(),
    );

    expect($entry->status())->toBe(ShiftEntryStatus::CLOSED)
        ->and($entry->workedDuration()->minutes)->toBe(1);
})->group('RN-07', 'RQ-01');

it('anuncia el tramo corto como anomalia en el evento de salida', function (): void {
    // Compliance abre la incidencia al recibirlo. El empleado no se entera y su
    // fichaje queda registrado.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 08:00:00')->build();

    $workDay->clockOut(
        Instants::utc('2026-03-14 08:00:59'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withMinimumComputableMinutes(1)->build(),
    );
    $clockedOut = RecordedEvents::clockedOut($workDay->releaseEvents());

    expect($clockedOut->anomalies)->toBe([ShiftAnomaly::SHORT_SHIFT])
        ->and($clockedOut->worked->minutes)->toBe(0);
})->group('RN-07');

it('cuenta el tramo corto en el total del dia aunque este marcado', function (): void {
    // Se marca para revision, no se resta: el registro legal recoge lo fichado.
    $workDay = WorkDayFactory::new()
        ->withClosedShift('2026-03-14 06:00', '2026-03-14 08:00')
        ->build();

    $workDay->clockIn('shift-entry-2', Instants::utc('2026-03-14 09:00:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(
        Instants::utc('2026-03-14 09:01:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withMinimumComputableMinutes(5)->build(),
    );

    expect($workDay->totalWorked()->minutes)->toBe(121)
        ->and($workDay->hasAnomaly())->toBeTrue();
})->group('RN-07', 'RN-06');

it('deja normal el tramo de 11 h 59 min con el umbral en 12 h', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 06:00')->build();

    $entry = $workDay->clockOut(
        Instants::utc('2026-03-14 17:59'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withAnomalousAfterHours(12)->build(),
    );

    expect($entry->workedDuration()->minutes)->toBe(719)
        ->and($entry->status())->toBe(ShiftEntryStatus::CLOSED);
})->group('RN-08', 'RQ-01');

it('deja normal el tramo de 12 h exactas con el umbral en 12 h', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 06:00')->build();

    $entry = $workDay->clockOut(
        Instants::utc('2026-03-14 18:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withAnomalousAfterHours(12)->build(),
    );

    expect($entry->workedDuration()->minutes)->toBe(720)
        ->and($entry->status())->toBe(ShiftEntryStatus::CLOSED);
})->group('RN-08', 'RQ-01');

it('marca como anomalo el tramo de 12 h y 1 min con el umbral en 12 h', function (): void {
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 06:00')->build();

    $entry = $workDay->clockOut(
        Instants::utc('2026-03-14 18:01'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withAnomalousAfterHours(12)->build(),
    );

    expect($entry->workedDuration()->minutes)->toBe(721)
        ->and($entry->status())->toBe(ShiftEntryStatus::ANOMALOUS);
})->group('RN-08', 'RQ-01');

it('cierra el tramo de 13 h con sus marcas reales y lo deja para revision humana', function (): void {
    // RN-08 literal: «nunca se cierra automaticamente sin intervencion humana».
    // El tramo queda cerrado a la hora que la persona ficho, con las 13 h
    // completas contadas, y lo que cambia es que alguien tiene que mirarlo.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 06:00')->build();

    $entry = $workDay->clockOut(
        Instants::utc('2026-03-14 19:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withAnomalousAfterHours(12)->build(),
    );

    expect($entry->workedDuration()->minutes)->toBe(780)
        ->and($entry->clockedInAt()->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T06:00:00+00:00')
        ->and($entry->clockedOutAt()?->format(DateTimeImmutable::ATOM))->toBe('2026-03-14T19:00:00+00:00')
        ->and($workDay->totalWorked()->minutes)->toBe(780);
})->group('RN-08', 'RQ-01');

it('no acorta ni recorta al umbral el tramo que declara anomalo', function (): void {
    // Si la politica truncase a 12 h, la nomina pagaria 720 minutos de los 780
    // que la persona ficho, y nadie sabria que se han perdido.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 06:00')->build();

    $workDay->clockOut(
        Instants::utc('2026-03-14 19:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withAnomalousAfterHours(12)->build(),
    );
    $totals = RecordedEvents::dailyTotals($workDay->releaseEvents());

    expect($totals->total->minutes)->toBe(780);
})->group('RN-08', 'RN-06');

it('marca a la vez el tramo corto y el largo cuando la politica los solapa en un mismo umbral', function (): void {
    // Con minimo y umbral anomalo en el mismo valor, la duracion exacta no es ni
    // corta ni larga: son dos comparaciones estrictas y ninguna se dispara.
    $workDay = WorkDayFactory::new()->withOpenShiftSince('2026-03-14 06:00')->build();

    $entry = $workDay->clockOut(
        Instants::utc('2026-03-14 07:00'),
        ScanOrigin::QR_KIOSK,
        ClockingPolicyFactory::new()->withMinimumComputableMinutes(60)->withAnomalousAfterMinutes(60)->build(),
    );

    expect($entry->status())->toBe(ShiftEntryStatus::CLOSED);
})->group('RN-07', 'RN-08');
