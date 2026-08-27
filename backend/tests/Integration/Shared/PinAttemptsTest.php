<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use App\Modules\Shared\Infrastructure\Adapter\CachePinAttempts;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;
use Tests\Support\Time\FixedClock;

/*
 * El contador de intentos fallidos del PIN sobre la cache real (RS-12, doc 02
 * §7.5, tarea 1.12).
 *
 * La unitaria de al lado prueba la **tabla de decision**; esta prueba el
 * **contador**: que la clave separa las dos puertas, que la ventana de olvido
 * desliza y que el desbloqueo llega cuando tiene que llegar.
 *
 * **El tiempo se mueve construyendo el adaptador con otro reloj**, no esperando.
 * `CachePinAttempts` recibe el puerto `Clock`, asi que dos instancias con dos
 * `FixedClock` distintos sobre la MISMA cache son literalmente el mismo contador
 * visto en dos momentos. Es lo que permite comprobar «24 h sin fallos» sin
 * dormir 24 h, y lo que hace que la ventana deslizante sea comprobable en vez de
 * ser una promesa del docblock.
 */

/**
 * El contador visto desde un instante concreto, sobre la cache compartida.
 */
function contadorEn(string $instante): PinAttempts
{
    return new CachePinAttempts(app(Cache::class), FixedClock::at($instante));
}

beforeEach(function (): void {
    app(Cache::class)->clear();

    // Los umbrales de serie, escritos para que la prueba no dependa de lo que
    // tenga el `.env` de quien la ejecuta.
    config()->set('identity.pin.max_attempts', 3);
    config()->set('identity.pin.lockout_seconds', 300);
    config()->set('identity.pin.lockout_tier2_attempts', 5);
    config()->set('identity.pin.lockout_tier2_seconds', 900);
    config()->set('identity.pin.lockout_tier3_attempts', 10);
    config()->set('identity.pin.lockout_tier3_seconds', 3600);
    config()->set('identity.pin.lockout_reset_hours', 24);
});

it('no bloquea hasta el tercer fallo', function (): void {
    // Valores limite del §3.5 alrededor de `IDENTITY_PIN_MAX_ATTEMPTS=3`: el
    // segundo intento no bloquea y el tercero si.
    $uuid = Str::uuid7()->toString();
    $contador = contadorEn('2026-03-14 06:00:00');

    $contador->recordFailure($uuid, PinOrigin::KIOSK);
    expect($contador->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse();

    $contador->recordFailure($uuid, PinOrigin::KIOSK);
    expect($contador->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse();

    $contador->recordFailure($uuid, PinOrigin::KIOSK);
    expect($contador->isLocked($uuid, PinOrigin::KIOSK))->toBeTrue()
        ->and($contador->secondsUntilUnlock($uuid, PinOrigin::KIOSK))->toBe(300);
})->group('RS-12', 'RF-AT-11');

it('escala a quince y a sesenta minutos con el quinto y el decimo fallo', function (): void {
    $uuid = Str::uuid7()->toString();
    $contador = contadorEn('2026-03-14 06:00:00');

    for ($i = 0; $i < 5; $i++) {
        $contador->recordFailure($uuid, PinOrigin::KIOSK);
    }

    expect($contador->secondsUntilUnlock($uuid, PinOrigin::KIOSK))->toBe(900);

    for ($i = 5; $i < 10; $i++) {
        $contador->recordFailure($uuid, PinOrigin::KIOSK);
    }

    expect($contador->secondsUntilUnlock($uuid, PinOrigin::KIOSK))->toBe(3600);
})->group('RS-12', 'RF-AT-11');

it('desbloquea cuando pasa el tiempo del escalon', function (): void {
    $uuid = Str::uuid7()->toString();

    $contador = contadorEn('2026-03-14 06:00:00');

    for ($i = 0; $i < 3; $i++) {
        $contador->recordFailure($uuid, PinOrigin::KIOSK);
    }

    // Un segundo antes: sigue bloqueado. Un segundo despues: ya no.
    expect(contadorEn('2026-03-14 06:04:59')->isLocked($uuid, PinOrigin::KIOSK))->toBeTrue()
        ->and(contadorEn('2026-03-14 06:05:01')->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse();
})->group('RS-12', 'RF-AT-11');

it('cuenta el quiosco y el portal por separado', function (): void {
    // RS-12 y §7.5: «por empleado y por origen». Sin esta separacion, sondear el
    // PIN de alguien contra el portal —accesible desde la red interna del hotel,
    // RF-ID-08— le dejaria sin poder fichar a la manana siguiente: un ataque a
    // una puerta cerrando la otra, que es la regla dura 19 provocada desde
    // fuera.
    $uuid = Str::uuid7()->toString();
    $contador = contadorEn('2026-03-14 06:00:00');

    for ($i = 0; $i < 10; $i++) {
        $contador->recordFailure($uuid, PinOrigin::PORTAL);
    }

    expect($contador->isLocked($uuid, PinOrigin::PORTAL))->toBeTrue()
        ->and($contador->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse()
        ->and($contador->secondsUntilUnlock($uuid, PinOrigin::KIOSK))->toBe(0);
})->group('RS-12', 'RF-AT-11');

it('cuenta cada empleado por separado', function (): void {
    $unaPersona = Str::uuid7()->toString();
    $otraPersona = Str::uuid7()->toString();
    $contador = contadorEn('2026-03-14 06:00:00');

    for ($i = 0; $i < 3; $i++) {
        $contador->recordFailure($unaPersona, PinOrigin::KIOSK);
    }

    expect($contador->isLocked($unaPersona, PinOrigin::KIOSK))->toBeTrue()
        ->and($contador->isLocked($otraPersona, PinOrigin::KIOSK))->toBeFalse();
})->group('RS-12');

it('olvida los fallos tras veinticuatro horas sin ninguno', function (): void {
    // `IDENTITY_PIN_LOCKOUT_RESET_HOURS=24`. Dos fallos por la manana no pueden
    // sumarse al de pasado manana: quien se equivoca una vez al mes no es quien
    // esta probando PIN.
    $uuid = Str::uuid7()->toString();

    contadorEn('2026-03-14 06:00:00')->recordFailure($uuid, PinOrigin::KIOSK);
    contadorEn('2026-03-14 06:00:10')->recordFailure($uuid, PinOrigin::KIOSK);

    // Mas de 24 h despues, el tercer fallo es el PRIMERO que cuenta.
    $pasadoManana = contadorEn('2026-03-15 07:00:00');
    $pasadoManana->recordFailure($uuid, PinOrigin::KIOSK);

    expect($pasadoManana->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse();
})->group('RS-12', 'RF-AT-11');

it('desliza la ventana con cada fallo nuevo', function (): void {
    // La ventana arranca en el ULTIMO fallo, no en el primero. Si arrancara en
    // el primero, quien fallara una vez cada veintitres horas no acumularia
    // nunca y el escalon alto seria inalcanzable para justo el patron que existe
    // para frenar.
    $uuid = Str::uuid7()->toString();

    contadorEn('2026-03-14 06:00:00')->recordFailure($uuid, PinOrigin::KIOSK);
    contadorEn('2026-03-15 05:00:00')->recordFailure($uuid, PinOrigin::KIOSK);

    $tercero = contadorEn('2026-03-16 04:00:00');
    $tercero->recordFailure($uuid, PinOrigin::KIOSK);

    // El primero ya caduco (48 h antes de este), pero el segundo sigue dentro:
    // dos fallos vigentes, todavia sin bloqueo.
    expect($tercero->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse();

    $cuarto = contadorEn('2026-03-16 04:00:30');
    $cuarto->recordFailure($uuid, PinOrigin::KIOSK);

    expect($cuarto->isLocked($uuid, PinOrigin::KIOSK))->toBeTrue();
})->group('RS-12');

it('limpiar borra las dos puertas de una vez', function (): void {
    // RF-ID-09: `clear()` no toma origen a proposito. Al restablecer el PIN, el
    // anterior deja de existir —la unica copia era el hash— asi que ningun
    // contador levantado contra el describe ya nada.
    $uuid = Str::uuid7()->toString();
    $contador = contadorEn('2026-03-14 06:00:00');

    for ($i = 0; $i < 10; $i++) {
        $contador->recordFailure($uuid, PinOrigin::KIOSK);
        $contador->recordFailure($uuid, PinOrigin::PORTAL);
    }

    $contador->clear($uuid);

    expect($contador->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse()
        ->and($contador->isLocked($uuid, PinOrigin::PORTAL))->toBeFalse();
})->group('RS-12', 'RF-ID-09');

it('lee los umbrales de la configuracion y no de constantes', function (): void {
    // Regla dura 13: un cliente con una politica mas dura baja el primer escalon
    // sin tocar el repositorio. Si estos numeros estuvieran clavados en el
    // codigo, esta prueba pasaria igual y la promesa de ADR-017 seria falsa.
    config()->set('identity.pin.max_attempts', 2);
    config()->set('identity.pin.lockout_seconds', 60);

    $uuid = Str::uuid7()->toString();
    $contador = contadorEn('2026-03-14 06:00:00');

    $contador->recordFailure($uuid, PinOrigin::KIOSK);
    $contador->recordFailure($uuid, PinOrigin::KIOSK);

    expect($contador->isLocked($uuid, PinOrigin::KIOSK))->toBeTrue()
        ->and($contador->secondsUntilUnlock($uuid, PinOrigin::KIOSK))->toBe(60);
})->group('RS-12');
