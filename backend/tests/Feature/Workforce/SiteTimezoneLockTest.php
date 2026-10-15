<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA ZONA HORARIA DEL CENTRO QUEDA FIJA CON EL PRIMER TRAMO** (RN-23,
 * ADR-056), por HTTP.
 *
 * Mientras no hay registro horario, `PATCH /site` cambia la zona y deja
 * `site.updated`. Desde el primer tramo —en cualquier estado, tambien anulado—
 * otra zona es `409` con `urn:kronoqr:problem:site-time-zone-locked`, sin
 * cambiar nada (tampoco el nombre que viajaba al lado) y sin asiento. La misma
 * zona no es un cambio, y el nombre se sigue cambiando. `GET /site` dice si la
 * zona ya esta fija.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

function zonaFijaToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
}

/**
 * Un tramo del centro en el estado que se pida: lo que importa aqui es que
 * exista, no como se ficho. Se escribe a mano vigente —un tramo nace vigente
 * (ADR-057 §2)— y el anulado se anula despues por la API, como en la vida real.
 */
function zonaFijaTramo(int $site, string $estado = 'closed'): void
{
    $employee = WorkforceFixtures::employee($site);
    $uuid = Str::uuid7()->toString();
    $abierto = $estado === 'open';

    DB::table('shift_entries')->insert([
        'uuid' => $uuid,
        'employee_id' => AttendanceFixtures::employeeIdOf($employee),
        'site_id' => $site,
        'work_date' => '2026-03-14',
        'clocked_in_at' => '2026-03-14 07:00:00+00',
        'clocked_out_at' => $abierto ? null : '2026-03-14 15:00:00+00',
        'duration_minutes' => $abierto ? null : 480,
        'status' => $abierto ? 'open' : 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => $abierto ? null : 'qr_kiosk',
        'version' => 1,
    ]);

    if ($estado === 'voided') {
        Api::as(zonaFijaToken())
            ->post('/api/v1/shift-entries/'.$uuid.'/void', ['reason_code' => 'ERROR_DE_ESCANEO_DUPLICADO'])
            ->assertOk();
    }
}

function zonaFijaNombre(int $site): string
{
    $nombre = DB::table('sites')->where('id', $site)->value('name');
    \assert(\is_string($nombre));

    return $nombre;
}

function zonaFijaAsientos(): int
{
    return DB::table('audit_log')->where('action', 'site.updated')->count();
}

it('sin registro horario cambia la zona, lo audita y dice que sigue abierta', function (): void {
    $site = WorkforceFixtures::site('Hotel Marina', 'Europe/Madrid');

    Api::as(zonaFijaToken())
        ->patch('/api/v1/site', ['timezone' => 'Atlantic/Canary'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('timezone', 'Atlantic/Canary')
        ->assertJsonPath('timezone_locked', false);

    expect(DB::table('sites')->where('id', $site)->value('timezone'))->toBe('Atlantic/Canary')
        ->and(zonaFijaAsientos())->toBe(1);
})->group('RN-23', 'RN-05', 'RL-04');

it('con un tramo responde 409 con su type, sin cambiar la zona ni dejar asiento', function (string $estado): void {
    // Un tramo anulado tambien se ficho con la zona de entonces y sigue en el
    // registro legal: fija la zona igual que uno vigente.
    $site = WorkforceFixtures::site('Hotel Marina', 'Europe/Madrid');
    zonaFijaTramo($site, $estado);

    Api::as(zonaFijaToken())
        ->withHeaders(['Accept-Language' => 'es'])
        ->patch('/api/v1/site', ['timezone' => 'Atlantic/Canary'])
        ->assertValidRequest()
        ->assertValidResponse(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'urn:kronoqr:problem:site-time-zone-locked')
        ->assertJsonPath('status', 409)
        ->assertJsonPath('detail', 'Ya hay fichajes registrados con Europe/Madrid; la zona no se cambia desde la API.');

    expect(DB::table('sites')->where('id', $site)->value('timezone'))->toBe('Europe/Madrid')
        ->and(zonaFijaAsientos())->toBe(0);
})->with([
    'cerrado' => ['closed'],
    'abierto' => ['open'],
    'anulado' => ['voided'],
])->group('RN-23', 'RL-04');

it('da el detalle en ingles a quien lo pide en ingles', function (): void {
    $site = WorkforceFixtures::site('Hotel Marina', 'Europe/Madrid');
    zonaFijaTramo($site);

    Api::as(zonaFijaToken())
        ->withHeaders(['Accept-Language' => 'en'])
        ->patch('/api/v1/site', ['timezone' => 'Atlantic/Canary'])
        ->assertStatus(409)
        ->assertJsonPath('detail', 'There are clock-ins recorded with Europe/Madrid; the time zone cannot be changed through the API.');
})->group('RN-23');

it('con nombre y zona nueva responde 409 y deja el nombre intacto', function (): void {
    $site = WorkforceFixtures::site('Hotel Marina', 'Europe/Madrid');
    $nombre = zonaFijaNombre($site);
    zonaFijaTramo($site);

    Api::as(zonaFijaToken())
        ->patch('/api/v1/site', ['name' => 'Hotel Atlantico', 'timezone' => 'Atlantic/Canary'])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:site-time-zone-locked');

    expect(DB::table('sites')->where('id', $site)->value('name'))->toBe($nombre)
        ->and(DB::table('sites')->where('id', $site)->value('timezone'))->toBe('Europe/Madrid')
        ->and(zonaFijaAsientos())->toBe(0);
})->group('RN-23', 'RL-04');

it('con la misma zona responde 200 y no deja asiento', function (): void {
    $site = WorkforceFixtures::site('Hotel Marina', 'Europe/Madrid');
    zonaFijaTramo($site);

    Api::as(zonaFijaToken())
        ->patch('/api/v1/site', ['name' => zonaFijaNombre($site), 'timezone' => 'Europe/Madrid'])
        ->assertValidResponse(200)
        ->assertJsonPath('timezone', 'Europe/Madrid')
        ->assertJsonPath('timezone_locked', true);

    expect(zonaFijaAsientos())->toBe(0);
})->group('RN-23', 'RL-04');

it('renombra el centro aunque haya registro horario, con su asiento', function (): void {
    $site = WorkforceFixtures::site('Hotel Marina', 'Europe/Madrid');
    zonaFijaTramo($site);

    Api::as(zonaFijaToken())
        ->patch('/api/v1/site', ['name' => 'Hotel Atlantico', 'timezone' => 'Europe/Madrid'])
        ->assertValidResponse(200)
        ->assertJsonPath('name', 'Hotel Atlantico')
        ->assertJsonPath('timezone_locked', true);

    expect(DB::table('sites')->where('id', $site)->value('name'))->toBe('Hotel Atlantico')
        ->and(zonaFijaAsientos())->toBe(1);
})->group('RN-23', 'RF-GP-01');

it('el GET dice si la zona ya esta fija', function (): void {
    $site = WorkforceFixtures::site('Hotel Marina', 'Europe/Madrid');
    $token = zonaFijaToken();

    Api::as($token)->get('/api/v1/site')
        ->assertValidResponse(200)
        ->assertJsonPath('timezone_locked', false);

    zonaFijaTramo($site, 'voided');

    Api::as($token)->get('/api/v1/site')
        ->assertValidResponse(200)
        ->assertJsonPath('timezone_locked', true);
})->group('RN-23');
