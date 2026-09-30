<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\ScanMetrics;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Attendance\RecordingScanMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Health\RedisOutage;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\EmployeePins;

/*
 * **Con Redis caido, el quiosco sigue fichando** (CH1, regla dura 19, RF-AT-10,
 * RS-02).
 *
 * La verificacion de la 2.1.0 paro el contenedor de Redis y `/scan` y `/scan/pin`
 * respondieron `500`: el limitador `throttle:` no capturaba la `RedisException`,
 * y detras de el la cache de la configuracion, el contador de fallos del PIN y la
 * cola de la difusion al panel en vivo tampoco. Todo lo que llegaba al servidor
 * durante la averia se perdia en un `500` hasta que alguien reparaba Redis.
 *
 * **Redis de verdad, en un puerto muerto**, con la cache, el limitador y la cola
 * sobre el como en produccion (ver `RedisOutage`). Un doble del cliente no
 * reproduciria la caida: la cache y la cola llegan a Redis por caminos que el
 * doble no sustituye.
 *
 * Y la otra mitad, que es la que importa en seguridad: **el limitador solo se
 * abre en el fichaje**. Una ruta de gestion con Redis caido sigue fallando
 * cerrada, y la autorizacion negativa sigue siendo un `403`.
 */

uses(RefreshDatabase::class);

afterEach(function (): void {
    RedisOutage::end();
});

const SCAN_WITHOUT_REDIS_TARJETA = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

const SCAN_WITHOUT_REDIS_PIN = '552190';

/**
 * El escenario de fichaje, preparado ANTES de tirar Redis: el alta del
 * empleado, su PIN y el emparejamiento del quiosco no son lo que se prueba.
 *
 * @return array{site: int, employee: string, device: int, deviceUuid: string, token: string, code: string, publicKey: string}
 */
function escenarioSinRedis(): array
{
    $escenario = AttendanceFixtures::scenario();

    EmployeePins::issue($escenario['employee'], SCAN_WITHOUT_REDIS_PIN);

    FrozenTime::at('2026-03-14 07:02:31');

    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving(SCAN_WITHOUT_REDIS_TARJETA, $escenario['employee']),
    );
    app()->instance(ScanMetrics::class, new RecordingScanMetrics);

    Spectator::using('openapi.yaml');

    return [
        ...$escenario,
        'code' => EmployeePins::codeOf($escenario['employee']),
        'publicKey' => EmployeePins::configureSealing(),
    ];
}

/**
 * Los avisos de log que vaya dejando la peticion.
 *
 * @return ArrayObject<int, array{message: string, context: array<mixed>}>
 */
function avisosSinRedis(): ArrayObject
{
    /** @var ArrayObject<int, array{message: string, context: array<mixed>}> $avisos */
    $avisos = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $logged) use ($avisos): void {
        $avisos->append(['message' => $logged->message, 'context' => $logged->context]);
    });

    return $avisos;
}

it('registra un fichaje por tarjeta con Redis caido', function (): void {
    $escenario = escenarioSinRedis();
    RedisOutage::begin();

    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => SCAN_WITHOUT_REDIS_TARJETA,
        ]);

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('result'))->toBe('clock_in')
        ->and(DB::table('shift_entries')->count())->toBe(1)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RF-AT-01', 'RF-AT-10', 'RS-02');

it('devuelve la respuesta original al reenviar un fichaje con Redis caido', function (): void {
    // La idempotencia no pasa por Redis (regla dura 8): la decide el UNIQUE de
    // `scan_events.scan_id`. Un reenvio durante la caida es lo normal —la cola
    // offline reintenta— y tiene que devolver lo mismo.
    $escenario = escenarioSinRedis();
    RedisOutage::begin();

    $scanId = Str::uuid7()->toString();
    $cuerpo = [
        'scan_id' => $scanId,
        'occurred_at' => '2026-03-14T07:02:31Z',
        'qr_payload' => SCAN_WITHOUT_REDIS_TARJETA,
    ];

    $primera = Api::as($escenario['token'])->withHeaders(['Idempotency-Key' => $scanId])->post('/api/v1/scan', $cuerpo);
    $reenvio = Api::as($escenario['token'])->withHeaders(['Idempotency-Key' => $scanId])->post('/api/v1/scan', $cuerpo);

    $primera->assertOk();
    $reenvio->assertOk();

    expect($reenvio->json())->toBe($primera->json())
        ->and(DB::table('scan_events')->count())->toBe(1);
})->group('RF-AT-07', 'RF-AT-10');

it('registra un lote de la cola offline con Redis caido', function (): void {
    $escenario = escenarioSinRedis();
    RedisOutage::begin();

    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => Str::uuid7()->toString()])
        ->post('/api/v1/scan/batch', ['scans' => [[
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => SCAN_WITHOUT_REDIS_TARJETA,
        ]]]);

    $respuesta->assertStatus(207)->assertValidResponse();

    expect(DB::table('scan_events')->where('scan_id', $scanId)->value('result'))->toBe('clock_in');
})->group('RF-KI-04', 'RF-AT-10', 'RS-02');

it('registra un fichaje por PIN con Redis caido', function (): void {
    $escenario = escenarioSinRedis();
    RedisOutage::begin();

    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'employee_code' => $escenario['code'],
            'pin_sealed' => EmployeePins::seal(SCAN_WITHOUT_REDIS_PIN, $escenario['publicKey']),
        ]);

    $respuesta->assertOk()->assertValidResponse();

    expect($respuesta->json('action'))->toBe('clock_in')
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->value('origin'))->toBe('pin_kiosk');
})->group('RF-AT-11', 'RF-AT-10', 'RS-12');

it('sigue rechazando un PIN erroneo con Redis caido', function (): void {
    // Fallar abierto el LIMITADOR no es fallar abierto la CREDENCIAL: un PIN que
    // no verifica sigue siendo el rechazo generico (RS-03), con o sin Redis.
    $escenario = escenarioSinRedis();
    RedisOutage::begin();

    $scanId = Str::uuid7()->toString();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'employee_code' => $escenario['code'],
            'pin_sealed' => EmployeePins::seal('000000', $escenario['publicKey']),
        ])
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:scan-rejected');

    expect(DB::table('shift_entries')->count())->toBe(0);
})->group('RF-AT-11', 'RS-03', 'RS-12');

it('mantiene el bloqueo por PIN durante la caida de Redis', function (): void {
    // El contador de fallos cae al disco con la cache (`resilient`), no a la
    // memoria de una peticion: tres PIN erroneos siguen bloqueando, y el PIN
    // bueno no entra mientras dura el bloqueo (RS-12). Si el contador se
    // perdiera con Redis, la averia seria una ventana de fuerza bruta.
    $escenario = escenarioSinRedis();
    RedisOutage::begin();

    $fichar = static fn (string $pin): mixed => Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId = Str::uuid7()->toString()])
        ->post('/api/v1/scan/pin', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'employee_code' => $escenario['code'],
            'pin_sealed' => EmployeePins::seal($pin, $escenario['publicKey']),
        ]);

    for ($intento = 0; $intento < 3; $intento++) {
        $fichar('000000')->assertStatus(422);
    }

    $fichar(SCAN_WITHOUT_REDIS_PIN)->assertStatus(422);

    expect(DB::table('shift_entries')->count())->toBe(0);
})->group('RF-AT-11', 'RS-12');

it('difunde al panel despues de responder cuando la cola de Redis no responde', function (): void {
    // La difusion al panel en vivo es un oyente encolado tras el COMMIT. Con
    // Redis caido, la conexion `resilient` la ejecuta en el mismo proceso tras la
    // respuesta, y deja constancia de que la cola cayo a su respaldo.
    $escenario = escenarioSinRedis();
    RedisOutage::begin();
    $avisos = avisosSinRedis();

    $scanId = Str::uuid7()->toString();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => SCAN_WITHOUT_REDIS_TARJETA,
        ])
        ->assertOk();

    $colas = array_values(array_filter(
        $avisos->getArrayCopy(),
        static fn (array $aviso): bool => $aviso['message'] === 'queue.failed_over',
    ));

    expect($colas)->not->toBe([])
        ->and($colas[0]['context']['connection'])->toBe('redis')
        ->and(array_keys($colas[0]['context']))->toBe(['connection', 'job', 'failure']);
})->group('RF-AT-10', 'RF-PA-02');

it('deja rastro sin datos personales cuando el limitador falla abierto', function (): void {
    $escenario = escenarioSinRedis();
    RedisOutage::begin();
    $avisos = avisosSinRedis();

    $scanId = Str::uuid7()->toString();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => SCAN_WITHOUT_REDIS_TARJETA,
        ])
        ->assertOk();

    $abiertos = array_values(array_filter(
        $avisos->getArrayCopy(),
        static fn (array $aviso): bool => $aviso['message'] === 'attendance.rate_limiter_fail_open',
    ));

    expect($abiertos)->not->toBe([]);

    // La zona, la fase y la clase: nada de empleado, codigo, IP ni el mensaje de
    // la excepcion, que lleva host y puerto (regla dura 21).
    expect(array_keys($abiertos[0]['context']))->toBe(['zone', 'phase', 'failure'])
        ->and($abiertos[0]['context']['zone'])->toBe('scan')
        ->and($abiertos[0]['context']['failure'])->toBe(RedisException::class);
})->group('RF-AT-10', 'RS-02');

it('mantiene la autorizacion negativa del fichaje con Redis caido', function (): void {
    // El fallo abierto va DESPUES de `auth:sanctum` y de `ability:`: una sesion
    // de gestion sin `scan:write` sigue recibiendo su `403` (regla dura 18).
    escenarioSinRedis();
    $gestion = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    RedisOutage::begin();

    Api::as($gestion)
        ->post('/api/v1/scan', [
            'scan_id' => Str::uuid7()->toString(),
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => SCAN_WITHOUT_REDIS_TARJETA,
        ])
        ->assertForbidden();

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RF-AT-01', 'RS-04');

it('no abre el limitador de una ruta de gestion con Redis caido', function (): void {
    // La mitad de seguridad de CH1: fuera del fichaje el limitador falla
    // CERRADO. Una averia de Redis no puede convertirse en una ventana sin techo
    // para probar credenciales o descargar la plantilla.
    escenarioSinRedis();
    $gestion = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    RedisOutage::begin();

    $respuesta = Api::as($gestion)->get('/api/v1/employees');

    expect($respuesta->status())->toBe(500)
        ->and($respuesta->headers->get('Content-Type'))->toBe('application/problem+json');
})->group('RS-02', 'RQ-06');
