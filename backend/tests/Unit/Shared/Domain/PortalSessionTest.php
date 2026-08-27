<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\PortalSession;
use PHPUnit\Framework\ExpectationFailedException;

/*
 * `PortalSession` — la sesion de portal recien abierta (RF-ID-05, RF-ID-07,
 * tarea 1.11).
 *
 * Es un objeto de valor y sus invariantes son baratas de comprobar, pero una de
 * ellas no es cosmetica: **la caducidad va en UTC**. Una sesion con caducidad en
 * hora local caduca a la hora equivocada dos veces al año, y una de esas dos
 * veces es la madrugada del cambio de octubre, que es cuando el turno de noche
 * esta trabajando (regla dura 3).
 */

it('exige que la caducidad venga en UTC', function (): void {
    expect(fn () => sesionCon(expiresAt: new DateTimeImmutable('2026-03-14 10:00', new DateTimeZone('Europe/Madrid'))))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-ID-07', 'RN-04');

it('no admite una sesion sin dueño, sin nombre, sin codigo, sin idioma, sin zona ni sin token', function (array $vacio): void {
    expect(fn () => sesionCon(...$vacio))->toThrow(InvalidArgumentException::class);
})->with([
    'sin UUID' => [['employeeUuid' => '']],
    'sin nombre' => [['displayName' => '']],
    'sin codigo' => [['employeeCode' => '']],
    'sin idioma' => [['locale' => '']],
    'sin zona horaria' => [['timeZone' => '']],
    'sin token' => [['plainTextToken' => '']],
])->group('RF-ID-07');

it('conserva lo que se le da, sin normalizar nada', function (): void {
    // Un objeto de valor que «arreglara» lo que recibe escondería un defecto del
    // adaptador: si la zona horaria llega mal, el sitio donde hay que verlo es
    // el adaptador, no aqui.
    $sesion = sesionCon();

    expect($sesion->employeeUuid)->toBe('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')
        ->and($sesion->displayName)->toBe('Lucia Gomez Ruiz')
        ->and($sesion->employeeCode)->toBe('E7K2M9XQ4')
        ->and($sesion->locale)->toBe('es')
        ->and($sesion->timeZone)->toBe('Europe/Madrid')
        ->and($sesion->plainTextToken)->toBe('41|token-de-prueba');
})->group('RF-ID-07');

/**
 * Una sesion valida, con los campos que la prueba quiera cambiar.
 *
 * @throws ExpectationFailedException
 */
function sesionCon(
    string $employeeUuid = '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
    string $displayName = 'Lucia Gomez Ruiz',
    string $employeeCode = 'E7K2M9XQ4',
    string $locale = 'es',
    string $timeZone = 'Europe/Madrid',
    string $plainTextToken = '41|token-de-prueba',
    ?DateTimeImmutable $expiresAt = null,
): PortalSession {
    return new PortalSession(
        employeeUuid: $employeeUuid,
        displayName: $displayName,
        employeeCode: $employeeCode,
        locale: $locale,
        timeZone: $timeZone,
        plainTextToken: $plainTextToken,
        expiresAt: $expiresAt ?? new DateTimeImmutable('2026-03-14 10:00', new DateTimeZone('UTC')),
    );
}
