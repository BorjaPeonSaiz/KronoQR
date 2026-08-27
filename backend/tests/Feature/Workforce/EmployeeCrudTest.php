<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Domain\Event\EmployeeHired;
use App\Modules\Workforce\Domain\Event\EmployeeOffboarded;
use App\Modules\Workforce\Domain\Event\EmployeeProfileUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * CRUD de plantilla (RF-GP-01, RF-GP-03), validado contra el contrato.
 *
 * Cada respuesta pasa por Spectator: el cliente TypeScript de los tres frontends
 * se genera de `openapi.yaml`, asi que una desviacion aqui rompe a los tres a la
 * vez y sin aviso.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, site: int, department: int}
 */
function hrContext(): array
{
    $site = WorkforceFixtures::site();

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => $site,
        'department' => WorkforceFixtures::department($site),
    ];
}

it('lista la plantilla paginada', function (): void {
    $context = hrContext();

    for ($i = 0; $i < 3; $i++) {
        WorkforceFixtures::employee($context['site'], $context['department']);
    }

    Api::as($context['token'])
        ->get('/api/v1/employees', ['per_page' => 2])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.total_pages', 2);
})->group('RF-GP-01');

it('incluye a quien esta de baja salvo que se filtre', function (): void {
    // El historico se conserva (RF-GP-03, RL-02) y un listado que escondiera a
    // los cesados haria invisible justo lo que una inspeccion viene a mirar.
    $context = hrContext();

    WorkforceFixtures::employee($context['site'], $context['department']);
    WorkforceFixtures::employee($context['site'], $context['department'], 'terminated');

    Api::as($context['token'])
        ->get('/api/v1/employees')
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 2);

    Api::as($context['token'])
        ->get('/api/v1/employees', ['status' => 'active'])
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 1);
})->group('RF-GP-01', 'RF-GP-03');

it('rechaza un filtro que no existe', function (): void {
    $context = hrContext();

    Api::as($context['token'])
        ->get('/api/v1/employees', ['status' => 'vacaciones'])
        ->assertValidResponse(422);
})->group('RF-GP-01');

it('devuelve la ficha de un empleado por su UUID publico', function (): void {
    $context = hrContext();
    $uuid = WorkforceFixtures::employee($context['site'], $context['department']);

    Api::as($context['token'])
        ->get('/api/v1/employees/'.$uuid)
        ->assertValidResponse(200)
        ->assertJsonPath('uuid', $uuid);
})->group('RF-GP-01');

it('responde 404 por un empleado que no existe', function (): void {
    $context = hrContext();

    Api::as($context['token'])
        ->get('/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-000000000000')
        ->assertValidResponse(404)
        ->assertJsonPath('type', 'urn:kronoqr:problem:not-found');
})->group('RF-GP-01');

it('genera el codigo de empleado en el servidor y no lo acepta del cliente', function (): void {
    $context = hrContext();

    Api::as($context['token'])
        ->post('/api/v1/employees', [
            'site_id' => $context['site'],
            'first_name' => 'Lucia',
            'last_name' => 'Ferrer',
            'hired_at' => '2026-08-14',
            'employee_code' => 'RECEPCION-01',
        ])
        // El contrato no admite propiedades adicionales y el FormRequest rechaza
        // lo desconocido en lugar de ignorarlo: quien lo envia se enteraria de
        // que el codigo lo pone el servidor (RF-ID-06).
        ->assertValidResponse(422);
})->group('RF-GP-01', 'RF-ID-06');

it('no almacena el documento de identidad en claro', function (): void {
    // RL-08. Entra en la peticion, sale como digest y no vuelve en la respuesta.
    $context = hrContext();

    $response = Api::as($context['token'])->post('/api/v1/employees', [
        'site_id' => $context['site'],
        'first_name' => 'Lucia',
        'last_name' => 'Ferrer',
        'national_id' => '00000000T',
        'hired_at' => '2026-08-14',
    ]);

    $response->assertValidResponse(201);

    expect($response->content())->not->toContain('00000000T');

    $uuid = $response->json('employee.uuid');

    /** @var object{hash: string|null}|null $row */
    $row = DB::selectOne(
        "SELECT encode(national_id_hash, 'hex') AS hash FROM employees WHERE uuid = ?",
        [is_string($uuid) ? $uuid : ''],
    );

    expect($row?->hash)->toBe(hash('sha256', '00000000T'));
})->group('RL-08', 'RF-GP-01');

it('no admite un departamento de otro centro', function (): void {
    // Un empleado adscrito a un departamento de otro hotel deja ambiguo de que
    // centro sale su zona horaria, que es de lo que depende RN-05.
    $context = hrContext();
    $otherSite = WorkforceFixtures::site('Otro hotel', 'Atlantic/Canary');
    $otherDepartment = WorkforceFixtures::department($otherSite);

    Api::as($context['token'])
        ->post('/api/v1/employees', [
            'site_id' => $context['site'],
            'department_id' => $otherDepartment,
            'first_name' => 'Lucia',
            'last_name' => 'Ferrer',
            'hired_at' => '2026-08-14',
        ])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.department_id.0', 'El departamento no pertenece al centro indicado.');
})->group('RF-GP-01', 'RN-05');

it('modifica la ficha sin tocar lo que no se envia', function (): void {
    $context = hrContext();
    $uuid = WorkforceFixtures::employee($context['site'], $context['department']);

    Api::as($context['token'])
        ->patch('/api/v1/employees/'.$uuid, ['first_name' => 'Lucia'])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('first_name', 'Lucia')
        ->assertJsonPath('last_name', 'De Prueba')
        ->assertJsonPath('status', 'active');
})->group('RF-GP-01');

it('no deja dar de baja por la puerta de atras del PATCH', function (): void {
    // La baja lleva fecha de cese y consecuencias (RN-14). Un PATCH que pudiera
    // darla acabaria produciendo bajas sin fecha.
    $context = hrContext();
    $uuid = WorkforceFixtures::employee($context['site'], $context['department']);

    Api::as($context['token'])
        ->patch('/api/v1/employees/'.$uuid, ['status' => 'terminated'])
        ->assertValidResponse(422);
})->group('RF-GP-03');

it('da de baja sin borrar nada', function (): void {
    // Regla dura 5 y RF-GP-03: la fila sigue ahi con todo su historial.
    $context = hrContext();
    $uuid = WorkforceFixtures::employee($context['site'], $context['department']);

    Api::as($context['token'])
        ->post('/api/v1/employees/'.$uuid.'/offboard', [
            'terminated_at' => '2026-09-30',
            'reason' => 'Fin de contrato de temporada',
        ])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('status', 'terminated')
        ->assertJsonPath('terminated_at', '2026-09-30');

    expect(DB::table('employees')->where('uuid', $uuid)->exists())->toBeTrue();
})->group('RF-GP-03');

it('no repite una baja ya registrada', function (): void {
    $context = hrContext();
    $uuid = WorkforceFixtures::employee($context['site'], $context['department'], 'terminated');

    Api::as($context['token'])
        ->post('/api/v1/employees/'.$uuid.'/offboard', ['terminated_at' => '2026-09-30'])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');
})->group('RF-GP-03');

it('publica los eventos de plantilla que otros modulos necesitan', function (): void {
    // Son el enganche de la revocacion de credencial (tarea 1.5) y del asiento
    // de audit_log (tarea 1.14): sin ellos, esas tareas tendrian que volver a
    // abrir estos casos de uso.
    Event::fake([EmployeeHired::class, EmployeeProfileUpdated::class, EmployeeOffboarded::class]);

    $context = hrContext();

    $created = Api::as($context['token'])->post('/api/v1/employees', [
        'site_id' => $context['site'],
        'first_name' => 'Lucia',
        'last_name' => 'Ferrer',
        'hired_at' => '2026-08-14',
    ])->json('employee.uuid');

    $uuid = is_string($created) ? $created : '';

    Api::as($context['token'])->patch('/api/v1/employees/'.$uuid, ['locale' => 'en']);
    Api::as($context['token'])->post('/api/v1/employees/'.$uuid.'/offboard', [
        'terminated_at' => '2026-09-30',
    ]);

    Event::assertDispatched(EmployeeHired::class);
    Event::assertDispatched(EmployeeProfileUpdated::class);
    Event::assertDispatched(
        EmployeeOffboarded::class,
        static fn (EmployeeOffboarded $event): bool => $event->employeeUuid === $uuid
            && $event->terminatedOn === '2026-09-30',
    );
})->group('RF-GP-01', 'RF-GP-03');
