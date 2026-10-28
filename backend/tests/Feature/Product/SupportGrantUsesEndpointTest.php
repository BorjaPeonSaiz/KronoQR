<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/support/grants/{uuid}/uses` (RF-PD-11, RL-15, RL-18, ADR-020 §3;
 * G5, decision del propietario del 27-10-2026): el panel enseña una linea por
 * cada ventana de uso del soporte, como promete el doc 05 §6.3.
 *
 * Los asientos se producen **por el camino real** —el token de soporte pide una
 * ruta y el middleware deja el asiento—, no se insertan a mano: asi, si
 * `Compliance` renombrara la accion `support_grant.used`, la lista quedaria
 * vacia y estas pruebas lo dirian.
 *
 * La autorizacion negativa, rol por rol, esta en `SupportGrantAuthorizationTest`
 * junto a las otras tres rutas.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();
    LicenseKeys::install();
    Cache::flush();
    config()->set('product.support_use_audit_window_seconds', 900);
    FrozenTime::at('2026-10-07 08:00:00');
});

/**
 * Una concesion de `configuration` hecha por una admin desde el panel.
 *
 * @return array{admin: string, support: string, grant: string}
 */
function usosSoporteConcesion(?string $admin = null): array
{
    $admin ??= ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    $respuesta = Api::as($admin)->post('/api/v1/support/grants', [
        'reason' => 'Incidencia #123: la cola del quiosco de recepcion no vacia',
        'scope' => 'configuration',
        'hours' => 24,
    ])->assertStatus(201);
    Auth::forgetGuards();

    $support = $respuesta->json('data.token');
    $grant = $respuesta->json('data.uuid');

    expect($support)->toBeString()
        ->and($grant)->toBeString();

    /** @var string $support */
    /** @var string $grant */
    return ['admin' => $admin, 'support' => $support, 'grant' => $grant];
}

/** El soporte consulta algo a la hora congelada. */
function usosSoporteConsulta(string $support, string $at, string $uri = '/api/v1/settings'): void
{
    FrozenTime::at($at);
    Api::as($support)->get($uri)->assertOk();
    Auth::forgetGuards();
}

it('lista una linea por ventana, de la mas reciente a la mas antigua, con el patron de la ruta', function (): void {
    $concesion = usosSoporteConcesion();

    usosSoporteConsulta($concesion['support'], '2026-10-07 09:00:00');
    // Dentro de la misma ventana: no deja linea.
    usosSoporteConsulta($concesion['support'], '2026-10-07 09:14:59');
    usosSoporteConsulta($concesion['support'], '2026-10-07 09:15:01');

    $respuesta = Api::as($concesion['admin'])
        ->get('/api/v1/support/grants/'.$concesion['grant'].'/uses')
        ->assertValidResponse(200);

    expect($respuesta->json('data'))->toBe([
        ['started_at' => '2026-10-07T09:15:01.000000Z', 'method' => 'GET', 'route' => 'api/v1/settings'],
        ['started_at' => '2026-10-07T09:00:00.000000Z', 'method' => 'GET', 'route' => 'api/v1/settings'],
    ])->and($respuesta->json('meta'))->toBe(['page' => 1, 'per_page' => 25, 'total' => 2, 'total_pages' => 1]);
})->group('RF-PD-11', 'RL-18');

it('solo devuelve los usos de esa concesion y ningun otro asiento', function (): void {
    $admin = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    $una = usosSoporteConcesion($admin);
    $otra = usosSoporteConcesion($admin);

    usosSoporteConsulta($una['support'], '2026-10-07 09:00:00');
    usosSoporteConsulta($otra['support'], '2026-10-07 09:05:00');
    usosSoporteConsulta($otra['support'], '2026-10-07 09:30:00');
    // Y un asiento de otra accion de la misma concesion, que no es un uso.
    FrozenTime::at('2026-10-07 10:00:00');
    Api::as($admin)->delete('/api/v1/support/grants/'.$una['grant'])->assertNoContent();
    Auth::forgetGuards();

    expect(DB::table('audit_log')->where('payload->grant_uuid', $una['grant'])->where('action', '!=', 'support_grant.used')->count())
        ->toBeGreaterThan(0, 'La prueba necesita asientos de otra accion de la misma concesion.');

    $respuesta = Api::as($admin)
        ->get('/api/v1/support/grants/'.$una['grant'].'/uses')
        ->assertValidResponse(200);

    expect($respuesta->json('data.*.started_at'))->toBe(['2026-10-07T09:00:00.000000Z'])
        ->and($respuesta->json('meta.total'))->toBe(1);
})->group('RF-PD-11', 'RL-15');

it('no saca del asiento mas que el instante, el metodo y el patron de la ruta, nunca la URL', function (): void {
    $concesion = usosSoporteConcesion();

    // Una ruta con un identificador en la URL. Que el grupo de errores no exista
    // da igual: el uso queda anotado con cualquier respuesta.
    FrozenTime::at('2026-10-07 09:00:00');
    Api::as($concesion['support'])->post('/api/v1/diagnostics/errors/987654/resolve');
    Auth::forgetGuards();

    $respuesta = Api::as($concesion['admin'])
        ->get('/api/v1/support/grants/'.$concesion['grant'].'/uses')
        ->assertValidResponse(200);

    expect($respuesta->json('data'))->toBe([
        ['started_at' => '2026-10-07T09:00:00.000000Z', 'method' => 'POST', 'route' => 'api/v1/diagnostics/errors/{id}/resolve'],
    ]);
})->group('RF-PD-11', 'RL-15');

it('pagina las ventanas con PageMeta', function (): void {
    $concesion = usosSoporteConcesion();

    foreach (['09:00:00', '09:20:00', '09:40:00'] as $hora) {
        usosSoporteConsulta($concesion['support'], '2026-10-07 '.$hora);
    }

    $respuesta = Api::as($concesion['admin'])
        ->get('/api/v1/support/grants/'.$concesion['grant'].'/uses?page=2&per_page=2')
        ->assertValidResponse(200);

    expect($respuesta->json('data.*.started_at'))->toBe(['2026-10-07T09:00:00.000000Z'])
        ->and($respuesta->json('meta'))->toBe(['page' => 2, 'per_page' => 2, 'total' => 3, 'total_pages' => 2]);
})->group('RF-PD-11');

it('devuelve una lista vacia para una concesion que nunca se uso', function (): void {
    $concesion = usosSoporteConcesion();

    $respuesta = Api::as($concesion['admin'])
        ->get('/api/v1/support/grants/'.$concesion['grant'].'/uses')
        ->assertValidResponse(200);

    expect($respuesta->json('data'))->toBe([])
        ->and($respuesta->json('meta'))->toBe(['page' => 1, 'per_page' => 25, 'total' => 0, 'total_pages' => 0]);
})->group('RF-PD-11');

it('responde 404 si el uuid no es de ninguna concesion', function (): void {
    $admin = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($admin)
        ->get('/api/v1/support/grants/0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f60/uses')
        ->assertValidResponse(404);
})->group('RF-PD-11');

it('rechaza con 422 lo que no es una pagina', function (string $query): void {
    $concesion = usosSoporteConcesion();

    Api::as($concesion['admin'])
        ->get('/api/v1/support/grants/'.$concesion['grant'].'/uses?'.$query)
        ->assertValidResponse(422);
})->with([
    'pagina cero' => ['page=0'],
    'mas de 100 por pagina' => ['per_page=101'],
    // Un filtro convertiria la ruta en una consulta de `audit_log`.
    'filtro por accion' => ['action=support_grant.granted'],
])->group('RF-PD-11', 'RL-15');
