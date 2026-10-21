<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Support\Database\DatabaseCircuitBreaker;
use App\Support\Network\BoundedReachability;
use App\Support\Redis\RedisCircuitBreaker;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Health\CountingRedisConnector;
use Tests\Support\Health\RedisOutage;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * CON UNA DEPENDENCIA CAIDA, `503` CON `Retry-After` Y RASTRO, NUNCA `500`
 * (ADR-059 §1, §2 y §8; R3-CH-03, V3-CH-1, V21-3-CH-1).
 *
 * Redis de verdad en un puerto muerto y con su circuito abierto, como en una
 * averia de verdad pasados los primeros milisegundos. Por familia de la tabla
 * del §8 que falla cerrada:
 *
 * - `503 service-unavailable` con `Retry-After`;
 * - un cuerpo que no nombra la dependencia;
 * - una linea `http.dependency_unavailable` con `dependency: redis` y sin el
 *   mensaje de la excepcion;
 * - ninguna fila nueva en `error_events`.
 *
 * Y PostgreSQL perdido a mitad de transaccion: `503` y nada escrito.
 */

uses(RefreshDatabase::class);

afterEach(function (): void {
    RedisOutage::end();

    if (is_file(dependencyUnavailableCircuitFile())) {
        unlink(dependencyUnavailableCircuitFile());
    }
});

function dependencyUnavailableCircuitFile(): string
{
    return sys_get_temp_dir().'/kronoqr-redis-circuit-dependency-'.getmypid();
}

/** Redis caido y con el circuito ya abierto. */
function dependencyUnavailableRedisDown(): void
{
    RedisOutage::begin();

    $breaker = new RedisCircuitBreaker(dependencyUnavailableCircuitFile(), 10.0, new MockClock, resolve(LoggerInterface::class));
    app()->instance(RedisCircuitBreaker::class, $breaker);
    resolve('redis')->extend('phpredis', fn (): CountingRedisConnector => new CountingRedisConnector($breaker, new BoundedReachability));
    $breaker->recordFailure(new RedisException('Connection refused [tcp://10.20.30.40:6379]'));
}

/**
 * @return ArrayObject<int, array{level: string, message: string, context: array<mixed>}>
 */
function dependencyUnavailableLog(): ArrayObject
{
    /** @var ArrayObject<int, array{level: string, message: string, context: array<mixed>}> $lineas */
    $lineas = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $logged) use ($lineas): void {
        $lineas->append(['level' => $logged->level, 'message' => $logged->message, 'context' => $logged->context]);
    });

    return $lineas;
}

/**
 * @param  TestResponse<Response>  $respuesta
 * @param  ArrayObject<int, array{level: string, message: string, context: array<mixed>}>  $lineas
 */
function dependencyUnavailableAssert(TestResponse $respuesta, ArrayObject $lineas, string $dependency, int $filasAntes, int $retryAfter = 10): void
{
    $respuesta->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('Retry-After', (string) $retryAfter)
        ->assertJsonPath('type', 'urn:kronoqr:problem:service-unavailable');

    $cuerpo = strtolower((string) $respuesta->getContent());

    $rastro = array_values(array_filter(
        $lineas->getArrayCopy(),
        static fn (array $linea): bool => $linea['message'] === 'http.dependency_unavailable',
    ));

    expect($cuerpo)->not->toContain('redis')
        ->and($cuerpo)->not->toContain('postgres')
        ->and($cuerpo)->not->toContain('10.20.30.40')
        ->and($rastro)->toHaveCount(1)
        ->and($rastro[0]['level'])->toBe('warning')
        ->and($rastro[0]['context']['dependency'] ?? null)->toBe($dependency)
        ->and($rastro[0]['context']['retry_after'] ?? null)->toBe($retryAfter)
        ->and(json_encode($rastro[0]['context']))->not->toContain('10.20.30.40')
        ->and(DB::table('error_events')->count())->toBe($filasAntes);
}

it('responde 503 con rastro en las rutas de gestion con Redis caido', function (string $metodo, string $ruta): void {
    AttendanceFixtures::scenario();
    $gestion = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    dependencyUnavailableRedisDown();
    $lineas = dependencyUnavailableLog();
    $filas = DB::table('error_events')->count();

    $respuesta = $metodo === 'GET'
        ? Api::as($gestion)->get($ruta)
        : Api::as($gestion)->call($metodo, $ruta, ['reason_code' => 'forgot_to_clock', 'reason' => 'Prueba']);

    dependencyUnavailableAssert($respuesta, $lineas, 'redis', $filas);
})->with([
    'plantilla' => ['GET', '/api/v1/employees'],
    'presencia por sondeo' => ['GET', '/api/v1/attendance/live'],
    'informe por periodo' => ['GET', '/api/v1/reports/period?from=2026-03-01&to=2026-03-15'],
    'correccion de un tramo' => ['PATCH', '/api/v1/shift-entries/019a0000-0000-7000-8000-000000000001'],
])->group('RNF-D-03', 'RQ-06');

it('responde 503 con rastro en el latido y el padron del quiosco con Redis caido', function (string $metodo, string $ruta): void {
    $escenario = AttendanceFixtures::scenario();
    dependencyUnavailableRedisDown();
    $lineas = dependencyUnavailableLog();
    $filas = DB::table('error_events')->count();

    $respuesta = Api::as($escenario['token'])->call($metodo, $ruta, $metodo === 'POST' ? ['app_version' => '2.3.0'] : []);

    dependencyUnavailableAssert($respuesta, $lineas, 'redis', $filas);
})->with([
    'latido' => ['POST', '/api/v1/kiosk/heartbeat'],
    'padron' => ['GET', '/api/v1/kiosk/roster'],
])->group('RNF-D-03', 'RF-AT-10');

it('responde el mismo 503 al acceso del panel con Redis caido, exista o no la cuenta (RS-03)', function (): void {
    $correo = ManagementUsers::withRole(UserRole::ADMIN)->email;
    dependencyUnavailableRedisDown();

    $existente = Api::guest()->post('/api/v1/auth/login', ['email' => $correo, 'password' => 'una-contrasena-cualquiera']);
    $inexistente = Api::guest()->post('/api/v1/auth/login', ['email' => 'nadie@hotel.example', 'password' => 'una-contrasena-cualquiera']);

    $existente->assertStatus(503);
    $inexistente->assertStatus(503);

    $cuerpo = static function (TestResponse $respuesta): mixed {
        $json = $respuesta->json();
        \is_array($json) && \array_key_exists('instance', $json) ? $json['instance'] = '-' : null;

        return $json;
    };

    expect($cuerpo($existente))->toBe($cuerpo($inexistente));
})->group('RS-03', 'RNF-D-03');

it('responde 503 y no escribe nada si PostgreSQL se pierde a mitad de una transaccion', function (string $mensaje): void {
    $site = WorkforceFixtures::site('Hotel de la conexion perdida');

    Route::middleware('api')->post('/api/v1/__dependency/lost-connection', static function () use ($mensaje, $site): never {
        DB::transaction(static function () use ($mensaje, $site): never {
            DB::table('departments')->insert(['site_id' => $site, 'name' => 'Departamento a medias']);

            // Lo que lanza el driver cuando la conexion se pierde a mitad.
            $fallo = new PDOException('SQLSTATE[08006]: '.$mensaje.' host=10.20.30.40');
            $fallo->errorInfo = ['08006', 7, $mensaje];

            throw $fallo;
        });
    });

    $lineas = dependencyUnavailableLog();
    $filas = DB::table('error_events')->count();

    $respuesta = Api::guest()->post('/api/v1/__dependency/lost-connection');

    dependencyUnavailableAssert($respuesta, $lineas, 'database', $filas, app(DatabaseCircuitBreaker::class)->retryAfterSeconds());

    expect(DB::table('departments')->where('name', 'Departamento a medias')->count())->toBe(0);
})->with([
    'al deshacer' => ['no connection to the server'],
    'a mitad de sentencia' => ['server closed the connection unexpectedly'],
])->group('RNF-D-03', 'RQ-06');

it('sigue dando 500 a un fallo del producto que no es de una dependencia', function (): void {
    Route::middleware('api')->get('/api/v1/__dependency/defect', static fn (): never => throw new LogicException('defecto'));

    Api::guest()->get('/api/v1/__dependency/defect')
        ->assertStatus(500)
        ->assertJsonPath('type', 'urn:kronoqr:problem:internal-error');
})->group('RQ-06');

it('no declara ninguna cola de respaldo en base de datos que nadie consume (ADR-059 §5)', function (): void {
    // Se lee el fichero y no la configuracion resuelta: Laravel mezcla sus
    // conexiones de serie con las de la aplicacion, y lo que importa es que
    // nadie lea aqui un respaldo que no existe.
    $fichero = (string) file_get_contents(config_path('queue.php'));

    expect($fichero)->not->toMatch("/'driver' => 'failover',/")
        ->and(config('queue.connections.resilient.driver'))->toBe('failover-after-commit');
})->group('RNF-D-03');

it('en un trabajo o una orden, la base perdida deja un warning con la clase, sin mensaje y sin fila', function (): void {
    // Fuera de una peticion no hay respuesta que deje `http.dependency_unavailable`:
    // callar del todo dejaria una conexion perdida en un trabajo sin rastro.
    event(new CommandStarting('reporting:weekly-summary', new ArrayInput([]), new NullOutput));
    $lineas = dependencyUnavailableLog();
    $filas = DB::table('error_events')->count();

    $driver = new PDOException('SQLSTATE[HY000]: General error: 7 no connection to the server host=10.20.30.40');
    $driver->errorInfo = ['HY000', 7, 'no connection to the server'];

    app(ExceptionHandler::class)->report(new QueryException('pgsql', 'select 1', [], $driver));

    $avisos = array_values(array_filter($lineas->getArrayCopy(), static fn (array $linea): bool => $linea['message'] === 'dependency.unavailable'));

    expect($avisos)->toHaveCount(1)
        ->and($avisos[0]['level'])->toBe('warning')
        ->and($avisos[0]['context'])->toBe(['dependency' => 'database', 'failure' => QueryException::class])
        ->and(array_column($lineas->getArrayCopy(), 'level'))->not->toContain('error')
        ->and(json_encode($lineas->getArrayCopy()))->not->toContain('10.20.30.40')
        ->and(DB::table('error_events')->count())->toBe($filas);
})->group('RNF-D-03', 'RS-05');
