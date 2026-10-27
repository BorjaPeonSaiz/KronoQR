<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\FakeCardRenderer;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Escenario «Alta de empleado y emisión de credencial» del doc 01 §11 (G7 de
 * la verificacion 2.1.0), tal como lo hace el producto: **el alta y la
 * emision son dos actos**. El alta no emite nada —ningun listener de Identity
 * escucha `EmployeeHired`—, y la emision la pide RRHH despues (ADR-034). Lo que
 * el escenario promete se afirma sobre ese recorrido: el alta sin correo, la
 * credencial pendiente de imprimir en el panel de estado y los dos PDF, el de
 * tarjeta y la hoja A4.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    FrozenTime::at('2026-08-14 07:30:00');
    app()->instance(CardRenderer::class, new FakeCardRenderer);
    WorkforceFixtures::site();
});

/**
 * Youssef dado de alta por RRHH sin correo.
 *
 * @return array{token: string, employee: string}
 */
function altaYEmisionYoussef(): array
{
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $employee = Api::as($token)->post('/api/v1/employees', [
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'hired_at' => '2026-08-14',
    ])->assertStatus(201)->json('employee.uuid');

    expect($employee)->toBeString();

    /** @var string $employee */
    return ['token' => $token, 'employee' => $employee];
}

/** RRHH emite la credencial de esa persona; devuelve su uuid. */
function altaYEmisionEmitir(string $token, string $employee): string
{
    $credential = Api::as($token)->post('/api/v1/credentials', ['employee_uuid' => $employee])
        ->assertStatus(201)
        ->json('uuid');

    expect($credential)->toBeString();

    /** @var string $credential */
    return $credential;
}

it('completa el alta sin direccion de correo y sin emitir todavia ninguna credencial', function (): void {
    $alta = altaYEmisionYoussef();

    expect(DB::table('employees')->where('uuid', $alta['employee'])->value('email'))->toBeNull()
        ->and(DB::table('credentials')->count())->toBe(0);
})->group('RF-GP-01', 'RF-QR-01');

it('muestra la credencial emitida como pendiente de imprimir en el panel de estado', function (): void {
    $alta = altaYEmisionYoussef();
    altaYEmisionEmitir($alta['token'], $alta['employee']);

    Api::as($alta['token'])->get('/api/v1/credentials/status', ['employee_uuid' => $alta['employee']])
        ->assertStatus(200)
        ->assertJsonPath('data.0.status', 'pending_print')
        ->assertJsonPath('summary.pending_print', 1);
})->group('RF-QR-01', 'RF-QR-08');

it('deja imprimir la credencial emitida en formato tarjeta', function (): void {
    $alta = altaYEmisionYoussef();
    $credential = altaYEmisionEmitir($alta['token'], $alta['employee']);

    Api::as($alta['token'])->post('/api/v1/credentials/'.$credential.'/print')
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
})->group('RF-QR-01', 'RF-QR-04');

it('deja imprimir la credencial emitida en la hoja A4 del lote', function (): void {
    $alta = altaYEmisionYoussef();
    $credential = altaYEmisionEmitir($alta['token'], $alta['employee']);

    Api::as($alta['token'])->post('/api/v1/credentials/print-batch')
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');

    expect(DB::table('credentials')->where('uuid', $credential)->value('printed_at'))->not->toBeNull();
})->group('RF-QR-01', 'RF-QR-04');
