<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\PinAttemptReservation;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use App\Modules\Shared\Infrastructure\Adapter\CachePinAttempts;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Sleep;
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

    $contador->reserve('E-0001', $uuid, PinOrigin::KIOSK);
    expect($contador->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse();

    $contador->reserve('E-0001', $uuid, PinOrigin::KIOSK);
    expect($contador->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse();

    $contador->reserve('E-0001', $uuid, PinOrigin::KIOSK);
    expect($contador->isLocked($uuid, PinOrigin::KIOSK))->toBeTrue()
        ->and($contador->secondsUntilUnlock($uuid, PinOrigin::KIOSK))->toBe(300);
})->group('RS-12', 'RF-AT-11');

it('escala a quince y a sesenta minutos con el quinto y el decimo fallo', function (): void {
    // Reservar con el bloqueo abierto no anota, asi que cada fallo que sube de
    // escalon llega cuando el anterior ya se ha levantado: es el unico camino
    // por el que se acumulan en produccion.
    $uuid = Str::uuid7()->toString();
    $instante = new DateTimeImmutable('2026-03-14 06:00:00');

    for ($i = 0; $i < 3; $i++) {
        contadorEn($instante->format('Y-m-d H:i:s'))->reserve('E-0001', $uuid, PinOrigin::KIOSK);
    }

    $aperturas = [];

    for ($fallo = 4; $fallo <= 10; $fallo++) {
        $contador = contadorEn($instante->format('Y-m-d H:i:s'));
        $instante = $instante->modify('+'.$contador->secondsUntilUnlock($uuid, PinOrigin::KIOSK).' seconds');

        $aperturas[$fallo] = contadorEn($instante->format('Y-m-d H:i:s'))->reserve('E-0001', $uuid, PinOrigin::KIOSK)->openedSeconds();
    }

    expect($aperturas)->toBe([4 => 300, 5 => 900, 6 => 900, 7 => 900, 8 => 900, 9 => 900, 10 => 3600]);
})->group('RS-12', 'RF-AT-11');

it('desbloquea cuando pasa el tiempo del escalon', function (): void {
    $uuid = Str::uuid7()->toString();

    $contador = contadorEn('2026-03-14 06:00:00');

    for ($i = 0; $i < 3; $i++) {
        $contador->reserve('E-0001', $uuid, PinOrigin::KIOSK);
    }

    // Un segundo antes: sigue bloqueado. Un segundo despues: ya no.
    expect(contadorEn('2026-03-14 06:04:59')->isLocked($uuid, PinOrigin::KIOSK))->toBeTrue()
        ->and(contadorEn('2026-03-14 06:05:01')->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse();
})->group('RS-12', 'RF-AT-11');

it('cuenta el quiosco y el portal por separado', function (): void {
    // RS-12 y §7.5: «por empleado y por canal». Sin esta separacion, sondear el
    // PIN de alguien contra el portal —accesible desde la red interna del hotel,
    // RF-ID-08— le dejaria sin poder fichar a la manana siguiente: un ataque a
    // una puerta cerrando la otra, que es la regla dura 19 provocada desde
    // fuera.
    $uuid = Str::uuid7()->toString();
    $contador = contadorEn('2026-03-14 06:00:00');

    for ($i = 0; $i < 10; $i++) {
        $contador->reserve('E-0001', $uuid, PinOrigin::PORTAL);
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
        $contador->reserve('E-0001', $unaPersona, PinOrigin::KIOSK);
    }

    expect($contador->isLocked($unaPersona, PinOrigin::KIOSK))->toBeTrue()
        ->and($contador->isLocked($otraPersona, PinOrigin::KIOSK))->toBeFalse();
})->group('RS-12');

it('olvida los fallos tras veinticuatro horas sin ninguno', function (): void {
    // `IDENTITY_PIN_LOCKOUT_RESET_HOURS=24`. Dos fallos por la manana no pueden
    // sumarse al de pasado manana: quien se equivoca una vez al mes no es quien
    // esta probando PIN.
    $uuid = Str::uuid7()->toString();

    contadorEn('2026-03-14 06:00:00')->reserve('E-0001', $uuid, PinOrigin::KIOSK);
    contadorEn('2026-03-14 06:00:10')->reserve('E-0001', $uuid, PinOrigin::KIOSK);

    // Mas de 24 h despues, el tercer fallo es el PRIMERO que cuenta.
    $pasadoManana = contadorEn('2026-03-15 07:00:00');
    $pasadoManana->reserve('E-0001', $uuid, PinOrigin::KIOSK);

    expect($pasadoManana->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse();
})->group('RS-12', 'RF-AT-11');

it('desliza la ventana con cada fallo nuevo', function (): void {
    // La ventana arranca en el ULTIMO fallo, no en el primero. Si arrancara en
    // el primero, quien fallara una vez cada veintitres horas no acumularia
    // nunca y el escalon alto seria inalcanzable para justo el patron que existe
    // para frenar.
    $uuid = Str::uuid7()->toString();

    contadorEn('2026-03-14 06:00:00')->reserve('E-0001', $uuid, PinOrigin::KIOSK);
    contadorEn('2026-03-15 05:00:00')->reserve('E-0001', $uuid, PinOrigin::KIOSK);

    $tercero = contadorEn('2026-03-16 04:00:00');
    $tercero->reserve('E-0001', $uuid, PinOrigin::KIOSK);

    // El primero ya caduco (48 h antes de este), pero el segundo sigue dentro:
    // dos fallos vigentes, todavia sin bloqueo.
    expect($tercero->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse();

    $cuarto = contadorEn('2026-03-16 04:00:30');
    $cuarto->reserve('E-0001', $uuid, PinOrigin::KIOSK);

    expect($cuarto->isLocked($uuid, PinOrigin::KIOSK))->toBeTrue();
})->group('RS-12');

it('limpiar borra las dos puertas de una vez', function (): void {
    // RF-ID-09: `clear()` no toma origen a proposito. Al restablecer el PIN, el
    // anterior deja de existir —la unica copia era el hash— asi que ningun
    // contador levantado contra el describe ya nada.
    $uuid = Str::uuid7()->toString();
    $contador = contadorEn('2026-03-14 06:00:00');

    for ($i = 0; $i < 10; $i++) {
        $contador->reserve('E-0001', $uuid, PinOrigin::KIOSK);
        $contador->reserve('E-0001', $uuid, PinOrigin::PORTAL);
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

    $contador->reserve('E-0001', $uuid, PinOrigin::KIOSK);
    $contador->reserve('E-0001', $uuid, PinOrigin::KIOSK);

    expect($contador->isLocked($uuid, PinOrigin::KIOSK))->toBeTrue()
        ->and($contador->secondsUntilUnlock($uuid, PinOrigin::KIOSK))->toBe(60);
})->group('RS-12');

it('devuelve el bloqueo solo en el intento que lo abre, y no anota los que llegan bloqueados', function (): void {
    // El flanco del asiento `auth.lockout_started`: la tercera reserva abre el
    // escalon 1 y lo dice; la cuarta —la de un intento simultaneo— llega
    // bloqueada, no se anota y no lo abre otra vez.
    $uuid = Str::uuid7()->toString();
    $contador = contadorEn('2026-03-14 06:00:00');

    $reservas = [];

    for ($i = 0; $i < 4; $i++) {
        $reservas[] = $contador->reserve('E-0001', $uuid, PinOrigin::KIOSK);
    }

    expect(array_map(static fn (PinAttemptReservation $r): int => $r->openedSeconds(), $reservas))->toBe([0, 0, 300, 0])
        ->and(array_map(static fn (PinAttemptReservation $r): bool => $r->isLocked(), $reservas))->toBe([false, false, false, true])
        ->and($reservas[3]->lockSeconds())->toBe(300)
        ->and(app(Cache::class)->get('workforce:pin-failures:kiosk:'.$uuid))->toHaveCount(3);

    // Pasado el bloqueo, la siguiente reserva cuenta y vuelve a abrir uno: un
    // flanco nuevo, todavia del escalon 1 con cuatro fallos.
    expect(contadorEn('2026-03-14 06:05:00')->reserve('E-0001', $uuid, PinOrigin::KIOSK)->openedSeconds())->toBe(300);
})->group('RS-12', 'RF-AT-11');

it('escribe la entrada tambien cuando el intento llega bloqueado', function (): void {
    // RS-03: las dos ramas pagan la misma lectura y la misma escritura. Se
    // siembra una marca ya caducada junto a las tres vigentes: si la reserva
    // bloqueada no escribiera, la caducada seguiria en la cache.
    $uuid = Str::uuid7()->toString();
    $ahora = (new DateTimeImmutable('2026-03-14 06:00:00 UTC'))->getTimestamp();
    $clave = 'workforce:pin-failures:portal:'.$uuid;

    app(Cache::class)->put($clave, [$ahora - 90_000, $ahora, $ahora, $ahora], 3600);

    expect(contadorEn('2026-03-14 06:00:00')->reserve('E-0001', $uuid, PinOrigin::PORTAL)->isLocked())->toBeTrue()
        ->and(app(Cache::class)->get($clave))->toBe([$ahora, $ahora, $ahora]);
})->group('RS-03', 'RS-12');

it('reserva contra el señuelo cuando no hay empleado, sin tocar a nadie', function (): void {
    $uuid = Str::uuid7()->toString();
    $contador = contadorEn('2026-03-14 06:00:00');

    for ($i = 0; $i < 4; $i++) {
        $contador->reserve('NOEXISTE', null, PinOrigin::KIOSK);
    }

    expect(app(Cache::class)->get('workforce:pin-failures:kiosk:00000000-0000-0000-0000-000000000000'))->toHaveCount(3)
        ->and($contador->isLocked($uuid, PinOrigin::KIOSK))->toBeFalse();
})->group('RS-03', 'RS-12');

it('cuenta el fallo sin candado si otro proceso no lo suelta', function (): void {
    // Regla dura 19: el candado ordena la cuenta, no la condiciona. Con el
    // candado retenido —un proceso muerto con el cogido— se espera un numero
    // fijo de intentos y se cuenta igual; ni excepcion, ni fallo perdido.
    Sleep::fake();

    $uuid = Str::uuid7()->toString();
    $retenido = app(Cache::class)->getStore();
    \assert($retenido instanceof LockProvider);
    $retenido->lock('workforce:pin-failures-lock:kiosk:'.$uuid, 10)->get();

    $contador = contadorEn('2026-03-14 06:00:00');

    $contador->reserve('E-0001', $uuid, PinOrigin::KIOSK);
    $contador->reserve('E-0001', $uuid, PinOrigin::KIOSK);

    expect($contador->reserve('E-0001', $uuid, PinOrigin::KIOSK)->openedSeconds())->toBe(300);

    // Cien intentos por fallo, tres fallos: la mecanica del candado la fija
    // `CacheMutexTest`; aqui, que el contador la usa con sus numeros.
    Sleep::assertSleptTimes(300);
})->group('RS-12', 'RF-AT-11');
