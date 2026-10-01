<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Workforce\EmployeePins;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `POST /api/v1/me/logout` — el cierre de la sesion del portal (PO1 de la
 * verificacion de la 2.1.0, RF-ID-05, RF-ID-07, RS-13).
 *
 * Antes de esta ruta, «Salir» solo borraba el token del navegador y el token
 * seguia valiendo hasta su caducidad: en el ordenador compartido de la sala de
 * personal, una sesion abierta para quien se sentara despues. Lo que se prueba
 * aqui es que el token **deja de valer en el servidor**, que solo cae el de esta
 * llamada y que nadie mas que una sesion de portal puede usar la ruta (regla
 * dura 18).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    config()->set('identity.portal.rate_limit_per_minute', 10_000);
});

/**
 * Un empleado con su PIN y dos sesiones de portal abiertas: la del ordenador
 * compartido y la de su movil.
 *
 * @return array{employee: string, shared: string, phone: string}
 */
function portalLogoutDosSesiones(): array
{
    $employee = WorkforceFixtures::employee(WorkforceFixtures::site('Hotel del cierre de sesion'));

    $shared = PortalLogins::open($employee);
    $phone = PortalLogins::tokenFor(EmployeePins::codeOf($employee));

    return ['employee' => $employee, 'shared' => $shared, 'phone' => $phone];
}

it('cierra la sesion del portal en el servidor y el token deja de valer', function (): void {
    $sesiones = portalLogoutDosSesiones();

    Api::as($sesiones['shared'])->get('/api/v1/me/workdays')->assertStatus(200);

    Api::as($sesiones['shared'])
        ->post('/api/v1/me/logout')
        ->assertValidRequest()
        ->assertValidResponse(204);

    expect(PersonalAccessToken::findToken($sesiones['shared']))->toBeNull();

    // Artefacto de la suite, no del producto: el guard de Sanctum cachea el
    // usuario ya resuelto dentro de la misma aplicacion.
    Auth::forgetGuards();

    Api::as($sesiones['shared'])
        ->get('/api/v1/me/workdays')
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->group('RF-ID-05', 'RF-ID-07');

it('revoca solo el token de la llamada y deja viva la otra sesion de la misma persona', function (): void {
    // Cerrar sesion en el ordenador compartido no puede echar a la persona del
    // movil desde el que tambien estaba mirando sus horas.
    $sesiones = portalLogoutDosSesiones();

    Api::as($sesiones['shared'])->post('/api/v1/me/logout')->assertStatus(204);

    Auth::forgetGuards();

    expect(PersonalAccessToken::findToken($sesiones['phone']))->not->toBeNull();

    Api::as($sesiones['phone'])->get('/api/v1/me/workdays')->assertStatus(200);
})->group('RF-ID-05');

it('es idempotente: repetir el cierre con el token ya revocado responde 401 y no rompe nada', function (): void {
    // El contrato lo dice igual que para el panel: `401` es «ya no hay sesion».
    // El cliente lo trata como un cierre correcto.
    $sesiones = portalLogoutDosSesiones();

    Api::as($sesiones['shared'])->post('/api/v1/me/logout')->assertStatus(204);

    Auth::forgetGuards();

    Api::as($sesiones['shared'])
        ->post('/api/v1/me/logout')
        ->assertValidResponse(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');

    expect(PersonalAccessToken::findToken($sesiones['phone']))->not->toBeNull();
})->group('RF-ID-05');

it('no deja cerrar nada sin token', function (): void {
    Api::guest()
        ->post('/api/v1/me/logout')
        ->assertValidResponse(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->group('RF-ID-07', 'RQ-07');

it('deniega el cierre de sesion del portal a cada rol de gestion', function (string $role): void {
    // Regla dura 18, por cada rol. El panel cierra su sesion por
    // `/auth/logout`; aqui su token no tiene `self:read` y ademas no es una
    // persona de la plantilla. Y el token sigue vivo: un 403 que revocara igual
    // seria un cierre de sesion por la puerta equivocada.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::from($role)));

    Api::as($token)
        ->post('/api/v1/me/logout')
        ->assertValidResponse(403);

    expect(PersonalAccessToken::findToken($token))->not->toBeNull();
})->with([
    'admin' => UserRole::ADMIN->value,
    'rrhh' => UserRole::RRHH->value,
    'auditor' => UserRole::AUDITOR->value,
    'responsable de departamento' => UserRole::RESPONSABLE_DEPARTAMENTO->value,
    'empleado' => UserRole::EMPLEADO->value,
])->group('RF-ID-07', 'RQ-07');

it('deniega el cierre de sesion del portal a un quiosco', function (): void {
    // RS-04: el token de una tablet no tiene nada que hacer en `/me/*`.
    $token = ManagementUsers::kioskToken();

    Api::as($token)->post('/api/v1/me/logout')->assertStatus(403);

    expect(PersonalAccessToken::findToken($token))->not->toBeNull();
})->group('RF-ID-07', 'RQ-07', 'RS-04');

it('deniega el cierre a un token de gestion recortado a self:read', function (): void {
    // El caso que separa la policy del ambito: el middleware `ability` lo deja
    // pasar y `PortalSessionPolicy` lo para, porque su `tokenable` es una cuenta
    // de `users` y no una persona de la plantilla. Sin esta prueba, la policy
    // podria borrarse sin que fallara nada.
    $usuaria = ManagementUsers::withRole(UserRole::ADMIN);
    $token = $usuaria->createToken('Sesion con el ambito equivocado', [
        TokenAbility::SELF_READ->value,
    ])->plainTextToken;

    Api::as($token)->post('/api/v1/me/logout')->assertStatus(403);

    expect(PersonalAccessToken::findToken($token))->not->toBeNull();
})->group('RF-ID-07', 'RQ-07');

it('deja la linea auth.logged_out con el uuid del empleado y sin asiento en audit_log', function (): void {
    // RS-13 y ADR-039: el portal no deja asiento de sesion —no hay tipo de actor
    // para un empleado—, pero su cierre si deja rastro en el log tecnico, con el
    // UUID y nunca el nombre (regla dura 21).
    $sesiones = portalLogoutDosSesiones();

    $apuntes = [];

    Log::listen(function (MessageLogged $evento) use (&$apuntes): void {
        $apuntes[] = ['message' => $evento->message, 'context' => $evento->context];
    });

    Api::as($sesiones['shared'])->post('/api/v1/me/logout')->assertStatus(204);

    $cierres = array_values(array_filter(
        $apuntes,
        static fn (array $apunte): bool => $apunte['message'] === 'auth.logged_out',
    ));

    $nombre = DB::table('employees')->where('uuid', $sesiones['employee'])->value('first_name');

    expect($nombre)->toBeString();
    /** @var string $nombre */
    expect($cierres)->toHaveCount(1)
        ->and($cierres[0]['context']['channel'])->toBe('portal')
        ->and($cierres[0]['context']['subject_uuid'])->toBe($sesiones['employee'])
        ->and(json_encode($cierres[0]['context'], JSON_THROW_ON_ERROR))->not->toContain($nombre)
        ->and(DB::table('audit_log')->where('action', 'auth.logout')->count())->toBe(0);
})->group('RS-13', 'RS-08', 'RF-ID-05');

it('cierra la sesion aunque la zona del portal este agotada', function (): void {
    // La exencion de `RouteRateLimitZonesTest`, probada en comportamiento: en el
    // ordenador compartido toda la plantilla sale por la misma IP, y un `429`
    // aqui dejaria abierta la sesion de alguien.
    $sesiones = portalLogoutDosSesiones();

    // Los dos accesos de la preparacion ya gastaron el cupo de esta IP: con un
    // techo de uno, cualquier otra ruta del portal responde 429.
    config()->set('identity.portal.rate_limit_per_minute', 1);

    Api::as($sesiones['phone'])->get('/api/v1/me/workdays')->assertStatus(429);

    Api::as($sesiones['shared'])->post('/api/v1/me/logout')->assertStatus(204);
})->group('RF-ID-05', 'RS-02');
