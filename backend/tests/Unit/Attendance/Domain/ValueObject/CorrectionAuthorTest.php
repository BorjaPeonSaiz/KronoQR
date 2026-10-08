<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InvalidCorrection;
use App\Modules\Attendance\Domain\ValueObject\Correction;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReasonCode;
use Tests\Support\Time\Instants;

/*
 * Toda correccion tiene autor (RN-13, RL-04): el historico de versiones dice
 * quien cambio el registro, y una correccion sin autor es un cambio anonimo del
 * registro legal.
 *
 * El autor es el `id` de una cuenta, y el primero que asigna la base de datos es
 * el 1. El limite va escrito como numero, no calculado (§3.5): es justo donde la
 * comparacion se equivoca, y la mutacion lo encontro sin prueba.
 */

it('rechaza una correccion sin autor', function (int $autor): void {
    expect(fn (): Correction => Correction::by(
        $autor,
        Instants::utc('2026-03-16 09:00'),
        CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_SALIDA),
    ))->toThrow(InvalidCorrection::class);
})->with([
    'autor cero' => [0],
    'autor negativo' => [-1],
])->group('RN-13', 'RQ-01');

it('acepta como autor la primera cuenta de la instalacion', function (): void {
    $correccion = Correction::by(
        1,
        Instants::utc('2026-03-16 09:00'),
        CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_SALIDA),
    );

    expect($correccion->performedByUserId)->toBe(1);
})->group('RN-13', 'RQ-01');
