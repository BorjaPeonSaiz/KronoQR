<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Autorizacion negativa del asistente de puesta en marcha (RF-PD-03, regla dura
 * 18): **un `403` por cada rol que no debe entrar**, en cada ruta.
 *
 * LAS DOS PREGUNTAS QUE ESTE FICHERO RESPONDE, y que son distintas de las de
 * cualquier otro endpoint del producto:
 *
 *   1. Las dos rutas PUBLICAS del asistente son publicas **por necesidad, no por
 *      descuido**: se llaman cuando no existe ninguna cuenta con la que
 *      autenticarse. Lo que hay que demostrar es que la unica escritura de las
 *      dos se cierra sola y para siempre.
 *   2. Las cuatro rutas AUTENTICADAS son de `admin` y de nadie mas: el asistente
 *      decide la zona horaria con la que se atribuyen las jornadas (RN-05) y el
 *      perfil con el que se calcula el cumplimiento (RL-21).
 *
 * `GET /setup/steps` es la cuarta, y es una LECTURA que tambien se cierra: la
 * lista de pasos dice si hay un administrador sin segundo factor, si no hay
 * licencia y si no hay ningun quiosco. Es un inventario de la postura de la
 * instalacion, y por eso no viaja en la ruta publica.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Un token con los ambitos de ese rol, tal como los emitiria `/auth/login`.
 */
function tokenForRole(UserRole $role): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole($role));
}

// -----------------------------------------------------------------------------
// Sin credenciales
// -----------------------------------------------------------------------------

it('no deja tocar el asistente sin token', function (): void {
    Api::guest()->get('/api/v1/setup/steps')->assertValidResponse(401);
    Api::guest()->call('PUT', '/api/v1/setup/steps/license', ['state' => 'skipped'])->assertValidResponse(401);
    Api::guest()->post('/api/v1/setup/complete')->assertValidResponse(401);
    Api::guest()->post('/api/v1/setup/site', ['name' => 'Hotel Marina', 'timezone' => 'Europe/Madrid'])
        ->assertValidResponse(401);
})->group('RF-PD-03', 'RS-04');

it('no publica el inventario de pasos en la ruta sin autenticar', function (): void {
    // La clave viaja AUSENTE, no vacia: un array vacio significa «el asistente
    // esta cerrado y no queda nada que enumerar», y usarlo tambien para «no
    // tienes permiso» haria indistinguibles dos estados que el panel trata
    // distinto.
    $response = Api::guest()->get('/api/v1/setup/status')->assertValidResponse(200);

    expect($response->json())->toHaveKey('available')
        ->and($response->json())->not->toHaveKey('steps');
})->group('RF-PD-03', 'RS-04');

it('si lo sirve a un administrador por su ruta autenticada', function (): void {
    // La otra mitad: cerrar la lectura publica no puede dejar al panel sin poder
    // pintar el asistente, que es lo unico para lo que existe.
    $response = Api::as(tokenForRole(UserRole::ADMIN))
        ->get('/api/v1/setup/steps')
        ->assertValidRequest()
        ->assertValidResponse(200);

    /** @var array<int, array<string, mixed>> $steps */
    $steps = $response->json('steps');

    expect($steps)->toHaveCount(8)
        ->and(array_column($steps, 'step'))->toContain('license', 'kiosk');
})->group('RF-PD-03');

it('no deja crear un segundo administrador sin autenticacion', function (): void {
    // La mitad de la regla dura 18 que mas importa en este endpoint: la puerta
    // publica se cierra sola y **no se puede volver a abrir** sin sesion.
    Api::guest()->post('/api/v1/setup/administrator', [
        'name' => 'Primero',
        'email' => 'primero@hotel.example',
        'password' => 'Una-Contrasena-Larga-1!',
    ])->assertValidResponse(201);

    Api::guest()->post('/api/v1/setup/administrator', [
        'name' => 'Segundo',
        'email' => 'segundo@hotel.example',
        'password' => 'Otra-Contrasena-Larga-2!',
    ])->assertValidResponse(409);

    // Y un token de un rol cualquiera tampoco la reabre: la guarda no es de
    // permisos, es de estado.
    Api::as(tokenForRole(UserRole::ADMIN))->post('/api/v1/setup/administrator', [
        'name' => 'Tercero',
        'email' => 'tercero@hotel.example',
        'password' => 'Otra-Contrasena-Larga-3!',
    ])->assertValidResponse(409);

    expect(DB::table('users')->count())->toBe(2);
})->group('RF-PD-03', 'RS-04');

// -----------------------------------------------------------------------------
// Rol a rol
// -----------------------------------------------------------------------------

it('no deja marcar pasos del asistente a quien no es admin', function (UserRole $role): void {
    // `rrhh` y `auditor` llegan al middleware con token valido y sin
    // `settings:*`: se quedan en el ambito. El responsable de departamento
    // tampoco lo lleva. Los tres son 403 por el mismo motivo de fondo —quien
    // gestiona la plantilla no decide con que umbrales se mide su jornada— y por
    // dos controles distintos (doc 02 §7.3 + policy).
    Api::as(tokenForRole($role))
        ->call('PUT', '/api/v1/setup/steps/license', ['state' => 'skipped'])
        ->assertValidResponse(403);
})->with([
    'rrhh' => UserRole::RRHH,
    'auditor' => UserRole::AUDITOR,
    'responsable_departamento' => UserRole::RESPONSABLE_DEPARTAMENTO,
])->group('RF-PD-03', 'RS-04');

it('no deja consultar los pasos del asistente a quien no es admin', function (UserRole $role): void {
    // Es una LECTURA y aun asi se cierra: la lista dice si hay un administrador
    // sin segundo factor confirmado —una cuenta con acceso total a medio
    // configurar—, si no hay licencia y si no hay ningun quiosco. `rrhh`,
    // `auditor` y el responsable de departamento se quedan en el ambito
    // `settings:*`; `SetupPolicy::view()` es el segundo control.
    Api::as(tokenForRole($role))->get('/api/v1/setup/steps')->assertValidResponse(403);
})->with([
    'rrhh' => UserRole::RRHH,
    'auditor' => UserRole::AUDITOR,
    'responsable_departamento' => UserRole::RESPONSABLE_DEPARTAMENTO,
])->group('RF-PD-03', 'RS-04');

it('no deja cerrar el asistente a quien no es admin', function (UserRole $role): void {
    Api::as(tokenForRole($role))->post('/api/v1/setup/complete')->assertValidResponse(403);
})->with([
    'rrhh' => UserRole::RRHH,
    'auditor' => UserRole::AUDITOR,
    'responsable_departamento' => UserRole::RESPONSABLE_DEPARTAMENTO,
])->group('RF-PD-03', 'RS-04');

it('no deja crear el centro a quien no es admin', function (UserRole $role): void {
    // `rrhh` SI lleva `employees:*` y aun asi no crea el centro: lo para
    // `SitePolicy::create()`. Es la asimetria escrita a proposito —modificar el
    // nombre del hotel es mantenimiento, crear el centro es constituir la
    // instalacion— y sin la policy el ambito solo no la conseguiria.
    Api::as(tokenForRole($role))
        ->post('/api/v1/setup/site', ['name' => 'Hotel Marina', 'timezone' => 'Europe/Madrid'])
        ->assertValidResponse(403);

    expect(DB::table('sites')->count())->toBe(0);
})->with([
    'rrhh' => UserRole::RRHH,
    'auditor' => UserRole::AUDITOR,
    'responsable_departamento' => UserRole::RESPONSABLE_DEPARTAMENTO,
])->group('RF-PD-03', 'RS-04');

it('sigue dejando que rrhh modifique el centro que ya existe', function (): void {
    // La otra mitad de la asimetria: `rrhh` no crea y si mantiene. Sin esta
    // afirmacion, endurecer el alta podria haber cerrado tambien la edicion sin
    // que nadie lo notara.
    WorkforceFixtures::site();

    Api::as(tokenForRole(UserRole::RRHH))
        ->patch('/api/v1/site', ['name' => 'Hotel Marina'])
        ->assertValidResponse(200);
})->group('RF-GP-01');

// -----------------------------------------------------------------------------
// El quiosco
// -----------------------------------------------------------------------------

it('no deja al quiosco acercarse a ninguna ruta del asistente', function (): void {
    // Su token lleva tres ambitos y ninguno es `settings:*` ni `employees:*`, asi
    // que se queda en el middleware. Es la segunda mitad de la regla dura 19: el
    // quiosco no se entera de la configuracion por ningun camino.
    $token = ManagementUsers::kioskToken();

    Api::as($token)->get('/api/v1/setup/steps')->assertValidResponse(403);
    Api::as($token)->call('PUT', '/api/v1/setup/steps/license', ['state' => 'skipped'])->assertValidResponse(403);
    Api::as($token)->post('/api/v1/setup/complete')->assertValidResponse(403);
    Api::as($token)->post('/api/v1/setup/site', ['name' => 'Hotel Marina', 'timezone' => 'Europe/Madrid'])
        ->assertValidResponse(403);
})->group('RF-PD-03', 'RS-04');

it('no deja tocar el asistente con la sesion pendiente de segundo factor', function (): void {
    // El `challenge_token` que devuelve `POST /setup/administrator` lleva un
    // unico ambito, `2fa:pending`, y no abre ninguna pantalla del producto: quien
    // acaba de crear la cuenta **no puede configurar nada** hasta activar su
    // TOTP (RS-06). Sin esto, el segundo factor seria opcional en la practica.
    $user = ManagementUsers::withRole(UserRole::ADMIN);
    $pending = ManagementUsers::pendingTokenFor($user);

    expect(TokenAbility::TWO_FACTOR_PENDING->value)->toBe('2fa:pending');

    Api::as($pending)->get('/api/v1/setup/steps')->assertValidResponse(403);
    Api::as($pending)->call('PUT', '/api/v1/setup/steps/license', ['state' => 'skipped'])->assertValidResponse(403);
    Api::as($pending)->post('/api/v1/setup/complete')->assertValidResponse(403);
    Api::as($pending)->post('/api/v1/setup/site', ['name' => 'Hotel Marina', 'timezone' => 'Europe/Madrid'])
        ->assertValidResponse(403);
})->group('RF-PD-03', 'RS-06');
