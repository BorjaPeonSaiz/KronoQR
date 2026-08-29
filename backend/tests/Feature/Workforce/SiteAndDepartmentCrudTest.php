<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El centro de trabajo de la instalacion y sus departamentos (RF-GP-01, ADR-040).
 *
 * Hay exactamente un centro: el recurso es singular (`/api/v1/site`), no tiene
 * alta por HTTP —la hace la puesta en marcha— y los departamentos nacen en el
 * sin que nadie lo elija. La zona horaria del centro es el dato del que depende
 * RN-05, y por eso su modificacion tiene pruebas propias.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

function hrToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
}

it('devuelve el centro de la instalacion con su zona', function (): void {
    $site = WorkforceFixtures::site('Hotel Ribeira', 'Europe/Lisbon');

    Api::as(hrToken())->get('/api/v1/site')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('id', $site)
        ->assertJsonPath('timezone', 'Europe/Lisbon');
})->group('RF-GP-01', 'RN-05');

it('responde 404 antes de la puesta en marcha, cuando todavia no hay centro', function (): void {
    Api::as(hrToken())->get('/api/v1/site')->assertValidResponse(404);
})->group('RF-GP-01');

it('no expone ninguna lista ni alta de centros', function (): void {
    // ADR-040: una licencia es un centro. Las rutas antiguas no existen.
    $token = hrToken();
    WorkforceFixtures::site();

    Api::as($token)->get('/api/v1/sites')->assertStatus(404);
    Api::as($token)->post('/api/v1/sites', ['name' => 'Hotel Marina'])->assertStatus(404);
    Api::as($token)->post('/api/v1/site', ['name' => 'Hotel Marina'])->assertStatus(405);
})->group('RF-GP-01');

it('cambia la zona horaria del centro', function (): void {
    $token = hrToken();
    $site = WorkforceFixtures::site();

    Api::as($token)
        ->patch('/api/v1/site', ['timezone' => 'Atlantic/Canary'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('timezone', 'Atlantic/Canary');

    expect(DB::table('sites')->where('id', $site)->value('timezone'))->toBe('Atlantic/Canary');
})->group('RN-05');

it('renombra el centro', function (): void {
    WorkforceFixtures::site();

    Api::as(hrToken())
        ->patch('/api/v1/site', ['name' => 'Hotel Marina'])
        ->assertValidResponse(200)
        ->assertJsonPath('name', 'Hotel Marina');
})->group('RF-GP-01');

it('rechaza una zona horaria que no existe', function (): void {
    WorkforceFixtures::site();

    Api::as(hrToken())
        ->patch('/api/v1/site', ['timezone' => 'Europe/Madird'])
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->group('RN-05');

it('rechaza un desfase en horas como zona del centro', function (): void {
    WorkforceFixtures::site();

    Api::as(hrToken())
        ->patch('/api/v1/site', ['timezone' => '+02:00'])
        ->assertValidResponse(422);
})->group('RN-09', 'RN-05');

it('no expone ningun verbo que borre el centro o un departamento', function (): void {
    $token = hrToken();
    $site = WorkforceFixtures::site();
    $department = WorkforceFixtures::department($site);

    Api::as($token)->delete('/api/v1/site')->assertStatus(405);
    Api::as($token)->delete('/api/v1/departments/'.$department)->assertStatus(405);
})->group('RF-GP-03');

it('crea el departamento en el centro de la instalacion sin que nadie lo elija', function (): void {
    $site = WorkforceFixtures::site();

    $id = Api::as(hrToken())
        ->post('/api/v1/departments', ['name' => 'Recepcion'])
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonMissingPath('site_id')
        ->json('id');

    expect(DB::table('departments')->where('id', $id)->value('site_id'))->toBe($site);
})->group('RF-GP-01');

it('rechaza un site_id en el alta de departamento en vez de ignorarlo', function (): void {
    $site = WorkforceFixtures::site();

    Api::as(hrToken())
        ->post('/api/v1/departments', ['site_id' => $site, 'name' => 'Recepcion'])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['site_id']]);
})->group('RF-GP-01');

it('responde 409 al crear un departamento antes de la puesta en marcha', function (): void {
    // Sin centro no hay donde colgarlo: es un estado de la instalacion, no un
    // error del formulario.
    Api::as(hrToken())
        ->post('/api/v1/departments', ['name' => 'Recepcion'])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');
})->group('RF-GP-01');

it('no admite dos departamentos con el mismo nombre', function (): void {
    $token = hrToken();
    WorkforceFixtures::site();

    Api::as($token)->post('/api/v1/departments', ['name' => 'Recepcion'])
        ->assertValidResponse(201);
    Api::as($token)->post('/api/v1/departments', ['name' => 'Recepcion'])
        ->assertValidResponse(409);
})->group('RF-GP-01');

it('lista los departamentos de la instalacion por nombre', function (): void {
    $token = hrToken();
    $site = WorkforceFixtures::site();
    WorkforceFixtures::department($site, 'Pisos');
    WorkforceFixtures::department($site, 'Cocina');

    Api::as($token)->get('/api/v1/departments')
        ->assertValidResponse(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonMissingPath('data.0.site_id');
})->group('RF-GP-01');

it('rechaza un site_id al listar departamentos en vez de ignorarlo', function (): void {
    $site = WorkforceFixtures::site();

    Api::as(hrToken())->get('/api/v1/departments', ['site_id' => $site])->assertStatus(422);
})->group('RF-GP-01');

it('renombra un departamento', function (): void {
    $token = hrToken();
    $site = WorkforceFixtures::site();
    $department = WorkforceFixtures::department($site);

    Api::as($token)
        ->patch('/api/v1/departments/'.$department, ['name' => 'Recepcion y conserjeria'])
        ->assertValidResponse(200)
        ->assertJsonPath('name', 'Recepcion y conserjeria');

    // El contrato no admite `site_id` en este cuerpo y el FormRequest lo
    // rechaza: no hay otro centro al que moverlo.
    Api::as($token)
        ->patch('/api/v1/departments/'.$department, ['name' => 'Recepcion', 'site_id' => $site])
        ->assertValidResponse(422);
})->group('RF-GP-01', 'RN-05');
