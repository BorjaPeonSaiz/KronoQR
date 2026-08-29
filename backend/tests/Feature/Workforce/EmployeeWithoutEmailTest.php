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
 * Escenario del doc 01 §11, literal:
 *
 *   Dado que RRHH da de alta al empleado "Youssef", sin direccion de correo
 *   Cuando se confirma el alta
 *   Entonces el alta se completa sin error
 *
 * Es la prueba de la regla dura 12 en el punto exacto donde el producto podria
 * empezar a depender del correo del empleado sin que nadie lo notara. El resto
 * del escenario del §11 —emision de credencial, PDF y panel de estado— es de las
 * tareas 1.5 y 1.10.
 */

uses(RefreshDatabase::class);

it('completa el alta de un empleado sin direccion de correo', function (): void {
    Spectator::using('openapi.yaml');

    $site = WorkforceFixtures::site();
    $department = WorkforceFixtures::department($site);
    $hr = ManagementUsers::withRole(UserRole::RRHH);

    $response = Api::as(ManagementUsers::tokenFor($hr))->post('/api/v1/employees', [
        'department_id' => $department,
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'hired_at' => '2026-08-14',
    ]);

    $response->assertValidRequest()
        ->assertValidResponse(201)
        ->assertJsonPath('employee.first_name', 'Youssef')
        ->assertJsonPath('employee.email', null)
        ->assertJsonPath('employee.status', 'active');

    // Y esta en la plantilla, con su codigo generado por el servidor.
    $stored = DB::table('employees')->where('uuid', $response->json('employee.uuid'))->first();

    expect($stored)->not->toBeNull();
    expect($stored?->email)->toBeNull();
    expect($stored?->employee_code)->toBeString();
})->group('RF-GP-01');

it('no exige el correo en ninguna de las operaciones de plantilla', function (): void {
    // La segunda mitad de la regla dura 12: no basta con que el alta lo permita,
    // ninguna funcionalidad puede exigirlo despues.
    $site = WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $uuid = Api::as($token)->post('/api/v1/employees', [
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'hired_at' => '2026-08-14',
    ])->json('employee.uuid');

    $employee = '/api/v1/employees/'.(is_string($uuid) ? $uuid : '');

    Api::as($token)->get($employee)->assertStatus(200);
    Api::as($token)->patch($employee, ['locale' => 'en'])->assertStatus(200);
    Api::as($token)->post($employee.'/offboard', ['terminated_at' => '2026-09-30'])->assertStatus(200);
})->group('RF-GP-01', 'RF-GP-03');
