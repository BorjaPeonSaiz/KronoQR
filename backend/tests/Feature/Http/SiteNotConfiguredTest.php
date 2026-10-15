<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;

/*
 * Antes de la puesta en marcha no hay centro (RF-PD-03, ADR-040), y las
 * operaciones que lo necesitan responden `409` con `problem+json`: es un estado
 * de la instalacion, no un fallo de la peticion (`bootstrap/app.php`, traduccion
 * de `InstallationSiteMissing`).
 *
 * Estas cinco ya lo devolvian y el contrato no lo declaraba (CT3 de la
 * verificacion de la 2.1.0): un cliente generado del contrato no tenia el `409`
 * tipado y el panel lo habria tratado como un error sin explicacion. Desde la
 * 2.2.1 lo declaran con `SiteNotConfigured`, y aqui se valida contra el
 * contrato.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    FrozenTime::at('2026-03-14 09:00:00');
});

it('responde con el 409 del contrato a una consulta del panel que necesita el centro', function (string $ruta): void {
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get($ruta)
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');
})->with([
    'bandeja de incidencias' => ['/api/v1/incidents'],
    'estado de las credenciales' => ['/api/v1/credentials/status'],
    'resumen de cumplimiento' => ['/api/v1/compliance/summary'],
    'presencia en vivo' => ['/api/v1/attendance/live'],
])->group('RF-PD-03', 'RF-PA-01', 'RF-PA-05', 'RF-PA-06', 'RF-QR-08');

it('no vincula un quiosco sin centro, con el 409 del contrato y no con el rechazo del codigo', function (): void {
    // El `409` va ANTES de mirar el codigo: no hay centro al que vincularlo, y
    // confundirlo con «codigo no valido» mandaria al administrador a pedir otro
    // codigo a la tablet una y otra vez.
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->post('/api/v1/kiosk/pair/confirm', ['code' => '123456', 'name' => 'Recepcion'])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');
})->group('RF-PD-03', 'RF-PD-06');
