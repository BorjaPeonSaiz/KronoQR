<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditableEvent;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Identity\Application\Command\IssueDeviceTokenCommand;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La evidencia de un plan superado (**ADR-028**, RF-PD-04, RL-04).
 *
 * LO PRIMERO, PORQUE EL NOMBRE INVITA A LEERLO AL REVES: ninguno de estos
 * asientos describe un rechazo. Describen altas que **si se hicieron**. El
 * asiento existe porque ADR-028 lo llama «la prueba que sostiene la reclamacion
 * comercial: la fecha exacta desde la que el cliente opera por encima del plan».
 *
 * Que el alta no se bloquea lo prueba `tests/Feature/Product/PlanLimitsDoNotBlockTest.php`.
 * Esto prueba la otra mitad: que queda constancia.
 *
 * POR QUE NO PODIA SER UNITARIA: el conteo sale de dos tablas, el asiento
 * encadena hashes y el observador corre en `afterCommit`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
});

/**
 * Activa una licencia con los limites indicados.
 */
function licenseWithLimits(int $employees, int $devices): void
{
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(
        LicenseKeys::current()->issue(['max_employees' => $employees, 'max_devices' => $devices]),
    ));
}

/**
 * @return list<array<string, mixed>>
 */
function planExcessPayloads(): array
{
    /** @var list<object{payload: string}> $rows */
    $rows = DB::table('audit_log')->where('action', 'license.plan_exceeded')->orderBy('id')->get()->all();

    return array_map(
        /** @return array<string, mixed> */
        static fn (object $row): array => (array) json_decode((string) $row->payload, true),
        $rows,
    );
}

/**
 * El UUID de la ultima persona dada de alta.
 */
function lastHiredUuid(): string
{
    /** @var string|null $uuid */
    $uuid = DB::table('employees')->orderByDesc('id')->value('uuid');

    return $uuid ?? '';
}

function hireThrough(string $token, string $code): void
{
    Api::as($token)->post('/api/v1/employees', [
        'first_name' => 'Camarero',
        'last_name' => 'De Temporada',
        'hired_at' => '2026-06-01',
        'national_id' => $code,
    ])->assertSuccessful();
}

it('no escribe nada mientras el plan no se supera', function (): void {
    licenseWithLimits(employees: 3, devices: 2);

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    hireThrough($token, '00000001A');
    hireThrough($token, '00000002B');
    hireThrough($token, '00000003C');

    expect(DB::table('employees')->where('status', 'active')->count())->toBe(3)
        ->and(planExcessPayloads())->toBe([]);
})->group('RF-PD-04', 'RL-04');

it('escribe el cruce del umbral con el limite, lo contratado y lo alcanzado', function (): void {
    licenseWithLimits(employees: 2, devices: 2);

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    hireThrough($token, '00000001A');
    hireThrough($token, '00000002B');
    hireThrough($token, '00000003C');

    $payloads = planExcessPayloads();

    expect($payloads)->toHaveCount(1)
        ->and($payloads[0]['limit'])->toBe('max_employees')
        ->and($payloads[0]['contracted'])->toBe(2)
        ->and($payloads[0]['reached'])->toBe(3)
        ->and($payloads[0]['excess'])->toBe(1)
        // El primer asiento es el que da la fecha desde la que se opera fuera de
        // contrato, que es lo que sostiene una reclamacion.
        ->and($payloads[0]['first_crossing'])->toBeTrue()
        // Lo que este asiento NO significa, escrito dentro del propio asiento:
        // dentro de dos años, quien lo lea no tendra el docblock delante.
        ->and($payloads[0]['operation_blocked'])->toBeFalse();
})->group('RF-PD-04', 'RL-04');

it('escribe tambien cada alta posterior en exceso, distinguiendola del cruce', function (): void {
    licenseWithLimits(employees: 1, devices: 2);

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    hireThrough($token, '00000001A');
    hireThrough($token, '00000002B');
    hireThrough($token, '00000003C');
    hireThrough($token, '00000004D');

    $payloads = planExcessPayloads();

    expect($payloads)->toHaveCount(3)
        ->and(array_column($payloads, 'first_crossing'))->toBe([true, false, false])
        ->and(array_column($payloads, 'reached'))->toBe([2, 3, 4])
        ->and(array_column($payloads, 'excess'))->toBe([1, 2, 3]);
})->group('RF-PD-04', 'RL-04');

it('cuenta solo la plantilla activa: una baja libera plaza', function (): void {
    // Un hotel de temporada acumula bajas cada año. Contarlas convertiria
    // cualquier instalacion en un exceso permanente al tercer verano, sin que
    // hubiera crecido nada.
    licenseWithLimits(employees: 2, devices: 2);

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    hireThrough($token, '00000001A');
    $uuid = lastHiredUuid();
    hireThrough($token, '00000002B');

    WorkforceFixtures::terminate($uuid);

    hireThrough($token, '00000003C');

    expect(planExcessPayloads())->toBe([]);
})->group('RF-PD-04', 'RL-04');

it('cuenta los quioscos al emparejar, y no al rotar su token', function (): void {
    licenseWithLimits(employees: 50, devices: 1);

    $first = AttendanceFixtures::device(WorkforceFixtures::onlySiteId(), 'Recepcion');
    $second = AttendanceFixtures::device(WorkforceFixtures::onlySiteId(), 'Cocina');

    $issue = app(IssueDeviceToken::class);

    // Emparejamiento del segundo quiosco: la instalacion pasa a 2 con 1
    // contratado. El token se emite igual (ADR-028).
    $issue->handle(new IssueDeviceTokenCommand($second['uuid'], rotation: false, actorUserId: null));

    expect(planExcessPayloads())->toHaveCount(1)
        ->and(planExcessPayloads()[0]['limit'])->toBe('max_devices')
        ->and(planExcessPayloads()[0]['contracted'])->toBe(1)
        ->and(planExcessPayloads()[0]['reached'])->toBe(2);

    // La ROTACION automatica al 80 % de vida no es un alta y no vuelve a
    // contar: si lo hiciera, cada quiosco produciria un asiento cada tres meses
    // sin que nada hubiera cambiado, y el trail dejaria de servir para lo que
    // existe.
    $issue->handle(new IssueDeviceTokenCommand($first['uuid'], rotation: true, actorUserId: null));
    $issue->handle(new IssueDeviceTokenCommand($second['uuid'], rotation: true, actorUserId: null));

    expect(planExcessPayloads())->toHaveCount(1);
})->group('RF-PD-04', 'RL-04');

it('no escribe nada sin licencia activada', function (): void {
    // Una instalacion recien puesta en marcha no esta en exceso: esta sin
    // activar, y el banner de «sin licencia» ya lo dice. Inventar un limite
    // llenaria el trail de asientos desde el primer empleado.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    hireThrough($token, '00000001A');
    hireThrough($token, '00000002B');

    expect(DB::table('license')->count())->toBe(0)
        ->and(planExcessPayloads())->toBe([]);
})->group('RF-PD-04', 'RL-04');

it('el asiento del exceso pertenece a la familia del bloque D de la licencia', function (): void {
    licenseWithLimits(employees: 1, devices: 2);

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    hireThrough($token, '00000001A');
    hireThrough($token, '00000002B');

    expect(AuditAction::LicensePlanExceeded->event())
        ->toBe(AuditableEvent::LicenseLifecycle)
        ->and(DB::table('audit_log')->where('action', 'license.plan_exceeded')->count())->toBe(1);
})->group('RF-PD-04', 'RL-04');

it('sin datos personales en el asiento del exceso', function (): void {
    // Regla dura 21: cifras y nombres de limite. Quien se dio de alta ya tiene
    // su propio asiento.
    licenseWithLimits(employees: 1, devices: 2);

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    hireThrough($token, '00000001A');
    hireThrough($token, '00000002B');

    /** @var string|null $payload */
    $payload = DB::table('audit_log')->where('action', 'license.plan_exceeded')->value('payload');

    expect((string) $payload)->not->toContain('Camarero')
        ->and((string) $payload)->not->toContain('De Temporada')
        ->and((string) $payload)->not->toContain('00000002B');
})->group('RF-PD-04', 'RL-04');
