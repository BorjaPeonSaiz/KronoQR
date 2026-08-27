<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Model\ShiftEntry;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use Tests\Support\Domain\RecordedEvents;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Time\Instants;

/*
 * RN-05, RF-AT-08, ADR-006 y regla dura 4: un turno 22:00 -> 06:00 es UN tramo,
 * atribuido a la jornada de su hora de inicio.
 *
 * La solucion intuitiva —partirlo a las 23:59— fabrica dos marcas que nadie
 * produjo, rompe el calculo del descanso entre jornadas y distorsiona la
 * jornada diaria. Por eso cada prueba de aqui comprueba las TRES cosas que pide
 * el §9.4: duracion, atribucion y ausencia de tramos artificiales.
 *
 * Las horas se escriben en UTC porque es lo que el dominio recibe. En marzo,
 * Madrid va en CET (+01:00): las 22:00 locales del dia 14 son las 21:00 UTC.
 */

it('no parte un turno que cruza la medianoche', function (): void {
    $workDay = WorkDayFactory::new()->forEmployee('marc')->onWorkDate('2026-03-14')->build();

    $workDay->clockIn('shift-entry-1', Instants::inMadrid('2026-03-14 22:00'), ScanOrigin::QR_KIOSK);
    $entry = $workDay->clockOut(Instants::inMadrid('2026-03-15 06:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    expect($entry->workedDuration()->minutes)->toBe(480)
        ->and($workDay->shiftCount())->toBe(1)
        ->and($entry->workDate()->isoDate)->toBe('2026-03-14');
})->group('RN-05', 'RF-AT-08', 'RQ-01');

it('no fabrica ningun tramo artificial a las 23:59', function (): void {
    $workDay = WorkDayFactory::new()->forEmployee('marc')->onWorkDate('2026-03-14')->build();

    $workDay->clockIn('shift-entry-1', Instants::inMadrid('2026-03-14 22:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::inMadrid('2026-03-15 06:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    $clockIns = array_map(
        static fn (ShiftEntry $entry): string => $entry->clockedInAt()->format(DateTimeImmutable::ATOM),
        $workDay->entries(),
    );
    $clockOuts = array_map(
        static fn (ShiftEntry $entry): ?string => $entry->clockedOutAt()?->format(DateTimeImmutable::ATOM),
        $workDay->entries(),
    );

    // Las 22:00 y las 06:00 de Madrid en marzo, y ni una marca mas.
    expect($clockIns)->toBe(['2026-03-14T21:00:00+00:00'])
        ->and($clockOuts)->toBe(['2026-03-15T05:00:00+00:00']);
})->group('RN-05', 'RF-AT-08');

it('atribuye el turno de noche entero a la jornada del dia en que empezo', function (): void {
    $workDay = WorkDayFactory::new()->forEmployee('marc')->onWorkDate('2026-03-14')->build();

    $workDay->clockIn('shift-entry-1', Instants::inMadrid('2026-03-14 22:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::inMadrid('2026-03-15 06:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $totals = RecordedEvents::dailyTotals($workDay->releaseEvents());

    expect($totals->workDate->isoDate)->toBe('2026-03-14')
        ->and($totals->total->minutes)->toBe(480)
        ->and($totals->shiftCount)->toBe(1);
})->group('RN-05', 'RF-AT-08');

it('deja a cero la jornada del dia siguiente al turno de noche', function (): void {
    // El dia 15 no recibe ni un minuto del turno que empezo el 14, aunque seis
    // de sus horas hayan ocurrido en la fecha civil del 15.
    $nextDay = WorkDayFactory::new()->forEmployee('marc')->onWorkDate('2026-03-15')->build();

    expect($nextDay->totalWorked()->minutes)->toBe(0)
        ->and($nextDay->shiftCount())->toBe(0);
})->group('RN-05', 'RF-AT-08');

it('la vuelta de una pausa hereda la jornada aunque sea otro dia natural', function (): void {
    // ADR-024. Es la prueba que falla si el agregado derivase work_date por
    // tramo: la entrada de las 02:30 abriria una jornada nueva y dos personas
    // del mismo turno acabarian con registros diarios distintos segun si
    // ficharon el descanso.
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();

    $workDay->clockIn('shift-entry-1', Instants::inMadrid('2026-03-14 22:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::inMadrid('2026-03-15 02:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $workDay->clockIn('shift-entry-2', Instants::inMadrid('2026-03-15 02:30'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::inMadrid('2026-03-15 06:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    expect($workDay->totalWorked()->minutes)->toBe(450);
})->group('RN-05', 'RN-12', 'RF-AT-12');

it('da la misma jornada a los dos tramos que separa una pausa de madrugada', function (): void {
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();

    $workDay->clockIn('shift-entry-1', Instants::inMadrid('2026-03-14 22:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::inMadrid('2026-03-15 02:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $workDay->clockIn('shift-entry-2', Instants::inMadrid('2026-03-15 02:30'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::inMadrid('2026-03-15 06:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    $workDates = array_map(
        static fn (ShiftEntry $entry): string => $entry->workDate()->isoDate,
        $workDay->entries(),
    );

    expect($workDates)->toBe(['2026-03-14', '2026-03-14']);
})->group('RN-05', 'RN-12', 'RF-AT-12');

it('deja a cero el dia siguiente cuando la pausa cae de madrugada', function (): void {
    $nextDay = WorkDayFactory::new()->onWorkDate('2026-03-15')->build();

    expect($nextDay->totalWorked()->minutes)->toBe(0);
})->group('RN-05', 'RF-AT-12');

it('no parte tampoco un turno que empieza justo antes de la medianoche', function (): void {
    $workDay = WorkDayFactory::new()->onWorkDate('2026-03-14')->build();

    $workDay->clockIn('shift-entry-1', Instants::inMadrid('2026-03-14 23:59'), ScanOrigin::QR_KIOSK);
    $entry = $workDay->clockOut(Instants::inMadrid('2026-03-15 00:01'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    expect($entry->workedDuration()->minutes)->toBe(2)
        ->and($workDay->shiftCount())->toBe(1);
})->group('RN-05', 'RF-AT-08');
