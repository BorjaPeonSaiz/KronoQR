<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use App\Modules\Reporting\Infrastructure\Adapter\ConcurrencyLimitedReportRenderer;
use App\Modules\Shared\Application\Port\PdfRenderingMetrics;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Psr\Log\LoggerInterface;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Health\RedisOutage;
use Tests\Support\Http\Api;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Reporting\FakeReportDocumentRenderer;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * EL PORTAL DEL EMPLEADO SIGUE ABIERTO CON REDIS CAIDO (ADR-059 §3 y §6, RL-03,
 * RL-05, RS-03, RS-12).
 *
 * Redis de verdad en un puerto muerto (`RedisOutage`), con la cache `resilient`
 * cayendo al disco del contenedor como en produccion:
 *
 * - `/me/login` y `/me/workdays` atienden: el limitador de Laravel de la zona
 *   `portal` falla abierto y lo deja dicho en el log;
 * - `/me/export` en CSV da `200`; en PDF, `503 service-unavailable` del embudo,
 *   que falla cerrado, con un `detail` que remite al CSV y no nombra Redis;
 * - el bloqueo por empleado y el bloqueo por origen siguen bloqueando, porque
 *   cuentan en el disco;
 * - las cinco causas de rechazo siguen siendo el mismo `401`.
 */

uses(RefreshDatabase::class);

afterEach(function (): void {
    RedisOutage::end();
});

const PORTAL_WITHOUT_REDIS_IP = '198.51.100.23';

beforeEach(function (): void {
    config()->set('identity.portal.rate_limit_per_minute', 10_000);
    config()->set('identity.pin.max_attempts', 3);
    config()->set('identity.pin.lockout_seconds', 300);
    config()->set('identity.portal.origin_lockout.max_failures', 4);
    config()->set('identity.portal.origin_lockout.window_seconds', 900);
    config()->set('identity.portal.origin_lockout.lockout_seconds', 3600);
});

/**
 * Un empleado con PIN, y su sesion de portal abierta ANTES de la averia.
 *
 * @return array{uuid: string, code: string, token: string}
 */
function portalSinRedisEmpleado(string $nombreDelCentro = 'Hotel sin Redis'): array
{
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site($nombreDelCentro));
    $token = PortalLogins::open($uuid);

    return ['uuid' => $uuid, 'code' => EmployeePins::codeOf($uuid), 'token' => $token];
}

/**
 * @return ArrayObject<int, array{message: string, context: array<mixed>}>
 */
function portalSinRedisAvisos(): ArrayObject
{
    /** @var ArrayObject<int, array{message: string, context: array<mixed>}> $avisos */
    $avisos = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $logged) use ($avisos): void {
        $avisos->append(['message' => $logged->message, 'context' => $logged->context]);
    });

    return $avisos;
}

it('abre sesion y consulta las jornadas con Redis caido, dejando dicho que el limitador fallo abierto', function (): void {
    $empleado = portalSinRedisEmpleado();
    RedisOutage::begin();
    $avisos = portalSinRedisAvisos();

    $login = Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ]);

    $login->assertOk();
    $token = $login->json('token');
    \assert(\is_string($token));

    Api::as($token)
        ->get('/api/v1/me/workdays?from=2026-03-01&to=2026-03-15')
        ->assertOk();

    $abiertos = array_filter(
        $avisos->getArrayCopy(),
        static fn (array $aviso): bool => $aviso['message'] === 'attendance.rate_limiter_fail_open'
            && ($aviso['context']['zone'] ?? null) === 'portal',
    );

    expect($abiertos)->not->toBeEmpty();
})->group('RL-05', 'RL-03', 'RS-02', 'RF-ID-06');

it('descarga el registro en CSV con Redis caido', function (): void {
    $empleado = portalSinRedisEmpleado();
    RedisOutage::begin();

    Api::as($empleado['token'])
        ->get('/api/v1/me/export?format=csv&from=2026-03-01&to=2026-03-15')
        ->assertOk();
})->group('RL-05', 'RL-03');

it('responde 503 al PDF con Redis caido, remitiendo al CSV y sin nombrar la dependencia', function (): void {
    $empleado = portalSinRedisEmpleado();
    RedisOutage::begin();

    // El embudo real delante de un motor de mentira, sobre el Redis caido.
    app()->instance(ReportDocumentRenderer::class, new ConcurrencyLimitedReportRenderer(
        new FakeReportDocumentRenderer,
        app(Redis::class),
        app(LoggerInterface::class),
        app(PdfRenderingMetrics::class),
        2,
        0,
    ));

    $respuesta = Api::as($empleado['token'])
        ->get('/api/v1/me/export?format=pdf&from=2026-03-01&to=2026-03-15');

    $respuesta->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'urn:kronoqr:problem:service-unavailable');

    expect($respuesta->json('detail'))->toContain('CSV')
        ->and(strtolower((string) $respuesta->getContent()))->not->toContain('redis');
})->group('RL-05', 'RF-IN-04');

it('sigue bloqueando al empleado tras sus fallos, contados en disco', function (): void {
    $empleado = portalSinRedisEmpleado();
    RedisOutage::begin();

    for ($i = 0; $i < 3; $i++) {
        Api::guest()->post('/api/v1/me/login', ['employee_code' => $empleado['code'], 'pin' => '999111'])
            ->assertStatus(401);
    }

    // Con el PIN correcto, el bloqueo sigue: el contador vive en el disco.
    Api::guest()->post('/api/v1/me/login', ['employee_code' => $empleado['code'], 'pin' => PortalLogins::PIN])
        ->assertStatus(401);
})->group('RS-12', 'RF-ID-08');

it('sigue cerrando el portal al origen tras sus fallos, contados en disco', function (): void {
    $empleado = portalSinRedisEmpleado();
    RedisOutage::begin();

    for ($i = 0; $i < 4; $i++) {
        Api::guest()->fromIp(PORTAL_WITHOUT_REDIS_IP)->post('/api/v1/me/login', [
            'employee_code' => 'NOEXISTE'.$i,
            'pin' => '000999',
        ])->assertStatus(401);
    }

    Api::guest()->fromIp(PORTAL_WITHOUT_REDIS_IP)->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])
        ->assertStatus(429)
        ->assertJsonPath('type', 'urn:kronoqr:problem:portal-origin-locked');

    expect(DB::table('audit_log')->where('action', 'auth.origin_locked')->count())->toBe(1);
})->group('RS-12', 'RF-ID-08');

it('da el mismo 401 para las cinco causas de rechazo con Redis caido', function (): void {
    // El bloqueo por origen tiene su propia prueba: aqui no debe adelantarse.
    config()->set('identity.portal.origin_lockout.max_failures', 50);

    $activo = portalSinRedisEmpleado('Hotel A');
    $bloqueado = portalSinRedisEmpleado('Hotel B');
    $suspendido = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel C'), null, 'suspended');
    EmployeePins::issue($suspendido, PortalLogins::PIN);
    $sinPin = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel D'));

    RedisOutage::begin();

    for ($i = 0; $i < 3; $i++) {
        Api::guest()->post('/api/v1/me/login', ['employee_code' => $bloqueado['code'], 'pin' => '999111']);
    }

    $causas = [
        'PIN incorrecto' => ['employee_code' => $activo['code'], 'pin' => '999111'],
        'codigo inexistente' => ['employee_code' => 'ENOEXISTE1', 'pin' => PortalLogins::PIN],
        'no en alta' => ['employee_code' => EmployeePins::codeOf($suspendido), 'pin' => PortalLogins::PIN],
        'sin PIN emitido' => ['employee_code' => EmployeePins::codeOf($sinPin), 'pin' => PortalLogins::PIN],
        'bloqueado' => ['employee_code' => $bloqueado['code'], 'pin' => PortalLogins::PIN],
    ];

    $cuerpos = [];

    foreach ($causas as $causa => $credenciales) {
        $respuesta = Api::guest()->post('/api/v1/me/login', $credenciales);

        expect($respuesta->getStatusCode())->toBe(401, $causa);
        $cuerpos[$causa] = $respuesta->getContent();
    }

    expect(array_unique($cuerpos))->toHaveCount(1);
})->group('RS-03', 'RS-12', 'RF-ID-06');
