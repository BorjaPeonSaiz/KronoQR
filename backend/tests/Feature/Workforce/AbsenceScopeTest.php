<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El alcance del listado de ausencias y la nota que **no** viaja
 * (**RF-ID-03**, RF-GP-04, regla dura 21, tarea 3.10).
 *
 * Dos cosas distintas y las dos importan:
 *
 *   1. **El responsable solo ve las ausencias de su gente**, y el filtro esta
 *      dentro del `WHERE`: `meta.total` describe lo que puede ver, no lo que
 *      hay. Filtrar despues seria una fuga por si misma.
 *   2. **La nota no le llega, y el campo desaparece del objeto.** `null` diria
 *      «no hay nota» y lo cierto es «no te corresponde». Una baja medica es dato
 *      de salud y la nota puede llevar un diagnostico.
 *
 * El tipo si lo ve: quien organiza el turno tiene que saber quien falta y por
 * que categoria, y esa es la informacion que la pantalla existe para dar.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    // El responsable no esta entre los roles que RS-06 obliga; se deja explicito
    // para que estas pruebas no dependan del valor de serie.
    config()->set('identity.two_factor.required_roles', []);
});

/**
 * Una ausencia escrita con el constructor de consultas, para no depender del
 * endpoint de alta al probar la lectura.
 */
function ausenciaDe(string $employeeUuid, string $type = 'vacation', ?string $note = null): string
{
    $uuid = Str::uuid7()->toString();

    /** @var int|string|null $employeeId */
    $employeeId = DB::table('employees')->where('uuid', $employeeUuid)->value('id');

    DB::table('absences')->insert([
        'uuid' => $uuid,
        'employee_id' => \is_numeric($employeeId) ? (int) $employeeId : 0,
        'type' => $type,
        'starts_on' => '2026-03-02',
        'ends_on' => '2026-03-06',
        'note' => $note,
        'status' => 'active',
        'version' => 1,
        'created_at' => '2026-03-01T08:00:00+00:00',
    ]);

    return $uuid;
}

it('el responsable solo ve las ausencias de su departamento, tambien en el recuento', function (): void {
    $site = WorkforceFixtures::site();
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $cocina = WorkforceFixtures::department($site, 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $jefeDeCocina->id]);

    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    ausenciaDe(WorkforceFixtures::employee($site, $cocina), 'sick_leave');
    ausenciaDe(WorkforceFixtures::employee($site, $recepcion));

    Api::as(ManagementUsers::tokenFor($jefeDeCocina))
        ->get('/api/v1/absences?from=2026-03-01&to=2026-03-31')
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.department_name', fn (mixed $nombre): bool => \is_string($nombre)
            && str_starts_with($nombre, 'Cocina'))
        // El recuento describe lo que puede ver, no lo que hay.
        ->assertJsonPath('meta.total', 1);

    // Y RRHH las ve las dos, que es lo que hace significativa la afirmacion de
    // arriba: sin esto, una consulta rota daria el mismo resultado.
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/absences?from=2026-03-01&to=2026-03-31')
        ->assertValidResponse(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);
})->group('RF-ID-03', 'RF-GP-04');

it('no le manda la nota al responsable, y el campo no existe en lugar de venir vacio', function (): void {
    // **Regla dura 21.** `null` significa «no hay nota»; aqui lo cierto es «no te
    // corresponde», y las dos cosas no se pueden decir igual.
    $site = WorkforceFixtures::site();
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $cocina = WorkforceFixtures::department($site, 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $jefeDeCocina->id]);

    $empleado = WorkforceFixtures::employee($site, $cocina);
    $uuid = ausenciaDe($empleado, 'sick_leave', 'Texto que no le corresponde al responsable.');

    $respuesta = Api::as(ManagementUsers::tokenFor($jefeDeCocina))
        ->get('/api/v1/absences?from=2026-03-01&to=2026-03-31')
        ->assertValidResponse(200)
        // El TIPO si lo ve: quien organiza el turno tiene que saber por que
        // categoria falta su gente.
        ->assertJsonPath('data.0.type', 'sick_leave');

    $respuesta->assertJsonMissingPath('data.0.note');

    // Ni por el detalle, que es la otra puerta.
    $detalle = Api::as(ManagementUsers::tokenFor($jefeDeCocina))
        ->get('/api/v1/absences/'.$uuid)
        ->assertValidResponse(200);

    $detalle->assertJsonMissingPath('absence.note');

    // Y el texto no aparece en ninguna parte del cuerpo.
    expect($detalle->getContent())->not->toContain('no le corresponde');

    // RRHH si la recibe: es quien la escribio y quien tiene que poder corregirla.
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/absences/'.$uuid)
        ->assertValidResponse(200)
        ->assertJsonPath('absence.note', 'Texto que no le corresponde al responsable.');
})->group('RF-ID-03', 'RF-GP-04');

it('deniega al responsable el detalle de una ausencia de otro departamento y lo deja en auditoria', function (): void {
    // Escenario «Aislamiento por departamento» del doc 01 §11, aplicado al
    // recurso nuevo: en el DETALLE si hay un sujeto identificable al que apuntar
    // en el trail, asi que aqui el `403` es la respuesta correcta.
    $site = WorkforceFixtures::site();
    $jefeDeCocina = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    $cocina = WorkforceFixtures::department($site, 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $jefeDeCocina->id]);

    $recepcion = WorkforceFixtures::department($site, 'Recepcion');
    $ajena = ausenciaDe(WorkforceFixtures::employee($site, $recepcion), 'sick_leave', 'Diagnostico que nadie deberia ver.');

    Api::as(ManagementUsers::tokenFor($jefeDeCocina))
        ->get('/api/v1/absences/'.$ajena)
        ->assertStatus(403)
        ->assertJsonPath('type', 'urn:kronoqr:problem:forbidden');

    $asiento = DB::table('audit_log')
        ->where('action', AuditAction::AccessDenied->value)
        ->orderByDesc('id')
        ->first();

    expect($asiento)->not->toBeNull();

    $payload = (string) json_encode($asiento);

    // El asiento nombra el conjunto y la persona por su UUID, nunca la nota ni
    // el nombre de nadie (regla dura 21).
    expect($payload)->toContain('absence');
    expect($payload)->not->toContain('Diagnostico');
})->group('RF-ID-03', 'RS-05', 'RF-GP-04');

it('deja en audit_log que alguien se llevo una pagina del cuadro de ausencias', function (): void {
    // RS-05: el listado reparte una lista de personas **con una categoria de
    // ausencia al lado**, y una de esas categorias es dato de salud. El asiento
    // describe el alcance y nunca lo divulgado.
    $site = WorkforceFixtures::site();

    ausenciaDe(WorkforceFixtures::employee($site), 'sick_leave', 'No debe salir.');

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->get('/api/v1/absences?from=2026-03-01&to=2026-03-31')
        ->assertValidResponse(200);

    $asiento = DB::table('audit_log')
        ->where('action', AuditAction::PersonalDataAccessed->value)
        ->orderByDesc('id')
        ->first();

    expect($asiento)->not->toBeNull();

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) ($asiento->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['dataset'] ?? null)->toBe('absence_register');
    expect($payload['record_count'] ?? null)->toBe(1);
    expect($payload['scope'] ?? null)->toBe('all');
    expect($payload['from'] ?? null)->toBe('2026-03-01');

    // Ni la nota ni el nombre de nadie.
    expect((string) ($asiento->payload ?? ''))->not->toContain('No debe salir');
})->group('RS-05', 'RF-GP-04');
