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
 * Centros y departamentos, validados contra el contrato.
 *
 * La zona horaria del centro no es un campo mas: es el dato del que depende
 * RN-05, y por eso tiene aqui sus propias pruebas.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

function hrToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
}

it('crea un centro con la zona horaria de serie cuando no se indica', function (): void {
    // `Europe/Madrid` es el valor del mercado inicial y es configuracion
    // editable, no una constante (regla dura 13).
    Api::as(hrToken())
        ->post('/api/v1/sites', ['name' => 'Hotel Marina'])
        ->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('timezone', 'Europe/Madrid');
})->group('RN-05');

it('crea un centro en otra zona horaria', function (): void {
    Api::as(hrToken())
        ->post('/api/v1/sites', ['name' => 'Hotel Atlantico', 'timezone' => 'Atlantic/Canary'])
        ->assertValidResponse(201)
        ->assertJsonPath('timezone', 'Atlantic/Canary');
})->group('RN-05');

it('rechaza una zona horaria que no existe', function (): void {
    // Una errata aqui atribuye los turnos de noche al dia equivocado durante
    // meses, sin dar ningun error (RN-05).
    Api::as(hrToken())
        ->post('/api/v1/sites', ['name' => 'Hotel Fantasma', 'timezone' => 'Europe/Madird'])
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->group('RN-05');

it('rechaza un desfase en horas como zona del centro', function (): void {
    // RN-09: un desfase no sabe de cambios de hora.
    Api::as(hrToken())
        ->post('/api/v1/sites', ['name' => 'Hotel Desfase', 'timezone' => '+02:00'])
        ->assertValidResponse(422);
})->group('RN-09', 'RN-05');

it('no admite dos centros con el mismo nombre', function (): void {
    $token = hrToken();

    Api::as($token)->post('/api/v1/sites', ['name' => 'Hotel Marina'])->assertStatus(201);

    Api::as($token)
        ->post('/api/v1/sites', ['name' => 'Hotel Marina'])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');
})->group('RF-GP-01');

it('lista y consulta los centros con su zona', function (): void {
    $token = hrToken();
    $site = WorkforceFixtures::site('Hotel Ribeira', 'Europe/Lisbon');

    Api::as($token)->get('/api/v1/sites')
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.timezone', 'Europe/Lisbon');

    Api::as($token)->get('/api/v1/sites/'.$site)
        ->assertValidResponse(200)
        ->assertJsonPath('id', $site);
})->group('RF-GP-01', 'RN-05');

it('cambia la zona horaria de un centro', function (): void {
    $token = hrToken();
    $site = WorkforceFixtures::site();

    Api::as($token)
        ->patch('/api/v1/sites/'.$site, ['timezone' => 'Atlantic/Canary'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('timezone', 'Atlantic/Canary');

    expect(DB::table('sites')->where('id', $site)->value('timezone'))->toBe('Atlantic/Canary');
})->group('RN-05');

it('no expone ningun verbo que borre un centro o un departamento', function (): void {
    // Regla dura 5. Un centro con empleados o con tramos no puede desaparecer
    // sin llevarse por delante el registro horario que hay que conservar cuatro
    // anos (RL-02). El 405 lo da el enrutador porque la ruta no existe.
    $token = hrToken();
    $site = WorkforceFixtures::site();
    $department = WorkforceFixtures::department($site);

    Api::as($token)->delete('/api/v1/sites/'.$site)->assertStatus(405);
    Api::as($token)->delete('/api/v1/departments/'.$department)->assertStatus(405);
})->group('RF-GP-03');

it('crea departamentos con nombre unico dentro de cada centro', function (): void {
    // Dos hoteles del mismo cliente tienen los dos una «Recepcion», y eso no
    // puede ser un error.
    $token = hrToken();
    $first = WorkforceFixtures::site('Hotel Uno');
    $second = WorkforceFixtures::site('Hotel Dos');

    Api::as($token)->post('/api/v1/departments', ['site_id' => $first, 'name' => 'Recepcion'])
        ->assertValidRequest()
        ->assertValidResponse(201);

    Api::as($token)->post('/api/v1/departments', ['site_id' => $second, 'name' => 'Recepcion'])
        ->assertValidResponse(201);

    Api::as($token)->post('/api/v1/departments', ['site_id' => $first, 'name' => 'Recepcion'])
        ->assertValidResponse(409);
})->group('RF-GP-01');

it('filtra los departamentos por centro', function (): void {
    $token = hrToken();
    $first = WorkforceFixtures::site('Hotel Uno');
    $second = WorkforceFixtures::site('Hotel Dos');

    WorkforceFixtures::department($first, 'Cocina');
    WorkforceFixtures::department($second, 'Pisos');

    Api::as($token)->get('/api/v1/departments', ['site_id' => $first])
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.site_id', $first);
})->group('RF-GP-01');

it('renombra un departamento sin poder moverlo de centro', function (): void {
    // Moverlo arrastraria a sus empleados a otra zona horaria (RN-05). El
    // contrato no admite `site_id` en este cuerpo y el FormRequest lo rechaza.
    $token = hrToken();
    $site = WorkforceFixtures::site();
    $other = WorkforceFixtures::site('Otro hotel');
    $department = WorkforceFixtures::department($site);

    Api::as($token)
        ->patch('/api/v1/departments/'.$department, ['name' => 'Recepcion y conserjeria'])
        ->assertValidResponse(200)
        ->assertJsonPath('name', 'Recepcion y conserjeria')
        ->assertJsonPath('site_id', $site);

    Api::as($token)
        ->patch('/api/v1/departments/'.$department, ['name' => 'Recepcion', 'site_id' => $other])
        ->assertValidResponse(422);
})->group('RF-GP-01', 'RN-05');
