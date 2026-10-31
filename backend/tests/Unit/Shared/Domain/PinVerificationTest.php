<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\OffboardedPinHolder;
use App\Modules\Shared\Domain\ValueObject\PinVerification;

/*
 * Los tres desenlaces de comprobar un PIN (RS-12, RS-03): verificado,
 * rechazado y bloqueado. Lo que fijan estas pruebas es que nunca se mezclan:
 * un PIN bueno o malo no pasa por bloqueo, y un bloqueo no entrega empleado.
 * El claim de RN-19 lo prueba PinClaimTest.
 */

const PIN_VERIFICATION_TEST_UUID = '0199f0c2-0000-7000-8000-0000000000aa';

it('entrega el empleado dueño del PIN verificado', function (): void {
    $verification = PinVerification::verified(PIN_VERIFICATION_TEST_UUID);

    expect($verification->isVerified())->toBeTrue()
        ->and($verification->employeeUuid())->toBe(PIN_VERIFICATION_TEST_UUID);
})->group('RF-AT-11', 'RF-ID-06');

it('no admite un PIN verificado sin empleado', function (): void {
    expect(fn (): PinVerification => PinVerification::verified(''))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-AT-11', 'RF-ID-06');

// Los casos se construyen dentro de la prueba (closures), no al declarar el
// dataset: si no, la cobertura no ve las fabricas y la mutacion los da por sin probar.
it('no presenta como bloqueo ni un PIN verificado ni uno rechazado', function (PinVerification $verification): void {
    expect($verification->isLocked())->toBeFalse()
        ->and($verification->retryAfterSeconds())->toBe(0);
})->with([
    'verificado' => fn (): PinVerification => PinVerification::verified(PIN_VERIFICATION_TEST_UUID),
    'rechazado' => fn (): PinVerification => PinVerification::rejected(),
    'rechazado de una persona de baja' => fn (): PinVerification => PinVerification::rejectedOffboarded(
        OffboardedPinHolder::of(PIN_VERIFICATION_TEST_UUID, '2026-03-01', '2026-08-15', new DateTimeZone('Europe/Madrid')),
    ),
])->group('RS-12', 'RS-03', 'RN-20');

it('no entrega empleado mientras dura el bloqueo', function (): void {
    $verification = PinVerification::locked(300);

    expect($verification->isLocked())->toBeTrue()
        ->and($verification->isVerified())->toBeFalse()
        ->and($verification->employeeUuid())->toBeNull();
})->group('RS-12', 'RS-03');

it('conserva la espera del bloqueo y nunca la deja negativa', function (int $requested, int $expected): void {
    expect(PinVerification::locked($requested)->retryAfterSeconds())->toBe($expected);
})->with([
    'cinco minutos' => [300, 300],
    'un segundo' => [1, 1],
    'cero' => [0, 0],
    'un segundo negativo' => [-1, 0],
    'negativa de cinco minutos' => [-300, 0],
])->group('RS-12');
