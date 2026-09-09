<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `php artisan kiosk:health` contra la base de datos de verdad (**RF-PA-07**,
 * doc 02 Anexo C, tarea 5.11).
 *
 * ## Que se prueba aqui y que no
 *
 * Las fronteras de los dos plazos ya estan fijadas al segundo en
 * `tests/Unit/Kiosk/Application/CheckKioskHealthTest.php`, sin base de datos y
 * con reloj fijo. Aqui se comprueba lo que aquella no puede: que la consulta
 * lee de `devices` de verdad, que el comando esta REGISTRADO —el motivo por el
 * que existe esta tarea es que la documentacion del cliente lo citaba y no
 * existia—, que los codigos de salida son los que un script vera, que el
 * `--json` tiene la forma que dice y que el informe sale entero en un idioma.
 *
 * ## Los tres quioscos del escenario
 *
 * Uno correcto, uno con cola sin drenar y uno callado: es literalmente lo que
 * el runbook `alta-nuevo-quiosco.md` manda mirar en sus §4.2, §5.1 y §6.
 *
 * Se usa `Artisan::call()` y no `$this->artisan()` porque hay que afirmar sobre
 * la salida COMPLETA —la tabla, los consejos y el resultado— y no linea a linea.
 */

uses(RefreshDatabase::class);

/** 12:00 UTC = 14:00 en `Europe/Madrid`, la zona del centro de las pruebas. */
const AHORA_EN_LA_SALUD = '2026-09-09 12:00:00';

beforeEach(function (): void {
    WorkforceFixtures::site();
    app()->instance(Clock::class, FixedClock::at(AHORA_EN_LA_SALUD));
});

/**
 * Un quiosco en `devices`, escrito directamente.
 *
 * Se inserta la fila en vez de recorrer el emparejamiento entero porque lo que
 * se prueba es la LECTURA: el alta ya tiene sus pruebas en `PairingTest`, y
 * hacerla aqui ataria esta prueba a un flujo que no interviene.
 *
 * `token_hash` se rellena a proposito en el que esta sano: es la unica forma de
 * comprobar que no aparece por ningun lado de la salida (regla dura 21).
 */
function quioscoEnLaBase(
    string $name,
    ?string $lastSeenAt,
    int $pendingQueueSize = 0,
    string $status = 'active',
    ?string $appVersion = '1.4.0',
    ?string $tokenHash = null,
): void {
    DB::table('devices')->insert([
        'uuid' => (string) Str::uuid(),
        'site_id' => WorkforceFixtures::site(),
        'name' => $name,
        'token_hash' => $tokenHash,
        'app_version' => $appVersion,
        'last_seen_at' => $lastSeenAt,
        'pending_queue_size' => $pendingQueueSize,
        'status' => $status,
        'paired_at' => '2026-09-01 08:00:00+00',
        'created_at' => '2026-09-01 08:00:00+00',
        'updated_at' => '2026-09-01 08:00:00+00',
    ]);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{code: int, output: string}
 */
function ejecutarSalud(array $parameters = []): array
{
    $code = Artisan::call('kiosk:health', $parameters);

    return ['code' => $code, 'output' => Artisan::output()];
}

/** La flota del escenario: uno bien, uno con cola y uno callado. */
function flotaDeTresQuioscos(): void
{
    quioscoEnLaBase('Recepcion', '2026-09-09 11:59:30+00', tokenHash: str_repeat('a', 64));
    quioscoEnLaBase('Cocina', '2026-09-09 11:59:40+00', pendingQueueSize: 5);
    quioscoEnLaBase('Almacen', '2026-09-09 09:00:00+00');
}

// --- Los codigos de salida, que es lo que consume un script -----------------

it('devuelve 0 con todos los quioscos al dia y sin cola', function (): void {
    quioscoEnLaBase('Recepcion', '2026-09-09 11:59:30+00');
    quioscoEnLaBase('Cocina', '2026-09-09 11:58:30+00');

    $resultado = ejecutarSalud();

    expect($resultado['code'])->toBe(0)
        ->and($resultado['output'])->toContain('Resultado: CORRECTO')
        ->and($resultado['output'])->toContain('Recepcion')
        ->and($resultado['output'])->toContain('Cocina');
})->group('RF-PA-07');

it('devuelve 1 cuando lo unico que pasa es que un quiosco arrastra cola', function (): void {
    // Runbook §5.1: es lo que hay que mirar ANTES de desvincular una tablet
    // averiada, porque los fichajes sin enviar se pierden al revocar el token.
    quioscoEnLaBase('Recepcion', '2026-09-09 11:59:30+00');
    quioscoEnLaBase('Cocina', '2026-09-09 11:59:40+00', pendingQueueSize: 5);

    $resultado = ejecutarSalud();

    expect($resultado['code'])->toBe(1)
        ->and($resultado['output'])->toContain('Resultado: CON AVISOS')
        ->and($resultado['output'])->toContain('NO LO DESVINCULES');
})->group('RF-PA-07');

it('devuelve 2 con un quiosco activo que lleva mas de diez minutos callado', function (): void {
    // Es el mismo umbral que la alerta «Quiosco sin latido > 10 min, Critica»
    // del doc 01 §9.3: la consola y la observabilidad no pueden decir cosas
    // distintas del mismo quiosco a las 06:00.
    flotaDeTresQuioscos();

    $resultado = ejecutarSalud();

    expect($resultado['code'])->toBe(2)
        ->and($resultado['output'])->toContain('Resultado: CON FALLOS')
        ->and($resultado['output'])->toContain('Almacen');
})->group('RF-PA-07');

it('devuelve 1, y no 0, en una instalacion sin ningun quiosco vinculado', function (): void {
    // Sin quioscos activos NADIE PUEDE FICHAR. Decir «correcto» de eso seria
    // mentirle a la fila 20 de la lista de comprobacion de `endurecimiento.md`,
    // que es la que se ejecuta cada trimestre para descubrir justo esto.
    $resultado = ejecutarSalud();

    expect($resultado['code'])->toBe(1)
        ->and($resultado['output'])->toContain('Todavia no hay ningun quiosco vinculado')
        ->and($resultado['output'])->toContain('kiosk:pairing-code');
})->group('RF-PA-07');

it('no cuenta como fallo un quiosco revocado que dejo de latir hace meses', function (): void {
    // Un desvinculado no late porque no debe (runbook §5). Contarlo como fallo
    // llenaria de rojo la consola de cualquier hotel que haya cambiado una
    // tablet, y una lista que siempre sale en rojo se deja de mirar.
    quioscoEnLaBase('Recepcion', '2026-09-09 11:59:30+00');
    quioscoEnLaBase('Cocina vieja', '2026-06-01 10:00:00+00', status: 'revoked');

    $resultado = ejecutarSalud();

    expect($resultado['code'])->toBe(0)
        ->and($resultado['output'])->toContain('Cocina vieja')
        ->and($resultado['output'])->toContain('revocado');
})->group('RF-PA-07');

// --- La tabla que lee una persona -------------------------------------------

it('enseña el ultimo contacto en relativo y en la hora del centro', function (): void {
    // Los dos y no uno (regla dura 3): el relativo se compara con el umbral de
    // un vistazo y el absoluto se cruza con el corte de luz o el turno en el que
    // dejaron de llegar fichajes. 12:00 UTC son las 14:00 en `Europe/Madrid`.
    flotaDeTresQuioscos();

    $resultado = ejecutarSalud();

    expect($resultado['output'])->toContain('hace 30 s')
        ->and($resultado['output'])->toContain('09/09/2026 13:59:30')
        ->and($resultado['output'])->toContain('hace 3 h 0 min')
        ->and($resultado['output'])->toContain('Europe/Madrid');
})->group('RF-PA-07');

it('dice que mirar en cada quiosco con hallazgos, no solo que va mal', function (): void {
    // El fabricante no accede a este servidor (ADR-016): un mensaje que solo
    // diga «aviso» deja como unica salida una llamada de telefono. Y las dos
    // causas de un aviso piden acciones distintas.
    flotaDeTresQuioscos();

    $resultado = ejecutarSalud();

    // El callado manda ir a verlo y el que arrastra cola manda esperar y NO
    // desvincular: si los dos dijeran «aviso» y nada mas, quien lo lee podria
    // desvincular el equivocado y perder fichajes.
    expect($resultado['output'])->toContain('Que hay que mirar')
        ->and($resultado['output'])->toContain('Ve a verlo')
        ->and($resultado['output'])->toContain('los fichajes sin enviar se pierden al revocar el token');
})->group('RF-PA-07');

it('no imprime el hash del token de ningun dispositivo', function (): void {
    // Regla dura 21 y el mismo criterio que `DeviceResource`: quien lo viera
    // tendria la mitad del trabajo hecho para suplantar a un quiosco. Ni sale de
    // la consulta ni puede salir por el `--json`.
    flotaDeTresQuioscos();

    expect(ejecutarSalud()['output'])->not->toContain(str_repeat('a', 64))
        ->and(ejecutarSalud(['--json' => true])['output'])->not->toContain(str_repeat('a', 64));
})->group('RF-PA-07');

// --- El `--json`, que es lo que consume un script ---------------------------

it('devuelve por --json la misma informacion, en UTC y con el mismo codigo de salida', function (): void {
    flotaDeTresQuioscos();

    $resultado = ejecutarSalud(['--json' => true]);

    /** @var array{status: string, exit_code: int, thresholds: array{fresh_within_seconds: int, silent_after_seconds: int}, fleet: array{total: int, active: int}, devices: list<array<string, mixed>>} $informe */
    $informe = json_decode($resultado['output'], true, 512, JSON_THROW_ON_ERROR);

    expect($resultado['code'])->toBe(2)
        ->and($informe['status'])->toBe('failure')
        ->and($informe['exit_code'])->toBe(2)
        ->and($informe['thresholds'])->toBe(['fresh_within_seconds' => 120, 'silent_after_seconds' => 600])
        ->and($informe['fleet'])->toBe(['total' => 3, 'active' => 3])
        ->and($informe['devices'])->toHaveCount(3);

    $porNombre = array_column($informe['devices'], null, 'name');

    // Los instantes salen en UTC con sufijo `Z` (regla dura 3): quien consume
    // esto es un script, y la zona del centro es cosa de la presentacion.
    expect($porNombre['Recepcion']['verdict'])->toBe('ok')
        ->and($porNombre['Recepcion']['reason'])->toBe('beating')
        ->and($porNombre['Recepcion']['last_seen_at'])->toEndWith('Z')
        ->and($porNombre['Recepcion']['seconds_since_last_seen'])->toBe(30)
        ->and($porNombre['Cocina']['verdict'])->toBe('warning')
        ->and($porNombre['Cocina']['reason'])->toBe('queue_pending')
        ->and($porNombre['Cocina']['pending_queue_size'])->toBe(5)
        ->and($porNombre['Almacen']['verdict'])->toBe('failure')
        ->and($porNombre['Almacen']['reason'])->toBe('silent');
})->group('RF-PA-07');

it('devuelve un JSON valido tambien sin ningun quiosco', function (): void {
    // El caso que rompe a quien escribe el script el dia de la instalacion.
    $resultado = ejecutarSalud(['--json' => true]);

    /** @var array{fleet: array{total: int, active: int}, devices: list<mixed>} $informe */
    $informe = json_decode($resultado['output'], true, 512, JSON_THROW_ON_ERROR);

    expect($resultado['code'])->toBe(1)
        ->and($informe['devices'])->toBe([])
        ->and($informe['fleet'])->toBe(['total' => 0, 'active' => 0]);
})->group('RF-PA-07');

// --- Un solo idioma en todo el informe --------------------------------------

it('saca el informe entero en el idioma que se le pide', function (): void {
    // El informe a dos idiomas —marco en español y contenido en ingles— es lo
    // que pasa en cuanto `APP_LOCALE` y `LOCALE_DEFAULT` no coinciden, que es el
    // caso normal. Por eso ni una linea esta escrita a mano en el comando.
    flotaDeTresQuioscos();

    $resultado = ejecutarSalud(['--lang' => 'en']);

    expect($resultado['code'])->toBe(2)
        ->and($resultado['output'])->toContain('Kiosk health')
        ->and($resultado['output'])->toContain('Last contact')
        ->and($resultado['output'])->toContain('What to look at')
        ->and($resultado['output'])->toContain('Result: WITH FAILURES')
        ->and($resultado['output'])->not->toContain('Que hay que mirar')
        ->and($resultado['output'])->not->toContain('kiosk.health.');
})->group('RF-PA-07', 'RF-PD-08');
