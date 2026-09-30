<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\VerifyAuditChain;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * AUD-2 (RF-GP-01, RF-GP-02, RF-GP-03, RL-04, RS-05, regla dura 6): cada alta,
 * cambio y baja de empleado y cada alta y cambio de departamento deja asiento
 * en `audit_log`, con la persona que lo hizo, **la lista de campos tocados y
 * ningun valor** (regla dura 21), y la cadena de hash sigue integra.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    FrozenTime::at('2026-09-14 10:00:00');
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, user: int, site: int, department: int}
 */
function contextoDeAuditoriaDePlantilla(): array
{
    $site = WorkforceFixtures::site();
    $user = ManagementUsers::withRole(UserRole::RRHH);

    return [
        'token' => ManagementUsers::tokenFor($user),
        'user' => $user->id,
        'site' => $site,
        'department' => WorkforceFixtures::department($site),
    ];
}

/**
 * Los asientos de una accion, con el payload ya decodificado.
 *
 * @return array<int, array{actor_type: string, actor_id: int|null, subject_type: string|null, subject_id: int|null, payload: array<string, mixed>, raw: string}>
 */
function asientosDePlantilla(string $action): array
{
    return DB::table('audit_log')
        ->where('action', $action)
        ->orderBy('id')
        ->get()
        ->map(static function (object $row): array {
            /** @var object{actor_type: string, actor_id: int|null, subject_type: string|null, subject_id: int|null, payload: string} $row */
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

            return [
                'actor_type' => $row->actor_type,
                'actor_id' => $row->actor_id,
                'subject_type' => $row->subject_type,
                'subject_id' => $row->subject_id,
                'payload' => $payload,
                'raw' => $row->payload,
            ];
        })
        ->values()
        ->all();
}

it('deja employee.hired con quien dio de alta y sin ningun dato personal', function (): void {
    $contexto = contextoDeAuditoriaDePlantilla();

    $uuid = Api::as($contexto['token'])->post('/api/v1/employees', [
        'first_name' => 'Lucia',
        'last_name' => 'Ferrer',
        'email' => 'lucia.ferrer@example.test',
        'national_id' => '00000000T',
        'department_id' => $contexto['department'],
        'hired_at' => '2026-09-14',
    ])->assertValidResponse(201)->json('employee.uuid');

    $asientos = asientosDePlantilla('employee.hired');

    expect($asientos)->toHaveCount(1)
        ->and($asientos[0]['actor_type'])->toBe('user')
        ->and($asientos[0]['actor_id'])->toBe($contexto['user'])
        ->and($asientos[0]['payload'])->toEqual([
            'department_id' => $contexto['department'],
            'employee_uuid' => $uuid,
            'site_id' => $contexto['site'],
            'via_import' => false,
        ]);

    foreach (['Lucia', 'Ferrer', 'lucia.ferrer', '00000000T'] as $dato) {
        expect(str_contains($asientos[0]['raw'], $dato))->toBeFalse('El asiento lleva «'.$dato.'».');
    }
})->group('RF-GP-01', 'RL-04', 'RS-05');

it('deja employee.updated con los campos tocados y sin sus valores', function (): void {
    $contexto = contextoDeAuditoriaDePlantilla();
    $otro = WorkforceFixtures::department($contexto['site'], 'Cocina');
    $uuid = WorkforceFixtures::employee($contexto['site'], $contexto['department']);

    Api::as($contexto['token'])->patch('/api/v1/employees/'.$uuid, [
        'first_name' => 'Nombre Nuevo',
        'department_id' => $otro,
    ])->assertValidResponse(200);

    $asientos = asientosDePlantilla('employee.updated');

    expect($asientos)->toHaveCount(1)
        ->and($asientos[0]['actor_id'])->toBe($contexto['user'])
        ->and($asientos[0]['payload'])->toEqual([
            'changed_fields' => ['first_name', 'department_id'],
            'employee_uuid' => $uuid,
        ])
        ->and(str_contains($asientos[0]['raw'], 'Nombre Nuevo'))->toBeFalse();
})->group('RF-GP-01', 'RF-ID-03', 'RL-04');

it('no deja asiento de un PATCH que no cambia nada', function (): void {
    $contexto = contextoDeAuditoriaDePlantilla();
    $uuid = WorkforceFixtures::employee($contexto['site'], $contexto['department']);
    $nombre = DB::table('employees')->where('uuid', $uuid)->value('first_name');

    Api::as($contexto['token'])->patch('/api/v1/employees/'.$uuid, ['first_name' => $nombre])
        ->assertValidResponse(200);

    expect(asientosDePlantilla('employee.updated'))->toHaveCount(0);
})->group('RF-GP-01', 'RL-04');

it('deja employee.offboarded con la fecha de cese y sin el motivo libre', function (): void {
    $contexto = contextoDeAuditoriaDePlantilla();
    $uuid = WorkforceFixtures::employee($contexto['site'], $contexto['department']);

    Api::as($contexto['token'])->post('/api/v1/employees/'.$uuid.'/offboard', [
        'terminated_at' => '2026-09-14',
        'reason' => 'Baja voluntaria de Lucia por traslado',
    ])->assertValidResponse(200);

    $asientos = asientosDePlantilla('employee.offboarded');

    expect($asientos)->toHaveCount(1)
        ->and($asientos[0]['actor_id'])->toBe($contexto['user'])
        ->and($asientos[0]['payload'])->toEqual([
            'employee_uuid' => $uuid,
            'has_reason' => true,
            'terminated_on' => '2026-09-14',
        ])
        ->and(str_contains($asientos[0]['raw'], 'Lucia'))->toBeFalse();
})->group('RF-GP-03', 'RN-14', 'RL-04');

it('deja department.created y department.renamed sobre el departamento, sin el nombre', function (): void {
    $contexto = contextoDeAuditoriaDePlantilla();

    $id = Api::as($contexto['token'])->post('/api/v1/departments', ['name' => 'Lavanderia'])
        ->assertValidResponse(201)
        ->json('id');

    Api::as($contexto['token'])->patch('/api/v1/departments/'.$id, ['name' => 'Lavanderia y costura'])
        ->assertValidResponse(200);

    // Renombrarlo al mismo nombre no cambia nada y no deja asiento.
    Api::as($contexto['token'])->patch('/api/v1/departments/'.$id, ['name' => 'Lavanderia y costura'])
        ->assertValidResponse(200);

    $creado = asientosDePlantilla('department.created');
    $renombrado = asientosDePlantilla('department.renamed');

    expect($creado)->toHaveCount(1)
        ->and($creado[0]['subject_type'])->toBe('department')
        ->and($creado[0]['subject_id'])->toBe($id)
        ->and($creado[0]['actor_id'])->toBe($contexto['user'])
        ->and($creado[0]['payload'])->toEqual(['site_id' => $contexto['site']])
        ->and($renombrado)->toHaveCount(1)
        ->and($renombrado[0]['subject_id'])->toBe($id)
        ->and($renombrado[0]['payload'])->toEqual(['changed_fields' => ['name']])
        ->and(str_contains($renombrado[0]['raw'], 'costura'))->toBeFalse();
})->group('RF-GP-02', 'RF-ID-03', 'RL-04');

it('un departamento duplicado no deja asiento', function (): void {
    $contexto = contextoDeAuditoriaDePlantilla();

    Api::as($contexto['token'])->post('/api/v1/departments', ['name' => 'Pisos'])->assertValidResponse(201);
    Api::as($contexto['token'])->post('/api/v1/departments', ['name' => 'Pisos'])->assertValidResponse(409);

    expect(asientosDePlantilla('department.created'))->toHaveCount(1);
})->group('RF-GP-02', 'RL-04');

it('mantiene integra la cadena de audit_log tras alta, cambio, baja y departamentos', function (): void {
    $contexto = contextoDeAuditoriaDePlantilla();

    $uuid = Api::as($contexto['token'])->post('/api/v1/employees', [
        'first_name' => 'Ana',
        'last_name' => 'Soler',
        'hired_at' => '2026-09-14',
    ])->assertValidResponse(201)->json('employee.uuid');

    Api::as($contexto['token'])->patch('/api/v1/employees/'.$uuid, ['locale' => 'en'])->assertValidResponse(200);
    Api::as($contexto['token'])->post('/api/v1/employees/'.$uuid.'/offboard', ['terminated_at' => '2026-09-14'])
        ->assertValidResponse(200);
    Api::as($contexto['token'])->post('/api/v1/departments', ['name' => 'Spa'])->assertValidResponse(201);

    expect(DB::table('audit_log')->whereIn('action', ['employee.hired', 'employee.updated', 'employee.offboarded', 'department.created'])->count())
        ->toBe(4)
        ->and(app(VerifyAuditChain::class)->handle()->isIntact())->toBeTrue();
})->group('RF-GP-01', 'RF-GP-03', 'RS-07', 'RL-04');

it('la cuenta que da de alta es la que figura, no el sistema', function (): void {
    // Sin sesion (consola, asistente) el actor seria `system`; desde el panel
    // tiene que ser la persona. Se comprueba contra el modelo para no depender
    // del orden de ids.
    $contexto = contextoDeAuditoriaDePlantilla();

    Api::as($contexto['token'])->post('/api/v1/departments', ['name' => 'Bar'])->assertValidResponse(201);

    expect(User::query()->whereKey(asientosDePlantilla('department.created')[0]['actor_id'])->exists())->toBeTrue();
})->group('RF-GP-02', 'RL-04');
