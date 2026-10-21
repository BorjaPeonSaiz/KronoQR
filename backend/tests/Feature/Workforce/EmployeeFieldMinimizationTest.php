<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * DIV-1 (RGPD art. 5.1.c, RF-ID-03, RS-05): el responsable de departamento lee
 * su plantilla **sin `email`, sin `locale` y sin `pin_status`** —omitidos, no a
 * `null`— y no puede filtrar por `pin_status`. `admin` y `rrhh` siguen
 * recibiendo la ficha completa. Las respuestas se validan contra el contrato:
 * `ScopedEmployee` para el responsable, `Employee` para los demas.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    config()->set('identity.two_factor.required_roles', []);
});

/** Los tres campos que el responsable no recibe. */
const MINIMIZACION_CAMPOS_RESERVADOS = ['email', 'locale', 'pin_status'];

/**
 * Un departamento dirigido por un responsable, con una persona con correo.
 *
 * @return array{responsable: string, persona: string}
 */
function minimizacionPlantilla(): array
{
    $site = WorkforceFixtures::site();
    $responsable = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $cocina = WorkforceFixtures::department($site, 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $responsable->id]);

    $persona = WorkforceFixtures::employee($site, $cocina);
    DB::table('employees')->where('uuid', $persona)->update(['email' => 'persona@example.test']);

    return ['responsable' => ManagementUsers::tokenFor($responsable), 'persona' => $persona];
}

it('sirve al responsable la ficha de su gente sin correo, idioma ni estado del PIN', function (): void {
    $plantilla = minimizacionPlantilla();

    $ficha = Api::as($plantilla['responsable'])->get('/api/v1/employees/'.$plantilla['persona'])
        ->assertValidResponse(200)
        ->assertJsonPath('uuid', $plantilla['persona']);

    foreach (MINIMIZACION_CAMPOS_RESERVADOS as $campo) {
        $ficha->assertJsonMissingPath($campo);
    }

    expect($ficha->getContent())->not->toContain('persona@example.test');
})->group('RF-ID-03', 'RS-05', 'RF-GP-01');

it('sirve al responsable su listado sin correo, idioma ni estado del PIN', function (): void {
    $plantilla = minimizacionPlantilla();

    $pagina = Api::as($plantilla['responsable'])->get('/api/v1/employees')
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.uuid', $plantilla['persona']);

    foreach (MINIMIZACION_CAMPOS_RESERVADOS as $campo) {
        $pagina->assertJsonMissingPath('data.0.'.$campo);
    }
})->group('RF-ID-03', 'RS-05', 'RF-GP-01');

it('deniega al responsable el filtro por estado del PIN', function (string $estado): void {
    $plantilla = minimizacionPlantilla();

    Api::as($plantilla['responsable'])->get('/api/v1/employees?pin_status='.$estado)
        ->assertStatus(403)
        ->assertJsonPath('type', 'urn:kronoqr:problem:forbidden');
})->with(['pending', 'issued', 'delivered'])->group('RF-ID-03', 'RS-05', 'RQ-07');

it('sigue dando la ficha completa y el filtro del PIN a admin y rrhh', function (UserRole $rol): void {
    $plantilla = minimizacionPlantilla();
    Auth::forgetGuards();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($rol));

    Api::as($token)->get('/api/v1/employees/'.$plantilla['persona'])
        ->assertValidResponse(200)
        ->assertJsonPath('email', 'persona@example.test')
        ->assertJsonPath('locale', 'es')
        ->assertJsonPath('pin_status', 'pending');

    Api::as($token)->get('/api/v1/employees?pin_status=pending')
        ->assertValidResponse(200)
        ->assertJsonPath('data.0.uuid', $plantilla['persona'])
        ->assertJsonPath('data.0.email', 'persona@example.test')
        ->assertJsonPath('data.0.locale', 'es')
        ->assertJsonPath('data.0.pin_status', 'pending');
})->with([
    'admin' => [UserRole::ADMIN],
    'rrhh' => [UserRole::RRHH],
])->group('RF-GP-01', 'RF-ID-09', 'RQ-07');
