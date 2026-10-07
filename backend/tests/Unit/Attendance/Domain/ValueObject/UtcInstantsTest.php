<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\ValueObject\Correction;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReasonCode;
use App\Modules\Attendance\Domain\ValueObject\ShiftTimes;
use Tests\Support\Time\Instants;

/*
 * Los valores del registro que aceptan un instante suelto lo exigen en UTC
 * (regla dura 3, RN-04).
 *
 * `ShiftTimes` y `Correction` comprueban el huso en su constructor, pero ninguna
 * prueba los construia con un instante en hora local: se podia borrar la
 * comprobacion sin que fallara nada (M4 de la verificacion de la 2.1.0). Un
 * instante con desfase que llegara hasta aqui acabaria en la base de datos con
 * la hora de pared por hora UTC, que es una hora de mas o de menos en el
 * registro legal de alguien segun la epoca del año.
 *
 * Se prueba con un turno ABIERTO a proposito: el cerrado construye ademas un
 * `TimeRange`, que tambien comprueba el huso, y esa segunda comprobacion
 * taparia la ausencia de la primera.
 */

it('no abre un turno con la entrada en hora local', function (): void {
    $entradaEnMadrid = new DateTimeImmutable('2026-03-14 07:00', Instants::madrid());

    expect(fn (): ShiftTimes => ShiftTimes::open($entradaEnMadrid))
        ->toThrow(InstantIsNotUtc::class);
})->group('RN-04', 'RQ-01');

it('no cambia la entrada de un turno abierto por una en hora local', function (): void {
    $abierto = ShiftTimes::open(Instants::utc('2026-03-14 06:00'));
    $entradaEnMadrid = new DateTimeImmutable('2026-03-14 06:30', Instants::madrid());

    expect(fn (): ShiftTimes => $abierto->withClockIn($entradaEnMadrid))
        ->toThrow(InstantIsNotUtc::class);
})->group('RN-04', 'RQ-01');

it('acepta la entrada de un turno abierto en UTC', function (): void {
    // El control positivo: sin el, las dos de arriba pasarian con un
    // constructor que rechazara cualquier instante.
    $abierto = ShiftTimes::open(Instants::utc('2026-03-14 06:00'));

    expect($abierto->clockedInAt->format(DATE_ATOM))->toBe('2026-03-14T06:00:00+00:00')
        ->and($abierto->clockedOutAt)->toBeNull();
})->group('RN-04', 'RQ-01');

it('no registra una correccion con el momento en hora local', function (): void {
    // `performedAt` es el «cuando» que la Inspeccion lee en el historico de
    // versiones (RN-13, RL-04).
    $momentoEnMadrid = new DateTimeImmutable('2026-03-16 10:00', Instants::madrid());

    expect(fn (): Correction => Correction::by(7, $momentoEnMadrid, CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_SALIDA)))
        ->toThrow(InstantIsNotUtc::class);
})->group('RN-04', 'RN-13', 'RQ-01');

it('registra una correccion con el momento en UTC', function (): void {
    $correccion = Correction::by(7, Instants::utc('2026-03-16 09:00'), CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_SALIDA));

    expect($correccion->performedAt->format(DATE_ATOM))->toBe('2026-03-16T09:00:00+00:00');
})->group('RN-04', 'RN-13', 'RQ-01');
