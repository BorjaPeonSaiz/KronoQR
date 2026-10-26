<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\BreakWithoutClockOut;
use App\Modules\Attendance\Domain\Policy\ScanIntentPolicy;
use App\Modules\Attendance\Domain\ValueObject\AcceptedScan;
use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
use App\Modules\Attendance\Domain\ValueObject\DeclaredIntent;
use App\Modules\Attendance\Domain\ValueObject\EntryClosure;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryTimes;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use Tests\Support\Factory\ContinuationCeilingFactory;
use Tests\Support\Time\Instants;

/*
 * La cota de continuacion de RN-05, la misma para el quiosco y para el panel
 * (2.3.0). Estricta y en segundos reales: con 12 h, 11:59:59 continua y 12:00:00
 * ya no.
 */

it('continua por debajo de la cota y deja de continuar en ella', function (string $start, bool $continues): void {
    $ceiling = ContinuationCeilingFactory::standard();

    expect($ceiling->continues(Instants::utc('2026-03-14 13:00:00'), Instants::utc($start)))->toBe($continues);
})->with([
    'en la misma marca' => ['2026-03-14 13:00:00', true],
    'antes de la marca' => ['2026-03-14 12:00:00', true],
    'un segundo antes de la cota' => ['2026-03-15 00:59:59', true],
    'en la cota' => ['2026-03-15 01:00:00', false],
    'mas alla de la cota' => ['2026-03-17 08:00:00', false],
])->group('RN-05');

it('es la misma cota con la que el quiosco decide si una vuelta continua la pausa', function (string $scan, ClockingAction $action): void {
    // La politica del fichaje se construye con el mismo objeto que el alta
    // manual: un hueco no puede ser pausa en un sitio y jornada nueva en otro.
    $policy = new ScanIntentPolicy(ContinuationCeilingFactory::standard());
    $pausa = new AcceptedScan(Instants::utc('2026-03-14 13:00:00'), ClockingAction::BREAK_START, 'shift-entry-1');

    expect($policy->resolve(DeclaredIntent::AUTO, false, Instants::utc($scan), $pausa)->action)->toBe($action);
})->with([
    'un segundo antes de la cota' => ['2026-03-15 00:59:59', ClockingAction::BREAK_END],
    'en la cota' => ['2026-03-15 01:00:00', ClockingAction::CLOCK_IN],
])->group('RN-05', 'RF-AT-12');

it('da el limite sumando la cota en tiempo real y la escribe en horas', function (): void {
    $ceiling = ContinuationCeilingFactory::standard();

    // La noche del cambio de hora de octubre: 12 h reales, no de reloj.
    expect($ceiling->limitAfter(Instants::utc('2026-10-24 23:30')))->toEqual(Instants::utc('2026-10-25 11:30'))
        ->and($ceiling->hours())->toBe('12');
})->group('RN-05', 'RN-09');

it('acaba la fecha civil en la medianoche local siguiente, tambien con cambio de hora', function (string $fecha, string $finUtc): void {
    expect(WorkDate::fromIsoDate($fecha, Instants::madrid())->endsAt())
        ->toEqual(Instants::utc($finUtc));
})->with([
    'un dia corriente de verano' => ['2026-10-10', '2026-10-10 22:00'],
    'el sabado antes del cambio de octubre' => ['2026-10-24', '2026-10-24 22:00'],
    'el domingo del cambio de octubre (25 h)' => ['2026-10-25', '2026-10-25 23:00'],
    'el domingo del cambio de marzo (23 h)' => ['2026-03-29', '2026-03-29 22:00'],
])->group('RN-05', 'RN-09');

it('no deja construir un tramo abierto que termino en pausa', function (): void {
    expect(fn () => ShiftEntryTimes::of(
        Instants::utc('2026-03-14 07:00'),
        null,
        EntryClosure::BREAK_START,
    ))->toThrow(BreakWithoutClockOut::class)
        ->and(fn () => ShiftEntryTimes::open(Instants::utc('2026-03-14 07:00'))
            ->withClosure(EntryClosure::BREAK_START))
        ->toThrow(BreakWithoutClockOut::class);
})->group('RN-05', 'RF-PA-04');

it('distingue dos tramos con las mismas horas que terminaron distinto', function (): void {
    $salida = ShiftEntryTimes::closed(Instants::utc('2026-03-14 07:00'), Instants::utc('2026-03-14 15:00'));
    $pausa = $salida->withClosure(EntryClosure::BREAK_START);

    expect($salida->equals($pausa))->toBeFalse()
        ->and($pausa->endsInBreak())->toBeTrue()
        ->and($salida->endsInBreak())->toBeFalse()
        // Una salida sin cierre declarado termina en salida.
        ->and(ShiftEntryTimes::of(Instants::utc('2026-03-14 07:00'), Instants::utc('2026-03-14 15:00'))->closure)
        ->toBe(EntryClosure::CLOCK_OUT)
        ->and(ShiftEntryTimes::open(Instants::utc('2026-03-14 07:00'))->closure)->toBeNull();
})->group('RN-05', 'RF-PA-04');

it('acaba la fecha civil aunque el dia siguiente no tenga medianoche', function (): void {
    // Chile adelanta la hora a las 00:00 del 6 de septiembre de 2026: ese dia
    // empieza a la 01:00 (-03), las 04:00 UTC.
    $santiago = new DateTimeZone('America/Santiago');

    expect(WorkDate::fromIsoDate('2026-09-05', $santiago)->endsAt())
        ->toEqual(Instants::utc('2026-09-06 04:00'))
        ->and(WorkDate::fromIsoDate('2026-09-05', $santiago)->next()->isoDate)
        ->toBe('2026-09-06');
})->group('RN-05', 'RN-09');
