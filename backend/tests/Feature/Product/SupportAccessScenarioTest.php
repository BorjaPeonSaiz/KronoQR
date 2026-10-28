<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Escenario «Acceso de soporte del fabricante» del doc 01 §11 (RF-PD-11,
 * ADR-020), con el reloj de verdad y no reescribiendo `expires_at` (G5 de la
 * verificacion 2.1.0):
 *
 * - El acceso se concede por el panel a las 08:00 UTC del 7 de octubre de 2026
 *   por 24 horas.
 * - Cada uso queda en `audit_log` con un asiento `support_grant.used` **por
 *   ventana de `product.support_use_audit_window_seconds`** (900 s), no por
 *   peticion: es el agrupamiento que protege el camino del fichaje.
 * - El administrador ve el ultimo acceso en la lista de concesiones.
 * - A las 08:00:01 del dia 8 el token deja de valer sin que nadie haga nada.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    Cache::flush();
    config()->set('product.support_use_audit_window_seconds', 900);
    FrozenTime::at('2026-10-07 08:00:00');
});

/**
 * La concesion de 24 horas con alcance `configuration`, hecha por una admin
 * desde el panel.
 *
 * @return array{admin: string, support: string, grant: string}
 */
function soporteEscenarioConcesion(): array
{
    $admin = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

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

/** El soporte del fabricante consulta datos de la instalacion. */
function soporteEscenarioConsulta(string $support): int
{
    $status = Api::as($support)->get('/api/v1/settings')->status();
    Auth::forgetGuards();

    return $status;
}

it('deja un asiento de uso con la concesion como actor por cada ventana de 900 s', function (): void {
    $concesion = soporteEscenarioConcesion();

    FrozenTime::at('2026-10-07 09:00:00');
    soporteEscenarioConsulta($concesion['support']);
    FrozenTime::at('2026-10-07 09:14:59');
    soporteEscenarioConsulta($concesion['support']);
    FrozenTime::at('2026-10-07 09:15:01');
    soporteEscenarioConsulta($concesion['support']);

    $asientos = DB::table('audit_log')->where('action', 'support_grant.used')->orderBy('id')->get();

    expect($asientos)->toHaveCount(2)
        ->and($asientos->pluck('actor_type')->all())->toBe(['support_grant', 'support_grant'])
        ->and($asientos->pluck('actor_id')->unique()->all())->toBe([DB::table('support_grants')->where('uuid', $concesion['grant'])->value('id')]);
})->group('RF-PD-11', 'RL-17', 'RL-18');

it('enseña al administrador del cliente el ultimo acceso del soporte', function (): void {
    $concesion = soporteEscenarioConcesion();

    FrozenTime::at('2026-10-07 09:30:00');
    soporteEscenarioConsulta($concesion['support']);

    Api::as($concesion['admin'])->get('/api/v1/support/grants')
        ->assertStatus(200)
        ->assertJsonPath('data.0.uuid', $concesion['grant'])
        ->assertJsonPath('data.0.accessed_at', '2026-10-07T09:30:00.000000Z');
})->group('RF-PD-11', 'RL-17');

it('deja de funcionar a las 24 horas sin intervencion de nadie', function (string $instante, int $esperado): void {
    $concesion = soporteEscenarioConcesion();

    FrozenTime::at($instante);

    expect(soporteEscenarioConsulta($concesion['support']))->toBe($esperado);
})->with([
    'un segundo antes de las 24 horas' => ['2026-10-08 07:59:59', 200],
    'un segundo despues de las 24 horas' => ['2026-10-08 08:00:01', 401],
])->group('RF-PD-11', 'RL-18');
