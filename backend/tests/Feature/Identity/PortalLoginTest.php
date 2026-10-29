<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `POST /api/v1/me/login` de punta a punta y contra el contrato (RF-ID-05,
 * RF-ID-06, RL-05, tarea 1.11).
 *
 * Cubre el Gherkin del doc 01 §11:
 *
 *     Escenario: El empleado consulta su propio registro
 *       Dado un empleado con su codigo y su PIN
 *       Cuando accede al portal personal desde la red interna
 *       ...
 *
 * Lo que estas pruebas defienden ademas de la forma de la respuesta:
 *
 *   - **Regla dura 17 y RS-03**: los cinco rechazos son indistinguibles desde
 *     fuera, y el bloqueo tampoco se anuncia.
 *   - **RF-ID-07**: el token que sale lleva `self:read` y nada mas.
 *   - **Regla dura 12 y ADR-015**: se entra con codigo y PIN, sin correo.
 *   - **Regla dura 21**: el PIN no aparece en ningun log, y el codigo de
 *     empleado tampoco.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Un empleado con su PIN emitido, listo para entrar.
 *
 * @return array{uuid: string, code: string}
 */
function empleadoConPortal(string $status = 'active'): array
{
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel del portal'), null, $status);

    EmployeePins::issue($uuid, PortalLogins::PIN);

    return ['uuid' => $uuid, 'code' => EmployeePins::codeOf($uuid)];
}

it('abre sesion con codigo de empleado y PIN', function (): void {
    $empleado = empleadoConPortal();

    $respuesta = Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ]);

    $respuesta->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('employee.uuid', $empleado['uuid'])
        ->assertJsonPath('employee.employee_code', $empleado['code'])
        // La zona del CENTRO, que es la que decide que dia es hoy para esa
        // persona (RN-04, regla dura 3). Sin ella el portal la adivinaria con la
        // del navegador.
        ->assertJsonPath('employee.time_zone', 'Europe/Madrid')
        ->assertJsonPath('employee.locale', 'es');

    // Y ni el PIN ni su hash vuelven por ningun sitio (regla dura 21).
    expect($respuesta->getContent())->not->toContain(PortalLogins::PIN);
})->group('RF-ID-05', 'RF-ID-06', 'RL-05');

it('entrega la sesion con no-store, porque el cuerpo lleva el token', function (): void {
    // 2.3.0, bloque 8. El portal se abre en el ordenador compartido de la sala
    // de personal: con el `no-cache, private` por omision, el navegador podia
    // guardar en disco una respuesta con un token vivo y el nombre de quien
    // acababa de entrar. `assertValidResponse` comprueba que el contrato la
    // declara.
    $empleado = empleadoConPortal();

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])
        ->assertValidResponse(200)
        ->assertHeader('Cache-Control', 'no-store, private');
})->group('RF-ID-06', 'RS-09');

it('emite un token con self:read y con ningun ambito mas', function (): void {
    // RF-ID-07, y es la mitad de la promesa que hace tolerable un PIN de seis
    // digitos: aunque alguien se lleve este token, no alcanza nada de gestion.
    $empleado = empleadoConPortal();

    $token = PortalLogins::tokenFor($empleado['code']);
    $fila = PersonalAccessToken::findToken($token);

    expect($fila)->toBeInstanceOf(PersonalAccessToken::class);

    /** @var PersonalAccessToken $fila */
    expect($fila->abilities)->toBe([TokenAbility::SELF_READ->value])
        // El `tokenable` es la persona, no una cuenta de gestion espejo
        // (ADR-015, regla dura 12).
        ->and($fila->tokenable?->getTable())->toBe('employees')
        // Y caduca: una sesion de portal sin caducidad seria un acceso
        // permanente al registro horario desde un movil personal.
        ->and($fila->expires_at)->not->toBeNull();
})->group('RF-ID-07', 'RS-04');

it('no distingue un codigo inexistente de un PIN incorrecto', function (array $credenciales): void {
    // Regla dura 17 y RS-03. Las dos respuestas tienen que ser la MISMA: mismo
    // codigo de estado, mismo `type` y mismo cuerpo. Si se separaran, bastaria
    // con mirar el estado para saber que codigos de empleado existen — y el
    // codigo va impreso en una tarjeta que la gente lleva colgada del cuello.
    $empleado = empleadoConPortal();

    $respuesta = Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $credenciales['code'] ?? $empleado['code'],
        'pin' => $credenciales['pin'],
    ]);

    $respuesta->assertValidRequest()
        ->assertValidResponse(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:invalid-credentials')
        ->assertJsonPath('detail', 'El codigo de empleado o el PIN no son correctos.');
})->with([
    'PIN incorrecto' => [['pin' => '999111']],
    'codigo inexistente' => [['code' => 'ENOEXISTE1', 'pin' => PortalLogins::PIN]],
])->group('RS-03', 'RF-ID-06');

it('no deja entrar a una persona de baja, y con el mismo rechazo que un codigo inexistente', function (): void {
    // RN-14. Una persona de baja conserva su historial —nada se borra, regla
    // dura 5— pero no entra al portal, y no lo hace **por el mismo camino y con
    // el mismo valor** que un codigo inexistente: el estado de una persona no
    // puede deducirse desde fuera (RS-03).
    $empleado = empleadoConPortal('terminated');

    $deBaja = Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ]);

    $deBaja->assertValidResponse(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:invalid-credentials')
        ->assertJsonPath('detail', 'El codigo de empleado o el PIN no son correctos.');

    $inexistente = Api::guest()->post('/api/v1/me/login', [
        'employee_code' => 'ENOEXISTE1',
        'pin' => PortalLogins::PIN,
    ]);

    expect($deBaja->getContent())->toBe($inexistente->getContent());
})->group('RN-25', 'RN-14', 'RS-03');

it('deja entrar a la persona suspendida, solo para consultar su registro', function (): void {
    // RN-25, decision del propietario del 27-10-2026: la suspension impide
    // fichar, no consultar el propio registro (RL-05). Entra con su codigo y
    // su PIN de siempre, con `self:read` y nada mas (RF-ID-07).
    $empleado = empleadoConPortal('suspended');

    $respuesta = Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ]);

    $respuesta->assertValidRequest()->assertValidResponse(200);

    $token = $respuesta->json('token');

    expect($token)->toBeString();

    /** @var string $token */
    $fila = PersonalAccessToken::findToken($token);

    expect($fila?->abilities)->toBe(['self:read']);

    Api::as($token)->get('/api/v1/me/workdays')->assertStatus(200);
})->group('RN-25', 'RL-05', 'RF-ID-05', 'RF-ID-07');

it('rechaza a la persona suspendida con el PIN erroneo igual que a cualquiera', function (): void {
    // Que la suspendida entre no abre ningun oraculo: con el PIN equivocado
    // recibe el mismo rechazo generico que un codigo inexistente (RS-03).
    $empleado = empleadoConPortal('suspended');

    $suspendida = Api::guest()->post('/api/v1/me/login', ['employee_code' => $empleado['code'], 'pin' => '999111']);
    $inexistente = Api::guest()->post('/api/v1/me/login', ['employee_code' => 'ENOEXISTE1', 'pin' => '999111']);

    $suspendida->assertValidResponse(401)->assertJsonPath('type', 'urn:kronoqr:problem:invalid-credentials');

    expect($suspendida->getContent())->toBe($inexistente->getContent());
})->group('RN-25', 'RS-03');

/**
 * Lo que un rechazo del portal deja ver desde fuera: estado, cuerpo entero y
 * cabeceras.
 *
 * Se apartan solo las dos cabeceras que cambian de una peticion a la siguiente
 * por motivos ajenos a la causa: `Date` y el contador `X-RateLimit-Remaining`,
 * que baja uno con cada intento. Todo lo demas —nombres y valores— tiene que
 * coincidir.
 *
 * @param  TestResponse<Response>  $response
 * @return array{status: int, body: string, headers: array<string, list<string|null>>}
 */
function portalRejectionFingerprint(TestResponse $response): array
{
    $headers = $response->headers->all();
    unset($headers['date'], $headers['x-ratelimit-remaining']);
    ksort($headers);

    return [
        'status' => $response->getStatusCode(),
        'body' => (string) $response->getContent(),
        'headers' => $headers,
    ];
}

it('responde a cada causa de rechazo lo mismo que a un codigo inexistente, byte a byte y cabecera a cabecera', function (Closure $credenciales): void {
    // A7 de la 2.1.0: las pruebas de arriba comparan `type` y `detail`; esta
    // compara TODO lo que ve quien intenta entrar. Regla dura 17 y RS-03: si
    // una sola cabecera o un solo byte distinguieran una causa, bastaria con
    // mirarlo para saber que codigos existen, quien esta de baja o a quien se
    // ha bloqueado.
    config()->set('identity.portal.rate_limit_per_minute', 10_000);
    config()->set('identity.pin.max_attempts', 3);

    /** @var array{code: string, pin: string} $intento */
    $intento = $credenciales();

    $causa = Api::guest()->post('/api/v1/me/login', ['employee_code' => $intento['code'], 'pin' => $intento['pin']]);
    $inexistente = Api::guest()->post('/api/v1/me/login', ['employee_code' => 'ENOEXISTE1', 'pin' => PortalLogins::PIN]);

    expect($causa->getStatusCode())->toBe(401)
        ->and(portalRejectionFingerprint($causa))->toBe(portalRejectionFingerprint($inexistente));
})->with([
    'PIN incorrecto' => [static fn (): array => ['code' => empleadoConPortal()['code'], 'pin' => '999111']],
    'de baja con su PIN' => [static fn (): array => ['code' => empleadoConPortal('terminated')['code'], 'pin' => PortalLogins::PIN]],
    'suspendida con un PIN incorrecto' => [static fn (): array => ['code' => empleadoConPortal('suspended')['code'], 'pin' => '999111']],
    'sin PIN emitido' => [static fn (): array => [
        'code' => EmployeePins::codeOf(WorkforceFixtures::employee(WorkforceFixtures::site('Hotel sin PIN'))),
        'pin' => PortalLogins::PIN,
    ]],
    'bloqueada, con su PIN' => [static function (): array {
        $empleado = empleadoConPortal();
        Api::guest()->post('/api/v1/me/login', ['employee_code' => $empleado['code'], 'pin' => '999111'])->assertStatus(401);
        Api::guest()->post('/api/v1/me/login', ['employee_code' => $empleado['code'], 'pin' => '999112'])->assertStatus(401);
        Api::guest()->post('/api/v1/me/login', ['employee_code' => $empleado['code'], 'pin' => '999113'])->assertStatus(401);

        return ['code' => $empleado['code'], 'pin' => PortalLogins::PIN];
    }],
])->group('RS-03', 'RS-12', 'RF-ID-06', 'RN-14', 'RN-25');

it('no deja entrar a quien no tiene PIN emitido', function (): void {
    // El alta de un empleado no emite PIN: lo hace RRHH en un acto propio
    // (RF-ID-09, tarea 1.13). Hasta entonces, su portal responde como si el
    // codigo no existiera.
    $uuid = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel sin PIN'));

    $codigo = EmployeePins::codeOf($uuid);

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $codigo,
        'pin' => PortalLogins::PIN,
    ])
        ->assertValidResponse(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:invalid-credentials');
})->group('RS-03', 'RF-ID-09');

it('rechaza la peticion mal formada con 400 y no con 401', function (array $cuerpo): void {
    // El `401` esta reservado a «no entras». Compartirlo con «te falta un campo»
    // dejaria al portal sin poder distinguir «corrige el formulario» de «vuelve
    // a intentarlo», y ademas un error por campo diria CUAL de los dos falla.
    Api::guest()->post('/api/v1/me/login', $cuerpo)
        ->assertStatus(400)
        ->assertJsonPath('type', 'urn:kronoqr:problem:invalid-request');
})->with([
    'sin PIN' => [['employee_code' => 'E1234567X']],
    'sin codigo' => [['pin' => PortalLogins::PIN]],
    'PIN de cinco digitos' => [['employee_code' => 'E1234567X', 'pin' => '12345']],
    'PIN con letras' => [['employee_code' => 'E1234567X', 'pin' => 'abc123']],
    'campo desconocido' => [['employee_code' => 'E1234567X', 'pin' => '123456', 'remember' => true]],
])->group('RF-ID-06', 'RQ-06');

it('no escribe el PIN ni el codigo de empleado en el log del acceso', function (): void {
    // Regla dura 21. El log tecnico viaja al fabricante dentro del paquete de
    // diagnostico (ADR-020): si lleva el codigo de empleado, se ha publicado
    // media credencial; si lleva el PIN, la otra media.
    $empleado = empleadoConPortal();

    $apuntes = [];

    Log::listen(function (MessageLogged $evento) use (&$apuntes): void {
        $apuntes[] = $evento->message.' '.json_encode($evento->context, JSON_THROW_ON_ERROR);
    });

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])->assertStatus(200);

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => '999111',
    ])->assertStatus(401);

    $registrado = implode("\n", $apuntes);

    expect($registrado)->not->toContain(PortalLogins::PIN)
        ->and($registrado)->not->toContain($empleado['code'])
        // Lo que si tiene que estar: el acierto identificado por `employee_uuid`,
        // que es el unico identificador de persona admitido en un log tecnico.
        // Los nombres son los del rastro de autenticacion (ADR-039): el portal
        // dejo de escribir un segundo apunte con otro nombre para el mismo hecho.
        ->and($registrado)->toContain('auth.login_succeeded')
        ->and($registrado)->toContain($empleado['uuid'])
        ->and($registrado)->toContain('auth.login_failed');
})->group('RS-08', 'RF-ID-06');

it('invalida las sesiones abiertas cuando RRHH restablece el PIN', function (): void {
    // RF-ID-09 leido hasta el final. Si restablecer el PIN no cortara la sesion
    // que quedo abierta en el movil perdido, el restablecimiento no serviria
    // para lo unico que de verdad hace falta.
    $empleado = empleadoConPortal();
    $token = PortalLogins::tokenFor($empleado['code']);

    Api::as($token)->get('/api/v1/me/workdays')->assertStatus(200);

    // La sesion se abrio un minuto antes del restablecimiento. Se escribe asi
    // —moviendo hacia atras el `created_at` del token— y no adelantando
    // `pin_issued_at`, porque un PIN emitido en el futuro invalidaria tambien la
    // sesion que se abriera despues, y entonces la prueba pasaria por el motivo
    // equivocado.
    $fila = PersonalAccessToken::findToken($token);

    expect($fila)->toBeInstanceOf(PersonalAccessToken::class);

    /** @var PersonalAccessToken $fila */
    $fila->forceFill(['created_at' => now()->subMinute()])->save();

    // Un PIN nuevo, emitido ahora. `pin_issued_at` avanza y con el se cae todo
    // lo anterior, en la peticion siguiente y no cuando caduque el token.
    EmployeePins::issue($empleado['uuid'], '502841');

    Api::as($token)->get('/api/v1/me/workdays')->assertStatus(401);

    // Y con el PIN nuevo se vuelve a entrar en el momento (regla dura 19 en su
    // version de portal: quien pide un PIN tiene que poder usarlo ya).
    Api::as(PortalLogins::tokenFor($empleado['code'], '502841'))
        ->get('/api/v1/me/workdays')
        ->assertStatus(200);
})->group('RF-ID-09', 'RS-12');

it('deja de valer la sesion en cuanto se da de baja a la persona', function (): void {
    // RN-14 comprobada en cada peticion, no solo al emitir el token. Sin esto,
    // alguien dado de baja seguiria leyendo su registro hasta que expirara la
    // sesion, que es la misma laguna que el producto cierra para los quioscos.
    $empleado = empleadoConPortal();
    $token = PortalLogins::tokenFor($empleado['code']);

    Api::as($token)->get('/api/v1/me/workdays')->assertStatus(200);

    WorkforceFixtures::terminate($empleado['uuid']);

    Api::as($token)->get('/api/v1/me/workdays')->assertStatus(401);
})->group('RN-14', 'RF-ID-07');

it('mantiene la sesion abierta cuando se suspende a la persona', function (): void {
    // RN-25: suspender no es dar de baja. La sesion sigue valiendo para leer su
    // registro mientras dura la suspension (RL-05); lo que la suspension cierra
    // es el fichaje.
    $empleado = empleadoConPortal();
    $token = PortalLogins::tokenFor($empleado['code']);

    DB::table('employees')->where('uuid', $empleado['uuid'])->update(['status' => 'suspended']);

    Api::as($token)->get('/api/v1/me/workdays')->assertStatus(200);
})->group('RN-25', 'RL-05', 'RF-ID-07');

it('no deja una linea ERROR por un rechazo y apunta el acceso con employee_uuid (V4-BE-2, R4-BE-04)', function (): void {
    $empleado = empleadoConPortal();

    /** @var list<array{level: string, message: string, context: array<mixed>}> $apuntes */
    $apuntes = [];
    Log::listen(function (MessageLogged $evento) use (&$apuntes): void {
        $apuntes[] = ['level' => $evento->level, 'message' => $evento->message, 'context' => $evento->context];
    });

    Api::guest()->post('/api/v1/me/login', ['employee_code' => $empleado['code'], 'pin' => '999111'])->assertStatus(401);
    Api::guest()->post('/api/v1/me/login', ['employee_code' => $empleado['code'], 'pin' => PortalLogins::PIN])->assertOk();

    $exito = array_values(array_filter($apuntes, static fn (array $apunte): bool => $apunte['message'] === 'auth.login_succeeded'));

    expect(array_column($apuntes, 'level'))->not->toContain('error')
        ->and($exito)->toHaveCount(1)
        ->and($exito[0]['context']['employee_uuid'] ?? null)->toBe($empleado['uuid']);
})->group('RS-03', 'RF-ID-06', 'RF-PD-15');
