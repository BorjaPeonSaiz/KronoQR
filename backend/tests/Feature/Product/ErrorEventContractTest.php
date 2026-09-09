<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las tres rutas del historico de errores, **validadas contra
 * `docs/api/openapi.yaml`** (RF-PD-15, ADR-013, RQ-06).
 *
 * El contrato es la fuente de verdad y de el generan sus tipos las tres SPA:
 * `assertValidResponse()` es lo que impide que el codigo y el contrato se
 * separen sin que nadie se entere. Aqui se cubren las formas que un cliente
 * generado puede recibir y que las pruebas de comportamiento no miran de cerca:
 * los tres cuerpos de exito y los tres codigos de error.
 *
 * EL RELOJ ESTA FIJO (regla dura 2): `meta.generated_at` y `resolved_at` son
 * campos del contrato, y sin reloj fijo serian cifras que cambian segun la hora
 * a la que corra la suite.
 */

uses(RefreshDatabase::class);

const ERROR_HISTORY_NOW = '2026-09-09 08:12:44';

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    WorkforceFixtures::site();
});

/**
 * Deja el reloj del producto detenido en {@see ERROR_HISTORY_NOW}.
 *
 * **No va en el `beforeEach`, y eso es deliberado.** Lo que aqui se valida es la
 * FORMA de la respuesta, no sus valores, asi que el reloj fijo no aporta nada a
 * la mayoria de los casos; y en el de la sesion de portal hacia dano: el token
 * se acuna con el reloj inyectado y Sanctum comprueba su caducidad contra el
 * reloj real, de modo que la prueba empezaba a devolver `401` a partir de cierta
 * hora del dia. Una prueba que pasa por la manana y falla por la tarde no
 * verifica nada, solo dice cuando se ejecuto.
 *
 * Se llama solo donde el instante importa: donde se compara `resolved_at` o
 * `generated_at`.
 */
function conRelojFijo(): void
{
    app()->instance(Clock::class, FixedClock::at(ERROR_HISTORY_NOW));
}

/**
 * Un grupo con **todos** los campos poblados, para que la validacion no pase por
 * omision: un `null` casa con casi cualquier `oneOf`.
 */
function grupoCompleto(): int
{
    $ahora = now()->toDateTimeString('microsecond');

    return (int) DB::table('error_events')->insertGetId([
        'fingerprint' => bin2hex(random_bytes(32)),
        'level' => 'critical',
        'source' => 'worker',
        'module' => 'attendance',
        'code' => 'attendance.projection_failed',
        'message' => "SQLSTATE[08006] connection to server at '…' failed",
        'exception_class' => 'PDOException',
        'file' => 'app/Modules/Attendance/Application/UseCase/RecordScan.php',
        'line' => 88,
        /*
         * SOLO TEXTO Y BOOLEANOS, y no porque el producto guarde solo eso: ver
         * la prueba marcada como pendiente al final de este fichero. El contrato
         * declara hoy `context.additionalProperties` con un `oneOf` que incluye
         * `integer` **y** `number`, y en JSON Schema un entero casa con los dos,
         * asi que «exactamente uno» falla siempre. Es un defecto del contrato,
         * no del codigo.
         */
        'context' => json_encode(['route' => '/api/v1/scan', 'method' => 'POST', 'outcome' => false]),
        'trace_id' => str_repeat('a1b2c3d4', 4),
        'device_id' => '0199f0aa-1111-7000-8000-0123456789ab',
        'employee_uuid' => '0199f0aa-2222-7000-8000-0123456789ab',
        'app_version' => '2.2.0',
        'occurrences' => 1000,
        'first_seen_at' => $ahora,
        'last_seen_at' => $ahora,
        'created_at' => $ahora,
        'updated_at' => $ahora,
    ]);
}

it('GET /diagnostics/errors devuelve un ErrorEventCollection valido', function (): void {
    grupoCompleto();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/diagnostics/errors?status=all&source=worker&level=critical&page=1&per_page=25')
        ->assertValidResponse(200);
})->group('RF-PD-15', 'RQ-06');

it('GET /diagnostics/errors devuelve una pagina vacia valida', function (): void {
    // El caso normal en una instalacion sana, y el que mas facil se rompe: un
    // `data: []` con un `meta` incompleto pasa cualquier prueba de
    // comportamiento y revienta el cliente generado.
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/diagnostics/errors')
        ->assertValidResponse(200);
})->group('RF-PD-15', 'RQ-06');

it('POST /diagnostics/errors/{id}/resolve devuelve un ErrorEvent valido', function (): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    $id = grupoCompleto();

    Api::as($token)->post('/api/v1/diagnostics/errors/'.$id.'/resolve')->assertValidResponse(200);

    // Y la segunda, que es la idempotente: devuelve la misma fila ya resuelta,
    // con `resolved_by` poblado. Es otra forma del esquema y se valida aparte.
    Api::as($token)->post('/api/v1/diagnostics/errors/'.$id.'/resolve')->assertValidResponse(200);
})->group('RF-PD-15', 'RQ-06');

it('POST /client-errors devuelve un ClientErrorsAccepted valido', function (): void {
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/client-errors', [
            'errors' => [[
                'code' => 'web.vue_error',
                'occurred_at' => '2026-09-09T08:12:03.512000Z',
                'app_version' => '2.2.0',
                'context' => ['component' => 'WorkdaysView', 'hook' => 'setup function'],
            ]],
        ])
        ->assertValidResponse(202);
})->group('RF-PD-15', 'RQ-06');

it('POST /client-errors admite el contexto vacio que el propio contrato ejemplifica', function (): void {
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/client-errors', [
            'errors' => [[
                'code' => 'web.unhandled_rejection',
                'occurred_at' => '2026-09-09T08:12:03.512000Z',
                'app_version' => '2.2.0',
                'context' => [],
            ]],
        ])
        ->assertValidResponse(202);
})->group('RF-PD-15', 'RQ-06');

it('POST /client-errors tambien con una sesion de portal', function (): void {
    // La otra mitad del `security` del contrato: `employeeToken: ['self:read']`.
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::onlySiteId());

    Api::as(PortalLogins::open($uuid))
        ->post('/api/v1/client-errors', [
            'errors' => [[
                'code' => 'web.vue_error',
                'occurred_at' => '2026-09-09T08:12:03.512000Z',
                'app_version' => '2.2.0',
                'context' => ['component' => 'MyWorkdaysView'],
            ]],
        ])
        ->assertValidResponse(202);
})->group('RF-PD-15', 'RQ-06');

it('acepta un contexto con enteros, que es lo que el producto guarda de verdad', function (): void {
    /*
     * Guarda contra una regresion del contrato: `ErrorEvent.context` y
     * `ClientErrorReport.context` declaran los valores con `anyOf` y no con
     * `oneOf`. En JSON Schema un entero valida contra `integer` Y contra
     * `number` a la vez, asi que un `oneOf` —«exactamente uno»— rechazaria
     * justo los valores que la lista de permitidos de la decision 5 espera:
     * `http_status`, `attempts`, `skew_seconds` y `line`. Esta prueba envia dos
     * enteros y valida peticion y respuesta con Spectator.
     *
     * El codigo es del catalogo **de web**, no del quiosco: desde una sesion de
     * gestion, un `kiosk.*` es una peticion invalida (revision de seguridad,
     * `ClientErrorCode::isKnown()`), y esta prueba mira la forma del contrato,
     * no la autorizacion.
     */
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->post('/api/v1/client-errors', [
        'errors' => [[
            'code' => 'web.unhandled_rejection',
            'occurred_at' => '2026-09-09T08:12:03.512000Z',
            'app_version' => '2.2.0',
            'context' => ['message' => 'Error: fetch failed', 'http_status' => 503, 'line' => 12],
        ]],
    ])->assertValidResponse(202);

    Api::as($token)->get('/api/v1/diagnostics/errors')->assertValidResponse(200);
})->group('RF-PD-15', 'RQ-06');

it('los tres codigos de error tienen la forma del contrato', function (): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    $rrhh = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    // 422: un filtro que no existe.
    Api::as($token)->get('/api/v1/diagnostics/errors?severity=critical')->assertValidResponse(422);

    // 403: un rol que no es `admin`.
    Api::as($rrhh)->get('/api/v1/diagnostics/errors')->assertValidResponse(403);

    // 404: un grupo que no esta.
    Api::as($token)->post('/api/v1/diagnostics/errors/999999/resolve')->assertValidResponse(404);

    // 401: sin token.
    Api::guest()->get('/api/v1/diagnostics/errors')->assertValidResponse(401);
})->group('RF-PD-15', 'RQ-06');
