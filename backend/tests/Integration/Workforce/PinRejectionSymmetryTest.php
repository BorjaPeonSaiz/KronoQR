<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\EmployeePinVerifier;
use App\Modules\Shared\Application\Port\PinAttempts;
use App\Modules\Shared\Domain\ValueObject\PinOrigin;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Shared\AuthenticationTrail;
use Tests\Support\Shared\RecordingPinAttempts;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * RS-03 y regla dura 17 en el camino del PIN: **un codigo que no existe cuesta lo
 * mismo que un PIN equivocado** (ADR-039).
 *
 * ## Que estructura se mide y por que no basta con el reloj
 *
 * `ConstantTimeRejectionTest` hace esto mismo para la tarjeta QR contando
 * consultas. Aqui el trabajo caro es `bcrypt` —ya igualado con el hash señuelo—
 * pero el resto no lo estaba: solo la rama «el empleado existe» leia el contador
 * de intentos, anotaba el fallo y volvia a leerlo. Tres viajes a la cache que un
 * codigo inexistente no pagaba, mas —en el flanco— una transaccion contra
 * `audit_log` con el candado global de ADR-010 dentro.
 *
 * Contarlo con un cronometro seria intermitente: son microsegundos detras de
 * cientos de milisegundos de `bcrypt`. **Contar la secuencia de operaciones no lo
 * es**: si alguien vuelve a poner un `return` temprano «porque no hay UUID contra
 * el que anotar», estas pruebas fallan aunque el reloj de la CI no de para
 * distinguirlo.
 *
 * Se comparan **cinco intentos seguidos** y no uno: la asimetria que mas
 * importaba aparecia en el tercero —el que abre el bloqueo— y en el cuarto —el
 * que llega con el bloqueo puesto—, que eran justo los dos que un atacante puede
 * provocar a voluntad para saber si un codigo existe.
 */

uses(RefreshDatabase::class);

const PIN_DE_LA_SIMETRIA = '481902';

const PIN_MALO_DE_LA_SIMETRIA = '481903';

const CODIGO_QUE_NO_EXISTE = 'ZZZNOEXISTE';

beforeEach(function (): void {
    app(Cache::class)->clear();

    config()->set('identity.pin.max_attempts', 3);
    config()->set('identity.pin.lockout_seconds', 300);
    config()->set('identity.pin.lockout_tier2_attempts', 5);
    config()->set('identity.pin.lockout_tier2_seconds', 900);
    config()->set('identity.pin.lockout_tier3_attempts', 10);
    config()->set('identity.pin.lockout_tier3_seconds', 3600);
    config()->set('identity.pin.lockout_reset_hours', 24);

    // El reloj detenido (ADR-021, regla dura 2): con el reloj real, cinco
    // comparaciones de bcrypt cruzan cambios de segundo y los escalones dejan de
    // ser deterministas.
    app()->instance(Clock::class, FixedClock::at('2026-03-14 06:00:00'));
    app()->forgetInstance(PinAttempts::class);
});

/**
 * Un empleado con su PIN puesto.
 *
 * @return array{uuid: string, code: string}
 */
function empleadoDeLaSimetria(): array
{
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel de la simetria', 'Europe/Madrid'));

    EmployeePins::issue($uuid, PIN_DE_LA_SIMETRIA);

    return ['uuid' => $uuid, 'code' => EmployeePins::codeOf($uuid)];
}

/**
 * Envuelve el contador real con el espia y lo deja puesto en el contenedor.
 */
function espiaDelContador(): RecordingPinAttempts
{
    /** @var PinAttempts $real */
    $real = app(PinAttempts::class);

    $espia = new RecordingPinAttempts($real);

    app()->instance(PinAttempts::class, $espia);

    return $espia;
}

/**
 * Cinco rechazos seguidos contra el mismo codigo, y la secuencia de llamadas al
 * contador que produce cada uno.
 *
 * @return list<list<string>>
 */
function secuenciaDeCincoRechazos(string $employeeCode, RecordingPinAttempts $espia): array
{
    $verificador = app(EmployeePinVerifier::class);
    $secuencias = [];

    for ($intento = 0; $intento < 5; $intento++) {
        $resultado = $verificador->verify($employeeCode, PIN_MALO_DE_LA_SIMETRIA, PinOrigin::PORTAL);

        expect($resultado->isVerified())->toBeFalse()
            ->and($resultado->employeeUuid())->toBeNull();

        $secuencias[] = $espia->drain();
    }

    return $secuencias;
}

it('ejecuta la misma secuencia de operaciones del contador exista o no el codigo', function (): void {
    $empleado = empleadoDeLaSimetria();
    $espia = espiaDelContador();

    $conEmpleado = secuenciaDeCincoRechazos($empleado['code'], $espia);
    $sinEmpleado = secuenciaDeCincoRechazos(CODIGO_QUE_NO_EXISTE, $espia);

    expect($conEmpleado)->toBe($sinEmpleado)
        // Y no esta vacia: una implementacion que no tocara el contador en
        // ninguna de las dos ramas tambien pasaria la comparacion de arriba.
        ->and($conEmpleado[0])->toBe([
            'secondsUntilUnlock:portal',
            'recordFailure:portal',
            'secondsUntilUnlock:portal',
        ]);
})->group('RS-03', 'RS-12', 'RF-ID-06');

it('ejecuta el mismo numero de consultas exista o no el codigo', function (): void {
    // La otra mitad estructural, la misma que fija `ConstantTimeRejectionTest`
    // para la tarjeta: buscar un codigo que no esta cuesta la misma consulta que
    // encontrarlo.
    $empleado = empleadoDeLaSimetria();
    espiaDelContador();

    $verificador = app(EmployeePinVerifier::class);
    $consultas = [];

    foreach (['existe' => $empleado['code'], 'no existe' => CODIGO_QUE_NO_EXISTE] as $caso => $codigo) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $verificador->verify($codigo, PIN_MALO_DE_LA_SIMETRIA, PinOrigin::PORTAL);

        $consultas[$caso] = \count(DB::getRawQueryLog());
        DB::disableQueryLog();
    }

    expect(array_unique(array_values($consultas)))
        ->toHaveCount(1, 'Los rechazos no cuestan lo mismo: '.json_encode($consultas));
})->group('RS-03', 'RF-ID-06');

it('escribe el mismo apunte, byte a byte, exista o no el codigo', function (): void {
    // Si el log distinguiera los dos casos, el oraculo que la respuesta no da lo
    // daria el servidor por dentro — y ese log viaja al fabricante en el paquete
    // de diagnostico (ADR-020).
    $empleado = empleadoDeLaSimetria();
    espiaDelContador();

    /** @var list<array{message: string, context: array<string, mixed>}> $apuntes */
    $apuntes = [];
    AuthenticationTrail::captureLog($apuntes);

    $verificador = app(EmployeePinVerifier::class);
    $verificador->verify($empleado['code'], PIN_MALO_DE_LA_SIMETRIA, PinOrigin::PORTAL);
    $verificador->verify(CODIGO_QUE_NO_EXISTE, PIN_MALO_DE_LA_SIMETRIA, PinOrigin::PORTAL);

    expect($apuntes)->toHaveCount(2)
        ->and($apuntes[0]['message'])->toBe('auth.login_failed')
        ->and($apuntes[1]['message'])->toBe($apuntes[0]['message'])
        ->and(json_encode($apuntes[1]['context'], JSON_THROW_ON_ERROR))
        ->toBe(json_encode($apuntes[0]['context'], JSON_THROW_ON_ERROR));
})->group('RS-03', 'RS-12');

it('no separa en el log el bloqueo del PIN equivocado', function (): void {
    // El motivo es el mismo en las dos ramas a proposito: `locked` solo se emite
    // donde la respuesta ya distingue el bloqueo —el panel, con su `429`—, y aqui
    // los cinco rechazos son la misma respuesta. El bloqueo se ve donde tiene que
    // verse, en el asiento `auth.lockout_started`.
    $empleado = empleadoDeLaSimetria();
    espiaDelContador();

    /** @var list<array{message: string, context: array<string, mixed>}> $apuntes */
    $apuntes = [];
    AuthenticationTrail::captureLog($apuntes);

    $verificador = app(EmployeePinVerifier::class);

    for ($intento = 0; $intento < 4; $intento++) {
        $verificador->verify($empleado['code'], PIN_MALO_DE_LA_SIMETRIA, PinOrigin::PORTAL);
    }

    $motivos = array_map(
        static fn (array $apunte): mixed => $apunte['context']['reason'] ?? null,
        array_values(array_filter(
            $apuntes,
            static fn (array $apunte): bool => $apunte['message'] === 'auth.login_failed',
        )),
    );

    expect($motivos)->toBe(array_fill(0, 4, 'invalid_credentials'));
})->group('RS-03', 'RS-12', 'RF-ID-06');

it('no anota el fallo de quien ya esta bloqueado, para que el bloqueo no crezca por insistir', function (): void {
    // El fallo del bloqueado se anota contra el señuelo: paga el mismo trabajo y
    // no alarga el castigo de nadie (RS-12). Sin esto, quien insiste se bloquea
    // indefinidamente a si mismo — o a la persona cuyo codigo conoce.
    $empleado = empleadoDeLaSimetria();
    espiaDelContador();

    $verificador = app(EmployeePinVerifier::class);

    for ($intento = 0; $intento < 3; $intento++) {
        $verificador->verify($empleado['code'], PIN_MALO_DE_LA_SIMETRIA, PinOrigin::PORTAL);
    }

    $alBloquear = $verificador->verify($empleado['code'], PIN_MALO_DE_LA_SIMETRIA, PinOrigin::PORTAL);

    for ($intento = 0; $intento < 5; $intento++) {
        $verificador->verify($empleado['code'], PIN_MALO_DE_LA_SIMETRIA, PinOrigin::PORTAL);
    }

    $despuesDeInsistir = $verificador->verify($empleado['code'], PIN_MALO_DE_LA_SIMETRIA, PinOrigin::PORTAL);

    // Mismo escalon —el primero, 300 s— y misma cuenta atras: el reloj esta
    // detenido, asi que cualquier diferencia seria un fallo anotado de mas.
    expect($alBloquear->isLocked())->toBeTrue()
        ->and($alBloquear->retryAfterSeconds())->toBe(300)
        ->and($despuesDeInsistir->retryAfterSeconds())->toBe(300);
})->group('RS-12', 'RF-ID-06');
