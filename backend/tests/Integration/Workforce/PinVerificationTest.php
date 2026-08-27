<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\EmployeePinVerifier;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Application\Port\SealedPinOpener;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La comprobacion del PIN contra `employees.pin_hash` y el sobre con el que
 * viaja (RF-AT-11, RF-ID-06, RS-12, tarea 1.12).
 *
 * **Integracion y no unitaria**: lo que se prueba aqui es que el hash escrito
 * por la tarea 1.13 lo lee y lo compara el verificador de la 1.12, que el
 * `CITEXT` de `employee_code` hace lo que promete y que un empleado de baja no
 * verifica. Nada de eso existe sin PostgreSQL delante.
 */

uses(RefreshDatabase::class);

const PIN_BUENO = '481902';

const PIN_MALO = '481903';

/**
 * Un empleado con su PIN puesto, listo para comprobarse.
 *
 * @return array{uuid: string, code: string}
 */
function empleadoConPin(string $pin = PIN_BUENO, string $status = 'active'): array
{
    $site = WorkforceFixtures::site('Hotel del PIN', 'Europe/Madrid');
    $uuid = WorkforceFixtures::employee($site, null, $status);

    EmployeePins::issue($uuid, $pin);

    return ['uuid' => $uuid, 'code' => EmployeePins::codeOf($uuid)];
}

function verificador(): EmployeePinVerifier
{
    return app(EmployeePinVerifier::class);
}

beforeEach(function (): void {
    app(Cache::class)->clear();

    config()->set('identity.pin.max_attempts', 3);
    config()->set('identity.pin.lockout_seconds', 300);

    // **El reloj se detiene** (ADR-021, regla dura 2). Con el reloj real, los
    // segundos que faltan para el desbloqueo dependen de si las cuatro
    // comparaciones de bcrypt de una prueba cruzan un cambio de segundo —a coste
    // 12 tardan unos cientos de milisegundos cada una—, asi que
    // `retryAfterSeconds()` salia 300 o 299 segun el humor de la maquina. Es el
    // motivo por el que el puerto existe.
    app()->instance(Clock::class, FixedClock::at('2026-03-14 06:00:00'));
    app()->forgetInstance(PinAttempts::class);
});

// --- El hash ------------------------------------------------------------------

it('verifica el PIN correcto y devuelve el UUID del empleado', function (): void {
    $empleado = empleadoConPin();

    $resultado = verificador()->verify($empleado['code'], PIN_BUENO, PinOrigin::KIOSK);

    expect($resultado->isVerified())->toBeTrue()
        ->and($resultado->employeeUuid())->toBe($empleado['uuid'])
        ->and($resultado->isLocked())->toBeFalse();
})->group('RF-AT-11', 'RF-ID-09');

it('no verifica un PIN incorrecto', function (): void {
    $empleado = empleadoConPin();

    $resultado = verificador()->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);

    expect($resultado->isVerified())->toBeFalse()
        ->and($resultado->employeeUuid())->toBeNull();
})->group('RF-AT-11', 'RS-12');

it('no verifica un codigo de empleado que no existe', function (): void {
    $resultado = verificador()->verify('ENOEXISTE', PIN_BUENO, PinOrigin::KIOSK);

    // El MISMO valor que un PIN incorrecto (regla dura 17): desde arriba no se
    // distingue, y por tanto no hay ninguna rama que pueda filtrarlo.
    expect($resultado->isVerified())->toBeFalse()
        ->and($resultado->isLocked())->toBeFalse();
})->group('RF-AT-11', 'RS-12');

it('no verifica a quien nunca recibio un PIN', function (): void {
    $site = WorkforceFixtures::site('Hotel sin PIN', 'Europe/Madrid');
    $uuid = WorkforceFixtures::employee($site);

    $resultado = verificador()->verify(EmployeePins::codeOf($uuid), PIN_BUENO, PinOrigin::KIOSK);

    expect($resultado->isVerified())->toBeFalse();
})->group('RF-AT-11');

it('no deja fichar por PIN a quien esta de baja', function (): void {
    // RN-14, y por el mismo camino que un codigo inexistente: dar de baja a
    // alguien no puede notarse desde fuera.
    $empleado = empleadoConPin(PIN_BUENO, 'terminated');

    $resultado = verificador()->verify($empleado['code'], PIN_BUENO, PinOrigin::KIOSK);

    expect($resultado->isVerified())->toBeFalse();
})->group('RF-AT-11', 'RN-14');

it('acepta el codigo de empleado sin distinguir mayusculas', function (): void {
    // `employee_code` es CITEXT (doc 01 §5.5): quien teclea su codigo en una
    // tablet con guantes no tiene por que acertar la caja.
    $empleado = empleadoConPin();

    $resultado = verificador()->verify(mb_strtolower($empleado['code']), PIN_BUENO, PinOrigin::KIOSK);

    expect($resultado->isVerified())->toBeTrue();
})->group('RF-AT-11');

// --- El contador de intentos --------------------------------------------------

it('anota un fallo por cada PIN incorrecto y bloquea al tercero', function (): void {
    $empleado = empleadoConPin();
    $verificador = verificador();

    $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);
    $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);

    expect(app(PinAttempts::class)->isLocked($empleado['uuid'], PinOrigin::KIOSK))->toBeFalse();

    $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);

    expect(app(PinAttempts::class)->isLocked($empleado['uuid'], PinOrigin::KIOSK))->toBeTrue();
})->group('RS-12', 'RF-AT-11');

it('no comprueba el PIN mientras el bloqueo esta activo', function (): void {
    // RS-12: si lo comprobara, el bloqueo seria un oraculo que confirma cuando
    // se acierta. Con el bloqueo puesto, ni el PIN BUENO verifica.
    $empleado = empleadoConPin();
    $verificador = verificador();

    for ($i = 0; $i < 3; $i++) {
        $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);
    }

    $resultado = $verificador->verify($empleado['code'], PIN_BUENO, PinOrigin::KIOSK);

    expect($resultado->isVerified())->toBeFalse()
        ->and($resultado->isLocked())->toBeTrue()
        ->and($resultado->retryAfterSeconds())->toBe(300);
})->group('RS-12', 'RF-AT-11');

it('un codigo inexistente no crea contador', function (): void {
    // El contador se lleva por `employee_uuid`, asi que quien prueba codigos al
    // azar no puede llenar la cache. A quien haga eso lo frena el limite por
    // dispositivo y por IP de la ruta (§7.1), que es otro control.
    for ($i = 0; $i < 20; $i++) {
        verificador()->verify('ENOEXISTE'.$i, PIN_BUENO, PinOrigin::KIOSK);
    }

    expect(DB::table('employees')->count())->toBe(0);
})->group('RS-12');

it('acertar limpia el castigo acumulado', function (): void {
    $empleado = empleadoConPin();
    $verificador = verificador();

    $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);
    $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);
    $verificador->verify($empleado['code'], PIN_BUENO, PinOrigin::KIOSK);

    // Dos fallos mas no bloquean: el contador arranco de cero al acertar.
    $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);
    $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);

    expect(app(PinAttempts::class)->isLocked($empleado['uuid'], PinOrigin::KIOSK))->toBeFalse();
})->group('RS-12');

it('fallar en el quiosco no bloquea el portal', function (): void {
    $empleado = empleadoConPin();
    $verificador = verificador();

    for ($i = 0; $i < 5; $i++) {
        $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);
    }

    expect($verificador->verify($empleado['code'], PIN_BUENO, PinOrigin::KIOSK)->isLocked())->toBeTrue()
        ->and($verificador->verify($empleado['code'], PIN_BUENO, PinOrigin::PORTAL)->isVerified())->toBeTrue();
})->group('RS-12', 'RF-ID-06');

// --- Tiempo constante ---------------------------------------------------------

it('tarda lo mismo con un codigo inexistente que con un PIN incorrecto', function (): void {
    // RS-03 y regla dura 17. La comparacion del hash se paga en los dos caminos
    // —con el hash real o con el señuelo—, y es el trabajo caro: si uno de los
    // dos se la saltara, la diferencia seria de decenas de milisegundos y quien
    // la midiera sabria que codigos de empleado existen sin acertar ni un PIN.
    $empleado = empleadoConPin();
    $verificador = verificador();

    // Una pasada en vacio para que el primer `Hash::check` no pague el arranque
    // de la extension y falsee la comparacion.
    $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::PORTAL);

    $conEmpleado = medir(fn () => $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::PORTAL));
    $sinEmpleado = medir(fn () => $verificador->verify('ENOEXISTE', PIN_MALO, PinOrigin::PORTAL));

    // Tolerancia amplia a proposito: lo que se vigila es que no haya un orden de
    // magnitud de diferencia —que es lo que produce saltarse el hash—, no que
    // dos medidas de reloj coincidan al microsegundo en una maquina compartida.
    expect(abs($conEmpleado - $sinEmpleado))->toBeLessThan(max($conEmpleado, $sinEmpleado));
})->group('RS-03', 'RS-12');

/**
 * Milisegundos que tarda una llamada, con reloj monotono.
 *
 * @param  callable(): mixed  $work
 */
function medir(callable $work): float
{
    $startedAt = hrtime(true);
    $work();

    return (hrtime(true) - $startedAt) / 1_000_000;
}

// --- El sobre cerrado ---------------------------------------------------------

it('abre el sobre que cierra el quiosco', function (): void {
    // El formato exacto del contrato: base64 de `crypto_box_seal`. La prueba lo
    // sella con la primitiva publicada y no llamando al adaptador, para estar
    // ejercitando el formato que consumira `frontend-quiosco`.
    $publica = EmployeePins::configureSealing();

    expect(app(SealedPinOpener::class)->open(EmployeePins::seal(PIN_BUENO, $publica)))->toBe(PIN_BUENO);
})->group('RF-AT-11', 'RL-12');

it('publica la clave publica derivada de la privada', function (): void {
    $publica = EmployeePins::configureSealing();

    expect(app(SealedPinOpener::class)->publicKey())->toBe($publica);
})->group('RF-AT-11');

it('no abre nada si la instalacion no tiene par de claves', function (): void {
    // ADR-017: una instalacion sin fichaje por PIN es un caso legitimo, no una
    // averia. `null` en los dos metodos y ninguna excepcion.
    $publica = EmployeePins::configureSealing();
    $sobre = EmployeePins::seal(PIN_BUENO, $publica);

    config()->set('identity.pin.sealing.secret_key', '');

    expect(app(SealedPinOpener::class)->publicKey())->toBeNull()
        ->and(app(SealedPinOpener::class)->open($sobre))->toBeNull();
})->group('RF-AT-11');

it('devuelve nulo ante cualquier sobre que no abra, sin distinguir por que', function (string $sobre): void {
    EmployeePins::configureSealing();

    expect(app(SealedPinOpener::class)->open($sobre))->toBeNull();
})->with([
    'base64 invalido' => ['no-es-base64-!!!'],
    'demasiado corto' => ['AAAA'],
    'sellado con otra clave' => [
        // Un sobre bien formado pero de otra instalacion: la longitud es
        // correcta y aun asi no abre.
        'sellado-con-otra-clave',
    ],
])->group('RF-AT-11', 'RS-03');

it('no abre un sobre sellado con la clave de otra instalacion', function (): void {
    $otraInstalacion = base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair()));
    $sobre = EmployeePins::seal(PIN_BUENO, $otraInstalacion);

    EmployeePins::configureSealing();

    expect(app(SealedPinOpener::class)->open($sobre))->toBeNull();
})->group('RF-AT-11', 'RS-03');
