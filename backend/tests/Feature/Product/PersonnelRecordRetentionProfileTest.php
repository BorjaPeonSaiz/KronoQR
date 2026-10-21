<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Port\RetentionPolicyProvider;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spectator\Spectator;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Los años de la ficha de personal en el perfil de cumplimiento (ADR-060,
 * tarea 11.3 A; RL-11, regla dura 14): 4 de serie, editables entre 1 y 30 por
 * `PATCH /compliance-profile`, y es lo que lee la retencion.
 */

uses(CommittedDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();
});

function fichaAdminToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

it('se entrega con 4 años y con la forma del contrato', function (): void {
    Api::as(fichaAdminToken())->get('/api/v1/compliance-profile')
        ->assertValidResponse(200)
        ->assertJsonPath('data.personnel_record_retention_years', 4);
})->group('RL-11', 'RF-PD-07');

it('se cambia por el perfil y llega a la politica de retencion', function (): void {
    Api::as(fichaAdminToken())
        ->patch('/api/v1/compliance-profile', ['personnel_record_retention_years' => 6])
        ->assertValidResponse(200)
        ->assertJsonPath('data.personnel_record_retention_years', 6);

    app()->forgetScopedInstances();

    expect(DB::table('compliance_profiles')->where('is_default', true)->value('personnel_record_retention_years'))->toBe(6)
        ->and(app(RetentionPolicyProvider::class)->forInstallation()->personnelRecordYears)->toBe(6)
        ->and(app(RetentionPolicyProvider::class)->snapshot()->personnelRecordYears)->toBe(6);
})->group('RL-11', 'RF-PD-07');

it('rechaza un plazo fuera de 1 a 30 señalando el campo', function (int $value): void {
    Api::as(fichaAdminToken())
        ->patch('/api/v1/compliance-profile', ['personnel_record_retention_years' => $value])
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['personnel_record_retention_years']]);
})->with([0, 31])->group('RL-11', 'RF-PD-07');

it('no lo cambia quien no administra el perfil', function (UserRole $rol): void {
    Auth::forgetGuards();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole($rol)))
        ->patch('/api/v1/compliance-profile', ['personnel_record_retention_years' => 1])
        ->assertStatus(403);

    expect(DB::table('compliance_profiles')->where('is_default', true)->value('personnel_record_retention_years'))->toBe(4);
})->with([
    'rrhh' => [UserRole::RRHH],
    'auditor' => [UserRole::AUDITOR],
    'responsable' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RL-11', 'RS-05', 'RQ-07');
