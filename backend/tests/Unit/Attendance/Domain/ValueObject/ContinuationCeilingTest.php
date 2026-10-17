<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Policy\ScanIntentPolicy;
use App\Modules\Attendance\Domain\ValueObject\AcceptedScan;
use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
use App\Modules\Attendance\Domain\ValueObject\DeclaredIntent;
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
