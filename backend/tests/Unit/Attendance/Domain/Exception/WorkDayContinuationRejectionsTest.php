<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\CorrectionWouldChangeWorkDate;
use App\Modules\Attendance\Domain\Exception\EntryDoesNotContinueWorkDay;
use App\Modules\Attendance\Domain\ValueObject\ContinuationCeiling;
use App\Modules\Attendance\Domain\ValueObject\FutureMark;
use App\Modules\Attendance\Domain\ValueObject\WorkedDuration;
use Tests\Support\Shared\RejectionTranslations;

/*
 * Lo que dicen los rechazos de un tramo que no continua su jornada (RN-05,
 * ADR-006, regla dura 4).
 *
 * Quien corrige un registro desde el panel tiene que saber si el problema es el
 * tramo que esta escribiendo o el siguiente, que quedaria huerfano de jornada:
 * son dos textos distintos y dos campos distintos del formulario. Los casos usan
 * una jornada del 28-03-2026 cuya ultima marca es a las 22:00 UTC y un descanso
 * minimo de 12 horas.
 */

dataset('rechazos de un tramo que no continua la jornada', [
    'el tramo escrito queda a la cota de descanso o mas alla' => [
        fn (): EntryDoesNotContinueWorkDay => EntryDoesNotContinueWorkDay::writtenEntry(
            FutureMark::ClockIn,
            '2026-03-28',
            new DateTimeImmutable('2026-03-28T22:00:00+00:00'),
            new DateTimeImmutable('2026-03-29T10:00:00+00:00'),
            ContinuationCeiling::belowRestOf(WorkedDuration::ofHours(12)),
        ),
        'attendance.errors.entry_does_not_continue_work_day',
        ['work_date' => '2026-03-28', 'hours' => '12'],
        'A shift entry starting at 2026-03-29T10:00:00+00:00 does not continue work day 2026-03-28: '
        .'the previous mark 2026-03-28T22:00:00+00:00 is 720 minutes or more before it (RN-05).',
        false,
    ],
    'el tramo siguiente quedaria a la cota de descanso o mas alla' => [
        fn (): EntryDoesNotContinueWorkDay => EntryDoesNotContinueWorkDay::nextEntry(
            FutureMark::ClockIn,
            '2026-03-28',
            new DateTimeImmutable('2026-03-28T22:00:00+00:00'),
            new DateTimeImmutable('2026-03-29T10:00:00+00:00'),
            ContinuationCeiling::belowRestOf(WorkedDuration::ofHours(12)),
        ),
        'attendance.errors.next_entry_would_not_continue_work_day',
        ['work_date' => '2026-03-28', 'hours' => '12'],
        'The next shift entry starting at 2026-03-29T10:00:00+00:00 does not continue work day 2026-03-28: '
        .'the previous mark 2026-03-28T22:00:00+00:00 is 720 minutes or more before it (RN-05).',
        true,
    ],
    'el tramo escrito no sigue a una pausa' => [
        fn (): EntryDoesNotContinueWorkDay => EntryDoesNotContinueWorkDay::notAfterBreak(
            FutureMark::ClockIn,
            '2026-03-28',
            new DateTimeImmutable('2026-03-29T01:00:00+00:00'),
            true,
        ),
        'attendance.errors.entry_not_after_break',
        ['work_date' => '2026-03-28'],
        'A shift entry starting at 2026-03-29T01:00:00+00:00 does not continue work day 2026-03-28: '
        .'the previous entry did not end in a break (RN-05).',
        false,
    ],
    'el tramo siguiente no sigue a una pausa' => [
        fn (): EntryDoesNotContinueWorkDay => EntryDoesNotContinueWorkDay::notAfterBreak(
            FutureMark::ClockOut,
            '2026-03-28',
            new DateTimeImmutable('2026-03-29T01:00:00+00:00'),
            false,
        ),
        'attendance.errors.entry_not_after_break',
        ['work_date' => '2026-03-28'],
        'The next shift entry starting at 2026-03-29T01:00:00+00:00 does not continue work day 2026-03-28: '
        .'the previous entry did not end in a break (RN-05).',
        true,
    ],
    'el tramo escrito empieza despues del dia siguiente' => [
        fn (): EntryDoesNotContinueWorkDay => EntryDoesNotContinueWorkDay::beyondNextDay(
            FutureMark::ClockIn,
            '2026-03-28',
            '2026-03-29',
            new DateTimeImmutable('2026-03-30T00:30:00+00:00'),
            true,
        ),
        'attendance.errors.entry_beyond_next_day',
        ['work_date' => '2026-03-28', 'next_day' => '2026-03-29'],
        'A shift entry starting at 2026-03-30T00:30:00+00:00 does not continue work day 2026-03-28: '
        .'a work day only continues until 2026-03-29 (RN-05).',
        false,
    ],
    'el tramo siguiente empezaria despues del dia siguiente' => [
        fn (): EntryDoesNotContinueWorkDay => EntryDoesNotContinueWorkDay::beyondNextDay(
            FutureMark::ClockOut,
            '2026-03-28',
            '2026-03-29',
            new DateTimeImmutable('2026-03-30T00:30:00+00:00'),
            false,
        ),
        'attendance.errors.entry_beyond_next_day',
        ['work_date' => '2026-03-28', 'next_day' => '2026-03-29'],
        'The next shift entry starting at 2026-03-30T00:30:00+00:00 does not continue work day 2026-03-28: '
        .'a work day only continues until 2026-03-29 (RN-05).',
        true,
    ],
    'otra jornada se interpone ante el tramo escrito' => [
        fn (): EntryDoesNotContinueWorkDay => EntryDoesNotContinueWorkDay::interruptedByAnotherWorkDay(
            FutureMark::ClockIn,
            '2026-03-28',
            new DateTimeImmutable('2026-03-28T22:00:00+00:00'),
            new DateTimeImmutable('2026-03-29T06:00:00+00:00'),
            ContinuationCeiling::belowRestOf(WorkedDuration::ofHours(12)),
            true,
        ),
        'attendance.errors.entry_does_not_continue_work_day',
        ['work_date' => '2026-03-28', 'hours' => '12'],
        'A shift entry starting at 2026-03-29T06:00:00+00:00 does not continue work day 2026-03-28: '
        .'a current shift entry of another work day lies between the previous mark 2026-03-28T22:00:00+00:00 and it (RN-05).',
        false,
    ],
    'otra jornada se interpondria ante el tramo siguiente' => [
        fn (): EntryDoesNotContinueWorkDay => EntryDoesNotContinueWorkDay::interruptedByAnotherWorkDay(
            FutureMark::ClockIn,
            '2026-03-28',
            new DateTimeImmutable('2026-03-28T22:00:00+00:00'),
            new DateTimeImmutable('2026-03-29T06:00:00+00:00'),
            ContinuationCeiling::belowRestOf(WorkedDuration::ofHours(12)),
            false,
        ),
        'attendance.errors.next_entry_would_not_continue_work_day',
        ['work_date' => '2026-03-28', 'hours' => '12'],
        'The next shift entry starting at 2026-03-29T06:00:00+00:00 does not continue work day 2026-03-28: '
        .'a current shift entry of another work day lies between the previous mark 2026-03-28T22:00:00+00:00 and it (RN-05).',
        true,
    ],
]);

it('dice que jornada no continua el tramo, por que y con que cota', function (
    EntryDoesNotContinueWorkDay $rechazo,
    string $clave,
    array $parametros,
    string $mensaje,
): void {
    expect($rechazo->translationKey)->toBe($clave)
        ->and($rechazo->parameters)->toBe($parametros)
        ->and($rechazo->getMessage())->toBe($mensaje);
})->with('rechazos de un tramo que no continua la jornada')->group('RN-05');

it('distingue si el rechazo es del tramo escrito o del siguiente', function (
    EntryDoesNotContinueWorkDay $rechazo,
    string $clave,
    array $parametros,
    string $mensaje,
    bool $esDelSiguiente,
): void {
    expect($rechazo->concernsNextEntry)->toBe($esDelSiguiente);
})->with('rechazos de un tramo que no continua la jornada')->group('RN-05');

it('señala la marca que hay que corregir', function (): void {
    $rechazo = EntryDoesNotContinueWorkDay::notAfterBreak(
        FutureMark::ClockOut,
        '2026-03-28',
        new DateTimeImmutable('2026-03-29T01:00:00+00:00'),
        false,
    );

    expect($rechazo->mark)->toBe(FutureMark::ClockOut);
})->group('RN-05');

it('tiene texto en español y en ingles con todos sus marcadores sustituidos', function (EntryDoesNotContinueWorkDay $rechazo): void {
    expect(RejectionTranslations::problemsWith($rechazo->translationKey, $rechazo->parameters))->toBe([]);
})->with('rechazos de un tramo que no continua la jornada')->group('RN-05', 'RF-KI-05');

it('dice que corregir la entrada moveria el tramo a otra jornada', function (): void {
    $rechazo = CorrectionWouldChangeWorkDate::from('0199f6a2-0000-7d3b-8a90-1b2c3d4e5f60', '2026-03-28', '2026-03-29');

    expect($rechazo->byVoiding)->toBeFalse()
        ->and($rechazo->workDate)->toBe('2026-03-28')
        ->and($rechazo->getMessage())->toBe(
            'Correcting the clock-in of shift entry 0199f6a2-0000-7d3b-8a90-1b2c3d4e5f60 would move it from work day '
            .'2026-03-28 to 2026-03-29 (RN-05). Void it here and create it there instead.',
        );
})->group('RN-05', 'RN-13');

it('dice que anular el tramo dejaria la jornada abierta por un tramo de otro dia', function (): void {
    $rechazo = CorrectionWouldChangeWorkDate::byVoiding('0199f6a2-0000-7d3b-8a90-1b2c3d4e5f60', '2026-03-28', '2026-03-29');

    expect($rechazo->byVoiding)->toBeTrue()
        ->and($rechazo->workDate)->toBe('2026-03-28')
        ->and($rechazo->getMessage())->toBe(
            'Voiding shift entry 0199f6a2-0000-7d3b-8a90-1b2c3d4e5f60 would leave work day 2026-03-28 '
            .'opened by a shift entry of 2026-03-29 (RN-05). Void the later entries first.',
        );
})->group('RN-05', 'RN-13');
