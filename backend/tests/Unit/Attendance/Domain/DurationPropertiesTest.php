<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\TimeRange;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Factory\WorkDayFactory;
use Tests\Support\Property\DurationSamples;
use Tests\Support\Time\Instants;

/*
 * RQ-02: el calculo de duraciones se prueba con pruebas basadas en propiedades,
 * incluidos los dias de cambio de hora y los turnos que cruzan medianoche.
 *
 * Un caso concreto demuestra que una hora sale bien; una propiedad demuestra
 * que sale bien para cualquier hora del ano. Las muestras son deterministas
 * (`DurationSamples`): un caso que falla hoy falla igual manana.
 */

it('mide exactamente los minutos que separan los dos instantes, sea cual sea el dia del ano', function (string $startUtc, int $minutes): void {
    $start = Instants::utc($startUtc);
    $end = $start->modify('+'.$minutes.' minutes');

    $range = new TimeRange($start, $end);

    expect($range->duration()->minutes)->toBe($minutes);
})->with(DurationSamples::shiftsAcrossTheYear())->group('RN-09', 'RQ-02');

it('mide lo mismo aunque el turno atraviese un cambio de hora', function (string $startUtc, int $minutes): void {
    // El sentido del salto no entra en la cuenta: la resta es sobre instantes.
    $start = Instants::utc($startUtc);
    $end = $start->modify('+'.$minutes.' minutes');

    $range = new TimeRange($start, $end);

    expect($range->duration()->minutes)->toBe($minutes);
})->with(DurationSamples::shiftsAcrossEachTransition())->group('RN-09', 'RQ-02');

it('no pierde ni gana minutos al partir un turno en dos', function (string $startUtc, int $first, int $second): void {
    $start = Instants::utc($startUtc);
    $middle = $start->modify('+'.$first.' minutes');
    $end = $middle->modify('+'.$second.' minutes');

    $whole = (new TimeRange($start, $end))->duration();
    $parts = (new TimeRange($start, $middle))->duration()->plus((new TimeRange($middle, $end))->duration());

    expect($whole->minutes)->toBe($parts->minutes);
})->with(DurationSamples::shiftsSplitInTwo())->group('RN-06', 'RN-09', 'RQ-02');

it('nunca parte un tramo que cruza la medianoche local', function (string $startUtc, int $minutes): void {
    $start = Instants::utc($startUtc);
    $end = $start->modify('+'.$minutes.' minutes');
    $workDay = WorkDay::start('employee-1', 1, WorkDate::fromInstant($start, Instants::madrid()));

    $workDay->clockIn('shift-entry-1', $start, ScanOrigin::QR_KIOSK);
    $entry = $workDay->clockOut($end, ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    expect($workDay->shiftCount())->toBe(1)
        ->and($entry->workedDuration()->minutes)->toBe($minutes);
})->with(DurationSamples::shiftsAcrossLocalMidnight())->group('RN-05', 'RF-AT-08', 'RQ-02');

it('atribuye a la jornada de su entrada todo tramo que cruza la medianoche local', function (string $startUtc, int $minutes): void {
    $start = Instants::utc($startUtc);
    $end = $start->modify('+'.$minutes.' minutes');
    $expectedWorkDate = WorkDate::fromInstant($start, Instants::madrid())->isoDate;
    $workDay = WorkDay::start('employee-1', 1, WorkDate::fromInstant($start, Instants::madrid()));

    $workDay->clockIn('shift-entry-1', $start, ScanOrigin::QR_KIOSK);
    $entry = $workDay->clockOut($end, ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    expect($entry->workDate()->isoDate)->toBe($expectedWorkDate);
})->with(DurationSamples::shiftsAcrossLocalMidnight())->group('RN-05', 'RF-AT-08', 'RQ-02');

it('da como total del dia la suma exacta de sus tramos, sean los que sean', function (array $shifts, int $expectedMinutes): void {
    /** @var list<array{string, string}> $shifts */
    $workDay = WorkDayFactory::new()->onWorkDate('2026-06-15')->withClosedShifts($shifts)->reconstituted();

    expect($workDay->totalWorked()->minutes)->toBe($expectedMinutes)
        ->and($workDay->shiftCount())->toBe(count($shifts));
})->with(DurationSamples::splitWorkDays())->group('RN-06', 'RF-AT-04', 'RQ-02');
