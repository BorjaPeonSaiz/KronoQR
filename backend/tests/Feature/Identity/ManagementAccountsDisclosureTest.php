<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Listar las cuentas de gestion deja asiento de lectura (tarea 11.4, V4-BE-4;
 * RS-05, RL-15, enmienda de ADR-037): `personal_data.accessed` con conjunto
 * `management_accounts`, el numero de cuentas entregadas y los filtros, nunca
 * el termino buscado. Por la API a nombre de quien lista; por consola
 * (`identity:list-users`), con actor `system`. El alta no deja asiento de
 * lectura.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    config()->set('identity.two_factor.required_roles', []);
    RateLimiter::clear('auth-ip:127.0.0.1');
});

/**
 * @return list<array{actor_type: string, actor_id: int|null, payload: array<string, mixed>}>
 */
function lecturasDeCuentas(): array
{
    return DB::table('audit_log')
        ->where('action', 'personal_data.accessed')
        ->where('payload->dataset', 'management_accounts')
        ->orderBy('id')
        ->get(['actor_type', 'actor_id', 'payload'])
        ->map(static fn (object $row): array => [
            'actor_type' => (string) $row->actor_type,
            'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
            'payload' => (array) json_decode((string) $row->payload, true),
        ])
        ->values()
        ->all();
}

it('el listado deja asiento con el recuento, los filtros y la busqueda como si o no', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withRole(UserRole::RRHH);

    Api::as(ManagementUsers::tokenFor($admin))
        ->get('/api/v1/management-accounts?role=rrhh&status=active&q=direccion%40hotel')
        ->assertOk();

    $asientos = lecturasDeCuentas();

    expect($asientos)->toHaveCount(1)
        ->and($asientos[0]['actor_type'])->toBe('user')
        ->and($asientos[0]['actor_id'])->toBe($admin->id)
        ->and($asientos[0]['payload'])->toMatchArray([
            'dataset' => 'management_accounts',
            'role' => 'rrhh',
            'status' => 'active',
            'search' => true,
            'page' => 1,
        ])
        ->and(json_encode($asientos[0]['payload']))->not->toContain('direccion');
})->group('RS-05', 'RL-15', 'RF-ID-10');

it('cuenta las cuentas que de verdad entrega la pagina', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    ManagementUsers::withRole(UserRole::AUDITOR);

    $pagina = Api::as(ManagementUsers::tokenFor($admin))->get('/api/v1/management-accounts')->assertOk();

    expect(lecturasDeCuentas()[0]['payload']['record_count'] ?? null)->toBe(\count((array) $pagina->json('data')))
        ->and(lecturasDeCuentas()[0]['payload']['search'] ?? null)->toBeFalse()
        ->and(lecturasDeCuentas()[0]['payload'])->not->toHaveKey('role');
})->group('RS-05', 'RL-15', 'RF-ID-10');

it('si el asiento no se puede escribir, no hay listado', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    app()->instance(PersonalDataAccessLog::class, new class implements PersonalDataAccessLog
    {
        public function recordDisclosure(string $dataset, int $recordCount, array $context = []): void
        {
            throw new RuntimeException('audit_log no responde');
        }
    });

    $respuesta = Api::as(ManagementUsers::tokenFor($admin))->get('/api/v1/management-accounts');

    expect($respuesta->status())->toBe(500)
        ->and($respuesta->json('data'))->toBeNull();
})->group('RS-05', 'RF-ID-10');

it('el alta de una cuenta no deja asiento de lectura', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    Api::as(ManagementUsers::tokenFor($admin))->post('/api/v1/management-accounts', [
        'name' => 'Direccion RRHH',
        'email' => 'rrhh-nueva@hotel.example',
        'role' => 'rrhh',
        'actor_current_password' => ManagementUsers::PASSWORD,
    ])->assertStatus(201);

    expect(lecturasDeCuentas())->toBe([]);
})->group('RS-05', 'RF-ID-10');

it('identity:list-users deja asiento a nombre del sistema', function (): void {
    ManagementUsers::withRole(UserRole::ADMIN);

    expect(Artisan::call('identity:list-users', ['--role' => 'admin']))->toBe(0);

    $asientos = lecturasDeCuentas();

    expect($asientos)->toHaveCount(1)
        ->and($asientos[0]['actor_type'])->toBe('system')
        ->and($asientos[0]['payload'])->toMatchArray(['role' => 'admin', 'record_count' => 1, 'search' => false]);
})->group('RS-05', 'RL-15', 'RF-ID-10');

it('no deja asiento a quien no puede listar', function (UserRole $rol): void {
    ManagementUsers::withRole(UserRole::ADMIN);

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole($rol)))
        ->get('/api/v1/management-accounts')
        ->assertStatus(403);

    expect(lecturasDeCuentas())->toBe([]);
})->with([
    'rrhh' => [UserRole::RRHH],
    'auditor' => [UserRole::AUDITOR],
    'responsable' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RS-05', 'RQ-07', 'RF-ID-10');
