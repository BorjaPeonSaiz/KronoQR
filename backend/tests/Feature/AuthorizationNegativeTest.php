<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\FakeCardRenderer;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Regla dura 18 y RQ-07: **cada endpoint tiene su prueba de autorizacion
 * negativa, por cada rol no autorizado**, sin excepciones.
 *
 * Se prueba por PAREJAS (rol x endpoint) y no con un caso representativo: una
 * policy olvidada en un solo endpoint es indistinguible de una bien puesta hasta
 * que alguien la encuentra, y lo que se encuentra en un sistema de fichaje son
 * datos de la plantilla.
 *
 * Dos controles distintos se comprueban aqui a la vez (doc 02 §7.3):
 *
 *   - **El ambito del token**, que verifica el middleware `ability`. Es lo que
 *     deja fuera al token de quiosco y al auditor: no llevan `employees:*`.
 *   - **La policy del recurso**, que verifica el rol. Es lo que dejaria fuera a
 *     un rol con ambito pero sin permiso sobre esos datos.
 *
 * Los dos devuelven 403 a proposito: desde fuera no se distingue por que se ha
 * denegado, que es lo correcto.
 */

uses(RefreshDatabase::class);

/**
 * Los endpoints de gestion de esta tarea, con un cuerpo valido para que lo que
 * falle sea la autorizacion y no la validacion.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function managementEndpoints(): array
{
    return [
        'listar empleados' => ['GET', '/api/v1/employees', []],
        'ver un empleado' => ['GET', '/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', []],
        'dar de alta' => ['POST', '/api/v1/employees', [
            'first_name' => 'Youssef',
            'last_name' => 'Amrani',
            'hired_at' => '2026-08-14',
        ]],
        'modificar un empleado' => ['PATCH', '/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', [
            'first_name' => 'Lucia',
        ]],
        'dar de baja' => ['POST', '/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/offboard', [
            'terminated_at' => '2026-09-30',
        ]],
        'listar departamentos' => ['GET', '/api/v1/departments', []],
        'ver un departamento' => ['GET', '/api/v1/departments/1', []],
        'crear un departamento' => ['POST', '/api/v1/departments', ['name' => 'Recepcion']],
        'renombrar un departamento' => ['PATCH', '/api/v1/departments/1', ['name' => 'Recepcion']],
        // El centro es un recurso singular (ADR-040): ni lista ni alta.
        'ver el centro' => ['GET', '/api/v1/site', []],
        'modificar el centro' => ['PATCH', '/api/v1/site', ['name' => 'Hotel Marina']],

        // Credenciales (tarea 1.5). Su ambito es `credentials:*` y no
        // `employees:*`: emitir una tarjeta y corregir un apellido son dos
        // potestades distintas del §7.3, y compartir ambito significaria que
        // quien puede lo segundo puede tambien emitir una tarjeta a nombre de
        // cualquiera.
        'emitir una credencial' => ['POST', '/api/v1/credentials', [
            'employee_uuid' => '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        ]],
        'revocar una credencial' => ['POST', '/api/v1/credentials/0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c01/revoke', [
            'reason' => 'Perdida',
        ]],

        // Impresion y entrega (tarea 1.10, ADR-034). Comparten el ambito
        // `credentials:*` con los dos de arriba, y aun asi entran uno a uno en la
        // matriz: la policy se declara en el `FormRequest` de cada endpoint, asi
        // que un `authorize()` que devolviera `true` en uno solo de los cuatro
        // seria invisible desde los otros tres.
        //
        // `credentials/status` es el que mas datos reparte de toda la API —nombre,
        // codigo, centro y departamento de la plantilla entera— y hasta ahora era
        // el unico endpoint de gestion sin ninguna pareja rol x endpoint probada.
        'ver el panel de credenciales' => ['GET', '/api/v1/credentials/status', []],
        'imprimir el lote pendiente' => ['POST', '/api/v1/credentials/print-batch', []],
        'imprimir una credencial' => ['POST', '/api/v1/credentials/0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c01/print', []],
        'registrar la entrega' => ['POST', '/api/v1/credentials/0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c01/deliver', []],

        // PIN de respaldo (tarea 1.13, RF-ID-09). Ambito `employees:*` y policy
        // propia: restablecer el PIN de otra persona es entregarle la llave de su
        // portal y de su fichaje sin tarjeta.
        'restablecer el PIN' => ['POST', '/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/pin/reset', []],
        'registrar la entrega del PIN' => ['POST', '/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/pin/deliver', []],
    ];
}

it('deniega a un token de quiosco cualquier endpoint de gestion', function (string $method, string $uri, array $body): void {
    // RS-04 y §7.3: «un token de quiosco comprometido NO da acceso a la
    // plantilla completa». Es el caso que mas importa de todo este fichero: el
    // token vive en una tablet compartida colgada en una pared.
    Api::as(ManagementUsers::kioskToken())->call($method, $uri, $body)->assertStatus(403);
})->with(managementEndpoints())->group('RS-04', 'RQ-07', 'RF-ID-04');

it('deniega a un auditor escribir o leer plantilla', function (string $method, string $uri, array $body): void {
    // El auditor es de solo lectura y su ambito es `attendance:read`,
    // `audit:read` y `reports:legal` (§7.3): la plantilla no esta ahi.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR));

    Api::as($token)->call($method, $uri, $body)->assertStatus(403);
})->with(managementEndpoints())->group('RQ-07', 'RF-ID-02');

/**
 * Los endpoints de gestion que un `responsable_departamento` **sigue** sin poder
 * tocar despues de la tarea 2.1.
 *
 * Es la matriz de arriba menos las dos rutas de lectura de plantilla —`GET
 * /employees` y `GET /employees/{uuid}`—, que RF-ID-03 le concede **acotadas a su
 * departamento**. Su alcance, y el `403` cuando se sale de el, se prueban en
 * `Tests\Feature\Identity\DepartmentScopeTest`: aqui no caben, porque lo que esta
 * matriz comprueba es el par rol x endpoint y aquello depende de a quien se pida.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function endpointsDeniedToDepartmentManager(): array
{
    $endpoints = managementEndpoints();

    unset($endpoints['listar empleados'], $endpoints['ver un empleado']);

    return $endpoints;
}

it('deniega a un responsable de departamento todo lo que no sea leer su plantilla', function (string $method, string $uri, array $body): void {
    // Tras la tarea 2.1, el responsable lee la plantilla **de su departamento** y
    // nada mas: lleva `employees:read` y no `employees:*` ni `credentials:*`.
    // Escribir, dar de baja, tocar el PIN o las credenciales sigue siendo `403`,
    // y el alcance de lo que si puede leer lo comprueba `DepartmentScopeTest`.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO));

    Api::as($token)->call($method, $uri, $body)->assertStatus(403);
})->with(endpointsDeniedToDepartmentManager())->group('RQ-07', 'RF-ID-03');

it('deniega a una cuenta con rol de empleado el acceso al panel', function (string $method, string $uri, array $body): void {
    // El empleado consulta lo suyo en el portal, con ambito `self:read`
    // (RF-ID-07). Nunca datos de terceros.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::EMPLEADO));

    Api::as($token)->call($method, $uri, $body)->assertStatus(403);
})->with(managementEndpoints())->group('RQ-07', 'RF-ID-07');

it('deniega el acceso sin token', function (string $method, string $uri, array $body): void {
    Api::guest()->call($method, $uri, $body)
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->with(managementEndpoints())->group('RQ-07');

it('deniega el acceso con un token que ya no vale', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $token = ManagementUsers::tokenFor($user);

    Api::as($token)->post('/api/v1/auth/logout')->assertStatus(204);

    // Artefacto de la suite, no del producto: las llamadas de una misma prueba
    // comparten aplicacion y el guard de Sanctum cachea el usuario que ya
    // resolvio. En produccion cada peticion arranca su propia aplicacion.
    Auth::forgetGuards();

    Api::as($token)->get('/api/v1/employees')->assertStatus(401);
})->group('RQ-07', 'RF-ID-01');

it('deja pasar a un responsable a leer plantilla, que es el control positivo de RF-ID-03', function (): void {
    // Sin esto, los `403` de arriba pasarian igual si al responsable se le hubiera
    // negado la plantilla por completo, que es como estaba antes de la tarea 2.1.
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO));

    // Sin departamento asignado no alcanza a nadie, y aun asi el endpoint le
    // responde: la lista vacia es la respuesta correcta, no un `403`.
    Api::as($token)->get('/api/v1/employees')->assertStatus(200);
})->group('RQ-07', 'RF-ID-03');

it('deja pasar a RRHH y al administrador, que es lo que hace significativo el resto', function (UserRole $role): void {
    // Sin este caso, todas las pruebas de arriba pasarian igual con la API
    // apagada. Es el control positivo.
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($role));

    Api::as($token)->get('/api/v1/employees')->assertStatus(200);

    Api::as($token)->post('/api/v1/employees', [
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'hired_at' => '2026-08-14',
    ])->assertStatus(201);
})->with([
    'rrhh' => UserRole::RRHH,
    'admin' => UserRole::ADMIN,
])->group('RQ-07', 'RF-ID-02');

it('deja pasar a RRHH a emitir credenciales, que es el control positivo del ambito credentials', function (): void {
    // El mismo papel que el caso de arriba: sin el, las pruebas de denegacion
    // sobre `/credentials` pasarian igual si la ruta no existiera.
    $site = WorkforceFixtures::site();
    $employee = WorkforceFixtures::employee($site);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)
        ->post('/api/v1/credentials', ['employee_uuid' => $employee])
        ->assertStatus(201);
})->group('RQ-07', 'RF-QR-01', 'RS-04');

it('deja pasar a RRHH a imprimir, entregar y ver el panel, que es el control positivo de la 1.10', function (): void {
    // El control positivo de las cuatro parejas nuevas de la matriz. Sin el, los
    // cuarenta `403` de arriba sobre `/print`, `/print-batch`, `/deliver` y
    // `/status` pasarian identicos si esas cuatro rutas estuvieran rotas: un
    // endpoint que revienta y uno que deniega se parecen mucho desde una prueba
    // que solo mira que no sea 200.
    // Sin Chromium: el puerto `CardRenderer` existe para que la impresion se
    // pueda probar sin arrancar un navegador (ver Tests\Support\Identity\
    // FakeCardRenderer). Lo que se ejercita sigue siendo todo lo demas.
    app()->instance(CardRenderer::class, new FakeCardRenderer);

    $site = WorkforceFixtures::site();
    $employee = WorkforceFixtures::employee($site);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $uuid = Api::as($token)
        ->post('/api/v1/credentials', ['employee_uuid' => $employee])
        ->assertStatus(201)
        ->json('uuid');

    expect($uuid)->toBeString();

    $credential = is_string($uuid) ? $uuid : '';

    Api::as($token)->post('/api/v1/credentials/'.$credential.'/print')
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');

    Api::as($token)->post('/api/v1/credentials/'.$credential.'/deliver')
        ->assertStatus(200);

    // Ya no queda nada pendiente de imprimir, y el lote lo dice con `204`.
    Api::as($token)->post('/api/v1/credentials/print-batch')->assertStatus(204);

    Api::as($token)->get('/api/v1/credentials/status')->assertStatus(200);
})->group('RQ-07', 'RF-QR-06', 'RF-QR-08');

it('deja pasar a RRHH a restablecer y entregar el PIN, control positivo de la 1.13', function (): void {
    // Lo mismo para las dos parejas nuevas de `/pin/*`.
    $site = WorkforceFixtures::site();
    $employee = WorkforceFixtures::employee($site);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->post('/api/v1/employees/'.$employee.'/pin/reset')->assertStatus(200);
    Api::as($token)->post('/api/v1/employees/'.$employee.'/pin/deliver')->assertStatus(200);
})->group('RQ-07', 'RF-ID-09');

it('deniega a un token de quiosco emitir o revocar credenciales', function (string $uri, array $body): void {
    // RS-04 con nombre y apellidos. El token del quiosco lleva `scan:write`,
    // `roster:read` y `heartbeat:write` (§7.3): ninguno de los tres abre esta
    // puerta. Si la abriera, quien robase la tablet de la puerta de servicio
    // podria fabricarse una tarjeta a nombre de cualquiera.
    Api::as(ManagementUsers::kioskToken())->post($uri, $body)->assertStatus(403);
})->with([
    'emitir' => ['/api/v1/credentials', ['employee_uuid' => '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90']],
    'revocar' => ['/api/v1/credentials/0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c01/revoke', ['reason' => 'Perdida']],
])->group('RS-04', 'RF-QR-01', 'RF-QR-03');
