<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Exception\InvalidSupportGrant;
use App\Modules\Product\Domain\Exception\SupportGrantAlreadyClosed;
use App\Modules\Product\Domain\Model\SupportGrant;
use App\Modules\Product\Domain\ValueObject\SupportGrantAuthor;
use App\Modules\Product\Domain\ValueObject\SupportGrantStatus;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use Tests\Support\Time\FixedClock;

/*
 * La caducidad de una concesion de soporte, con el reloj inyectado (RF-PD-11,
 * ADR-020, regla dura 2).
 *
 * LO QUE SE PRUEBA AQUI ES EL LIMITE EXACTO, que es lo unico que no se puede
 * comprobar de otra forma: en `expires_at` justo, ¿vale o no vale? Sin `Clock`
 * habria que mover el reloj de la maquina, y esa prueba no se escribe.
 *
 * El limite es `>` y no `>=` —en el instante exacto ya no vale— y tiene que
 * coincidir con lo que hace Sanctum con la caducidad del token: si las dos
 * lecturas discreparan, habria un segundo en el que el panel diria «activa» y el
 * token responderia 401, que es como se convence a alguien de que el producto
 * esta roto.
 */

const GRANTED_AT = '2026-06-15 09:00:00';

function supportAuthor(): SupportGrantAuthor
{
    return new SupportGrantAuthor(id: 7, uuid: '0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f60', name: 'Cuenta de prueba');
}

function grantedSupportAccess(int $hours = 24, SupportScope $scope = SupportScope::Diagnostics): SupportGrant
{
    return SupportGrant::grant(
        uuid: '0199f6a2-0000-7d3b-8a90-1b2c3d4e5f60',
        grantedBy: supportAuthor(),
        reason: 'Incidencia #123',
        scope: $scope,
        hours: $hours,
        maximumHours: 72,
        grantedAt: FixedClock::at(GRANTED_AT)->now(),
    );
}

it('sigue activa un segundo antes de caducar', function (): void {
    $grant = grantedSupportAccess(hours: 24);

    expect($grant->isActiveAt(FixedClock::at('2026-06-16 08:59:59')->now()))->toBeTrue()
        ->and($grant->statusAt(FixedClock::at('2026-06-16 08:59:59')->now()))
        ->toBe(SupportGrantStatus::Active);
})->group('RF-PD-11');

it('ya NO vale en el instante exacto de expires_at', function (): void {
    // El limite. `>` y no `>=`: a las 09:00:00 del dia siguiente la concesion de
    // 24 horas se acabo. Es la misma lectura que hace Sanctum con la caducidad
    // del token, y por eso las dos tienen que decir lo mismo.
    $grant = grantedSupportAccess(hours: 24);
    $exactly = FixedClock::at('2026-06-16 09:00:00')->now();

    expect($grant->expiresAt->format('Y-m-d H:i:s'))->toBe('2026-06-16 09:00:00')
        ->and($grant->isActiveAt($exactly))->toBeFalse()
        ->and($grant->statusAt($exactly))->toBe(SupportGrantStatus::Expired);
})->group('RF-PD-11');

it('tampoco vale un segundo despues', function (): void {
    $grant = grantedSupportAccess(hours: 24);

    expect($grant->isActiveAt(FixedClock::at('2026-06-16 09:00:01')->now()))->toBeFalse();
})->group('RF-PD-11');

it('caduca sola sin que nadie ejecute nada', function (): void {
    // La promesa literal de RF-PD-11: «al expirar, el acceso deja de funcionar
    // sin que nadie haga nada». Se comprueba que el estado cambia SOLO con el
    // paso del tiempo, sobre el mismo objeto y sin llamar a nada por el camino:
    // no hay ningun `expire()` que alguien tenga que acordarse de invocar, ni
    // ninguna tarea programada detras.
    $grant = grantedSupportAccess(hours: 2);

    expect($grant->statusAt(FixedClock::at('2026-06-15 10:59:59')->now()))
        ->toBe(SupportGrantStatus::Active)
        ->and($grant->statusAt(FixedClock::at('2026-06-15 11:00:00')->now()))
        ->toBe(SupportGrantStatus::Expired);
})->group('RF-PD-11');

it('revocada consta revocada aunque despues caduque', function (): void {
    // `Revoked` gana a `Expired` para siempre. Lo que describe el estado es el
    // HECHO que retiro el acceso, y ese fue una persona pulsando un boton;
    // borrarlo al pasar la fecha dejaria sin rastro la unica accion deliberada.
    $grant = grantedSupportAccess(hours: 24);
    $grant->revoke(FixedClock::at('2026-06-15 09:10:00')->now(), byUserId: 7);

    expect($grant->statusAt(FixedClock::at('2026-06-15 09:11:00')->now()))
        ->toBe(SupportGrantStatus::Revoked)
        ->and($grant->statusAt(FixedClock::at('2026-07-01 00:00:00')->now()))
        ->toBe(SupportGrantStatus::Revoked);
})->group('RF-PD-11');

it('no se puede revocar dos veces', function (): void {
    // Quien llama lo traduce a un 204 idempotente y a NO auditar: la segunda
    // pulsacion de un boton no es un hecho nuevo.
    $grant = grantedSupportAccess();
    $grant->revoke(FixedClock::at('2026-06-15 09:10:00')->now(), byUserId: 7);

    expect(fn () => $grant->revoke(FixedClock::at('2026-06-15 09:11:00')->now(), byUserId: 7))
        ->toThrow(SupportGrantAlreadyClosed::class);
})->group('RF-PD-11');

it('una concesion caducada SI se puede revocar', function (): void {
    // Caducar no es cerrar: revocar una caducada deja constancia de que alguien
    // la retiro a proposito ademas de que expiro.
    $grant = grantedSupportAccess(hours: 1);
    $grant->revoke(FixedClock::at('2026-06-20 00:00:00')->now(), byUserId: 7);

    expect($grant->revokedAt())->not->toBeNull();
})->group('RF-PD-11');

it('exige un motivo de 3 a 200 caracteres', function (string $reason): void {
    expect(fn () => SupportGrant::grant(
        uuid: 'u', grantedBy: supportAuthor(), reason: $reason,
        scope: SupportScope::Diagnostics, hours: 1, maximumHours: 72,
        grantedAt: FixedClock::at(GRANTED_AT)->now(),
    ))->toThrow(InvalidSupportGrant::class);
})->with([
    'vacio' => [''],
    'solo espacios' => ['   '],
    'demasiado corto' => ['ab'],
    'demasiado largo' => [str_repeat('x', 201)],
])->group('RF-PD-11');

it('rechaza duraciones fuera del rango, con el tope ya resuelto', function (int $hours, int $maximum): void {
    // El tope entra resuelto desde la configuracion (regla dura 14): el dominio
    // no consulta `config()`, y por eso esta prueba puede fijar uno de 8 horas.
    expect(fn () => SupportGrant::grant(
        uuid: 'u', grantedBy: supportAuthor(), reason: 'Incidencia #123',
        scope: SupportScope::Diagnostics, hours: $hours, maximumHours: $maximum,
        grantedAt: FixedClock::at(GRANTED_AT)->now(),
    ))->toThrow(InvalidSupportGrant::class);
})->with([
    'cero' => [0, 72],
    'negativa' => [-4, 72],
    'por encima del tope de serie' => [73, 72],
    'por encima de un tope mas estricto del cliente' => [24, 8],
])->group('RF-PD-11');

/*
 * EL CATALOGO DE ALCANCES TIENE SU PROPIO FICHERO.
 *
 * `SupportScopeTest` comprueba que cada cadena de `abilities()` existe en
 * `TokenAbility`, que ninguna es una de las potestades del cliente y que los
 * tres alcances actuan como `admin`. Aqui se prueba la ENTIDAD —su caducidad,
 * su revocacion y sus invariantes—, que es una pregunta distinta.
 */
