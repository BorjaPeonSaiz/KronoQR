<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
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
 * **Aislamiento por departamento** (RF-ID-03, RS-05), el escenario Gherkin del
 * doc 01 §11:
 *
 *     Dado un responsable del departamento "Cocina"
 *     Cuando solicita el detalle de un empleado del departamento "Recepcion"
 *     Entonces recibe un error de autorizacion
 *     Y el intento queda registrado en el trail de auditoria
 *
 * Las dos ultimas lineas son dos aserciones distintas y las dos importan: un
 * `403` sin asiento deja la pregunta «¿quien ha ido a por el expediente de esta
 * persona?» sin respuesta, que es justo la que se hace despues de un incidente.
 *
 * **Hay un solo centro** (ADR-040), asi que el unico eje de alcance es el
 * departamento. No hay ninguna prueba de frontera entre centros y no debe
 * haberla.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    // El responsable no esta entre los roles que RS-06 obliga; se deja explicito
    // para que estas pruebas no dependan del valor de serie.
    config()->set('identity.two_factor.required_roles', []);
});

/**
 * Un departamento con su responsable asignado (`departments.manager_user_id`).
 */
function departamentoDirigidoPor(int $siteId, string $nombre, int $userId): int
{
    $id = WorkforceFixtures::department($siteId, $nombre);

    Department::query()->whereKey($id)->update(['manager_user_id' => $userId]);

    return $id;
}

it('deniega al responsable de Cocina la ficha de alguien de Recepcion y lo deja en auditoria', function (): void {
    $site = WorkforceFixtures::site();
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $cocina = departamentoDirigidoPor($site, 'Cocina', $jefeDeCocina->id);
    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    WorkforceFixtures::employee($site, $cocina);
    $deRecepcion = WorkforceFixtures::employee($site, $recepcion);

    Api::as(ManagementUsers::tokenFor($jefeDeCocina))
        ->get('/api/v1/employees/'.$deRecepcion)
        ->assertStatus(403)
        // Mismo cuerpo que cualquier otra denegacion: desde fuera no se
        // distingue «no tienes el rol» de «no alcanzas a esa persona».
        ->assertJsonPath('type', 'urn:kronoqr:problem:forbidden');

    $asiento = DB::table('audit_log')
        ->where('action', AuditAction::AccessDenied->value)
        ->orderByDesc('id')
        ->first();

    expect($asiento)->not->toBeNull();

    $payload = (string) json_encode($asiento);

    // El UUID del recurso si; el nombre de nadie, nunca (regla dura 21).
    expect($payload)->toContain($deRecepcion)
        ->and($payload)->toContain('employee_profile')
        ->and($payload)->not->toContain('Persona De Prueba');
})->group('RF-ID-03', 'RS-05', 'RQ-07');

it('deja al responsable ver la ficha de su propia gente', function (): void {
    // El control positivo. Sin el, la prueba de arriba pasaria igual con el
    // endpoint roto o con el rol denegado por completo, que es lo que ocurria
    // antes de esta tarea.
    $site = WorkforceFixtures::site();
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $cocina = departamentoDirigidoPor($site, 'Cocina', $jefeDeCocina->id);

    $suyo = WorkforceFixtures::employee($site, $cocina);

    Api::as(ManagementUsers::tokenFor($jefeDeCocina))
        ->get('/api/v1/employees/'.$suyo)
        ->assertValidResponse(200)
        ->assertJsonPath('uuid', $suyo);
})->group('RF-ID-03', 'RQ-07');

it('acota el listado de plantilla en la consulta, con su meta.total', function (): void {
    // El alcance se aplica **en la consulta** y no despues: si filtrara la pagina
    // ya traida, `meta.total` describiria a personas que quien pregunta no puede
    // ver —una fuga por si misma— y la paginacion tendria huecos.
    $site = WorkforceFixtures::site();
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $cocina = departamentoDirigidoPor($site, 'Cocina', $jefeDeCocina->id);
    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    WorkforceFixtures::employee($site, $cocina, 'active', 'Ana');
    WorkforceFixtures::employee($site, $cocina, 'active', 'Bruno');
    WorkforceFixtures::employee($site, $recepcion, 'active', 'Carla');
    WorkforceFixtures::employee($site, null, 'active', 'Sin departamento');

    $respuesta = Api::as(ManagementUsers::tokenFor($jefeDeCocina))->get('/api/v1/employees');

    $respuesta->assertValidResponse(200)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(2, 'data');

    Auth::forgetGuards();

    // Y RRHH sigue viendo a los cuatro, incluido el que no tiene departamento.
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/employees')
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 4);
})->group('RF-ID-03', 'RF-GP-01');

it('no alcanza a nadie el responsable sin departamento asignado', function (): void {
    // El caso que convierte a un responsable recien creado en alguien que ve la
    // plantilla entera si el alcance se representara con una lista vacia
    // indistinguible de «sin restriccion».
    $site = WorkforceFixtures::site();
    $departamento = WorkforceFixtures::department($site, 'Cocina');
    WorkforceFixtures::employee($site, $departamento);

    $huerfano = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    Api::as(ManagementUsers::tokenFor($huerfano))
        ->get('/api/v1/employees')
        ->assertValidResponse(200)
        ->assertJsonPath('meta.total', 0);
})->group('RF-ID-03');

it('deniega al responsable el registro horario de fuera de su departamento', function (): void {
    $site = WorkforceFixtures::site();
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $cocina = departamentoDirigidoPor($site, 'Cocina', $jefeDeCocina->id);
    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    $suyo = WorkforceFixtures::employee($site, $cocina);
    $ajeno = WorkforceFixtures::employee($site, $recepcion);

    $token = ManagementUsers::tokenFor($jefeDeCocina);

    Api::as($token)->get('/api/v1/employees/'.$ajeno.'/workdays')->assertStatus(403);

    Auth::forgetGuards();

    // Control positivo: el suyo si.
    Api::as($token)->get('/api/v1/employees/'.$suyo.'/workdays')->assertValidResponse(200);

    $asientos = DB::table('audit_log')
        ->where('action', AuditAction::AccessDenied->value)
        ->count();

    expect($asientos)->toBe(1);
})->group('RF-ID-03', 'RS-05', 'RF-PA-03');

it('deniega al responsable dar de alta horas de fuera de su departamento', function (): void {
    // RF-PA-04 con alcance: `attendance:correct` esta en su token (§7.3) y la
    // policy le deja crear y corregir, pero solo dentro de su departamento.
    $site = WorkforceFixtures::site();
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    departamentoDirigidoPor($site, 'Cocina', $jefeDeCocina->id);
    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    $ajeno = WorkforceFixtures::employee($site, $recepcion);

    // Cuerpo completo y valido a proposito: lo que tiene que fallar es la
    // autorizacion, no la validacion. Un `422` aqui esconderia el `403`.
    Api::as(ManagementUsers::tokenFor($jefeDeCocina))->post('/api/v1/shift-entries', [
        'employee_uuid' => $ajeno,
        'work_date' => '2026-08-14',
        'clocked_in_at' => '2026-08-14T06:00:00Z',
        'clocked_out_at' => '2026-08-14T14:00:00Z',
        'reason_code' => 'ALTA_RETROACTIVA',
    ])->assertStatus(403);

    $asiento = DB::table('audit_log')
        ->where('action', AuditAction::AccessDenied->value)
        ->orderByDesc('id')
        ->first();

    expect($asiento)->not->toBeNull()
        ->and((string) json_encode($asiento))->toContain('shift_entry');
})->group('RF-ID-03', 'RS-05', 'RF-PA-04');

it('no deja anular a un responsable, ni dentro de su departamento', function (): void {
    // Anular es `rrhh+` y no `manager+` (RF-PA-04): saca horas del registro de una
    // persona y tiene efecto directo en su nomina. Que pueda ajustar diez minutos
    // no significa que pueda borrar una jornada.
    $site = WorkforceFixtures::site();
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    departamentoDirigidoPor($site, 'Cocina', $jefeDeCocina->id);

    Api::as(ManagementUsers::tokenFor($jefeDeCocina))
        ->post('/api/v1/shift-entries/0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c01/void', [
            'reason_code' => 'FALLO_TECNICO_QUIOSCO',
        ])
        ->assertStatus(403);
})->group('RF-ID-03', 'RF-PA-04', 'RQ-07');

it('no deja al responsable modificar la plantilla ni tocar credenciales', function (string $method, string $uri, array $body): void {
    // La otra mitad de RF-ID-03: alcance acotado **y** ambito minimo. El
    // responsable lleva `employees:read`, nunca `employees:*` ni `credentials:*`.
    $site = WorkforceFixtures::site();
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $cocina = departamentoDirigidoPor($site, 'Cocina', $jefeDeCocina->id);
    $suyo = WorkforceFixtures::employee($site, $cocina);

    Api::as(ManagementUsers::tokenFor($jefeDeCocina))
        ->call($method, str_replace('{uuid}', $suyo, $uri), $body)
        ->assertStatus(403);
})->with([
    'modificar la ficha' => ['PATCH', '/api/v1/employees/{uuid}', ['first_name' => 'Lucia']],
    'dar de baja' => ['POST', '/api/v1/employees/{uuid}/offboard', ['terminated_at' => '2026-09-30']],
    'restablecer el PIN' => ['POST', '/api/v1/employees/{uuid}/pin/reset', []],
    'emitir una credencial' => ['POST', '/api/v1/credentials', ['employee_uuid' => '{uuid}']],
    'ver el panel de credenciales' => ['GET', '/api/v1/credentials/status', []],
])->group('RF-ID-03', 'RQ-07', 'RS-04');
