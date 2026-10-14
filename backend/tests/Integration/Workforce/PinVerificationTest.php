<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\EmployeePinVerifier;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Application\Port\SealedPinOpener;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use App\Modules\Workforce\Infrastructure\Adapter\HashedEmployeePinVerifier;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Time\WallClockBudget;
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
    FrozenTime::at('2026-03-14 06:00:00');
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

it('el acierto no queda contado como fallo aunque su reserva alcanzara el escalon', function (): void {
    // El intento se reserva —se anota— antes de comparar (ADR-050). El tercero,
    // tras dos fallos, alcanza el escalon al reservarse; si es el PIN bueno, el
    // acierto borra la cuenta con su propia marca dentro y no deja a nadie
    // bloqueado ni asiento de bloqueo.
    $empleado = empleadoConPin();
    $verificador = verificador();

    $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);
    $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);

    expect($verificador->verify($empleado['code'], PIN_BUENO, PinOrigin::KIOSK)->isVerified())->toBeTrue()
        ->and(app(PinAttempts::class)->isLocked($empleado['uuid'], PinOrigin::KIOSK))->toBeFalse()
        ->and(app(Cache::class)->get('workforce:pin-failures:kiosk:'.$empleado['uuid']))->toBeNull()
        ->and(DB::table('audit_log')->where('action', 'auth.lockout_started')->count())->toBe(0);
})->group('RS-12', 'RF-AT-11');

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
//
// RS-03 y regla dura 17. Hasta la 2.2.1 esto lo vigilaba una sola medida por
// camino y la asercion `abs(a - b) < max(a, b)`, que es cierta para cualesquiera
// dos duraciones positivas: no podia fallar (V4-QA-1). Ahora son dos pruebas que
// se reparten el trabajo:
//
// 1. **La estructura, determinista.** Un espia del hasher cuenta las
//    comparaciones de cada rechazo. Saltarse el hash en un camino —el `return`
//    temprano «porque no hay empleado»— deja ese camino con cero y la prueba
//    falla, aunque la suite corra con `BCRYPT_ROUNDS=4` y el reloj no pudiera
//    notarlo.
// 2. **El reloj, estadistico.** Medianas de varias muestras intercaladas, con el
//    PIN al mismo coste que el señuelo, y una razon acotada. Bajo cobertura se
//    anuncia y no se afirma ({@see WallClockBudget}).

/**
 * Los hashes contra los que compara el verificador durante una llamada.
 *
 * Espia del hasher: envuelve el real —la comparacion se sigue haciendo— y anota
 * contra que hash se hizo cada una.
 *
 * @param  callable(): mixed  $work
 * @return list<string>
 */
function hashesComparados(callable $work): array
{
    $real = app('hash');
    $comparados = [];

    Hash::partialMock()
        ->shouldReceive('check')
        ->andReturnUsing(static function (string $value, string $hashedValue, array $options = []) use ($real, &$comparados): bool {
            $comparados[] = $hashedValue;

            return $real->check($value, $hashedValue, $options);
        });

    $work();

    Hash::swap($real);

    return $comparados;
}

/**
 * Un empleado con el bloqueo del quiosco ya abierto (tres fallos, `beforeEach`).
 */
function codigoBloqueado(): string
{
    $empleado = empleadoConPin();

    verificador()->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);
    verificador()->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);
    verificador()->verify($empleado['code'], PIN_MALO, PinOrigin::KIOSK);

    return $empleado['code'];
}

dataset('rechazos del PIN', [
    'codigo inexistente' => [fn (): array => ['code' => 'ENOEXISTE', 'pin' => PIN_BUENO]],
    'PIN nunca emitido' => [fn (): array => [
        'code' => EmployeePins::codeOf(WorkforceFixtures::employee(WorkforceFixtures::site('Hotel sin PIN', 'Europe/Madrid'))),
        'pin' => PIN_BUENO,
    ]],
    'de baja, con su credencial retirada (RN-14)' => [fn (): array => ['code' => empleadoConPin(PIN_BUENO, 'terminated')['code'], 'pin' => PIN_BUENO]],
    'suspendido' => [fn (): array => ['code' => empleadoConPin(PIN_BUENO, 'suspended')['code'], 'pin' => PIN_BUENO]],
    'bloqueado, aunque teclee el PIN bueno' => [fn (): array => ['code' => codigoBloqueado(), 'pin' => PIN_BUENO]],
    'PIN incorrecto' => [fn (): array => ['code' => empleadoConPin()['code'], 'pin' => PIN_MALO]],
]);

it('paga exactamente una comparacion de hash bcrypt en cada rechazo', function (Closure $intento): void {
    /** @var array{code: string, pin: string} $tecleado */
    $tecleado = $intento();
    $verificador = verificador();

    $comparados = hashesComparados(fn () => $verificador->verify($tecleado['code'], $tecleado['pin'], PinOrigin::KIOSK));

    // Una, y contra un hash bcrypt de verdad: comparar contra una cadena vacia
    // tambien seria «una llamada», pero el hasher la descarta sin calcular nada.
    expect($comparados)->toHaveCount(1)
        ->and(password_get_info($comparados[0])['algoName'])->toBe('bcrypt');
})->with('rechazos del PIN')->group('RS-03', 'RS-12');

/**
 * Coste bcrypt del hash señuelo del verificador, que es el que paga un codigo
 * inexistente.
 */
function costeDelSenuelo(): int
{
    $senuelo = (new ReflectionClass(HashedEmployeePinVerifier::class))->getConstant('DECOY_HASH');

    expect($senuelo)->toBeString();

    /** @var string $senuelo */
    $coste = password_get_info($senuelo)['options']['cost'] ?? null;

    expect($coste)->toBeInt();

    /** @var int $coste */
    return $coste;
}

/**
 * Mediana de unas muestras de tiempo: una muestra lenta —el planificador del
 * contenedor, un `checkpoint`— no la desplaza, y una media si.
 *
 * @param  non-empty-list<float>  $muestras
 */
function medianaDeTiempos(array $muestras): float
{
    sort($muestras);

    return $muestras[intdiv(\count($muestras), 2)];
}

it('tarda lo mismo, en mediana, con un codigo inexistente que con un PIN incorrecto', function (): void {
    // La suite hashea con `BCRYPT_ROUNDS=4` y el señuelo esta clavado al coste
    // de produccion: sin igualarlos, la prueba compararia dos costes distintos
    // y no el trabajo de los dos caminos.
    $empleado = empleadoConPin();
    DB::table('employees')
        ->where('uuid', $empleado['uuid'])
        ->update(['pin_hash' => password_hash(PIN_BUENO, PASSWORD_BCRYPT, ['cost' => costeDelSenuelo()])]);
    $verificador = verificador();
    $intentos = app(PinAttempts::class);

    // Una pasada en vacio para que el primer `Hash::check` no pague el arranque.
    $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::PORTAL);

    $conEmpleado = [];
    $sinEmpleado = [];

    // Intercaladas, para que una racha lenta de la maquina caiga sobre los dos
    // caminos por igual. El contador se limpia fuera de la medida: siete fallos
    // seguidos abririan el bloqueo y se mediria otro camino.
    for ($ronda = 0; $ronda < 7; $ronda++) {
        $intentos->clear($empleado['uuid']);
        $conEmpleado[] = medir(fn () => $verificador->verify($empleado['code'], PIN_MALO, PinOrigin::PORTAL));
        $sinEmpleado[] = medir(fn () => $verificador->verify('ENOEXISTE', PIN_MALO, PinOrigin::PORTAL));
    }

    $medianas = [medianaDeTiempos($conEmpleado), medianaDeTiempos($sinEmpleado)];

    // Saltarse el hash en un camino lo deja en unos pocos milisegundos frente a
    // los cientos de un bcrypt de coste 12: una razon de decenas. 1,5 deja sitio
    // al ruido de un contenedor compartido sin dejarselo a esa asimetria.
    WallClockBudget::expectRatioBelow(max($medianas) / min($medianas), 1.5, 'RS-03');
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
