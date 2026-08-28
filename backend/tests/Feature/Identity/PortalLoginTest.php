<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Spectator\Spectator;
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

it('no deja entrar a quien no esta en alta, y con el mismo rechazo', function (string $status): void {
    // RN-14. Una persona de baja conserva su historial —nada se borra, regla
    // dura 5— pero no entra al portal, y no lo hace **por el mismo camino y con
    // el mismo valor** que un codigo inexistente: el estado de una persona no
    // puede deducirse desde fuera.
    $empleado = empleadoConPortal($status);

    Api::guest()->post('/api/v1/me/login', [
        'employee_code' => $empleado['code'],
        'pin' => PortalLogins::PIN,
    ])
        ->assertValidResponse(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:invalid-credentials');
})->with([
    'suspendido' => 'suspended',
    'de baja' => 'terminated',
])->group('RN-14', 'RS-03');

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
