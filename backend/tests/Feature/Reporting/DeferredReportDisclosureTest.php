<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\PayrollDocumentWriter;
use App\Modules\Reporting\Application\UseCase\GenerateReportExportHandler;
use App\Modules\Reporting\Domain\Exception\ReportExportWriteFailed;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\ReportExports;
use Tests\Support\Reporting\UnavailablePayrollDocumentWriter;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * El informe en diferido deja su asiento de lectura a nombre de quien lo pidio
 * (tarea 11.4, V4-BE-5; RS-05, RL-15, enmienda de ADR-037):
 * `personal_data.accessed` en la misma transaccion que `report_export.generated`,
 * con el `report_export_uuid` y `deferred: true`, y ninguno a nombre de `system`.
 * Si la generacion falla, no hay asiento de lectura.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    LicenseKeys::grantAll();
    ReportExports::useTemporaryPath();
});

afterEach(function (): void {
    ReportExports::cleanUpTemporaryPath();
});

/**
 * @return list<array{id: int, action: string, actor_type: string, actor_id: int|null, payload: array<string, mixed>}>
 */
function asientosDelDiferido(): array
{
    return DB::table('audit_log')
        ->whereIn('action', ['report_export.generated', 'personal_data.accessed'])
        ->orderBy('id')
        ->get(['id', 'action', 'actor_type', 'actor_id', 'payload'])
        ->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'action' => (string) $row->action,
            'actor_type' => (string) $row->actor_type,
            'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
            'payload' => (array) json_decode((string) $row->payload, true),
        ])
        ->values()
        ->all();
}

it('deja la lectura a nombre de quien lo pidio, junto al asiento de generacion', function (ReportExportKind $kind, string $dataset): void {
    $persona = ManagementUsers::withRole(UserRole::RRHH);
    $export = ReportExports::pendingFor($persona->id, kind: $kind);

    app(GenerateReportExportHandler::class)->handle($export->uuid);

    $asientos = asientosDelDiferido();

    expect(array_column($asientos, 'action'))->toBe(['report_export.generated', 'personal_data.accessed'])
        // Seguidos: los escribe el mismo listener en la misma transaccion.
        ->and($asientos[1]['id'])->toBe($asientos[0]['id'] + 1)
        ->and($asientos[1]['actor_type'])->toBe('user')
        ->and($asientos[1]['actor_id'])->toBe($persona->id)
        ->and($asientos[1]['payload'])->toMatchArray([
            'dataset' => $dataset,
            'report_export_uuid' => $export->uuid,
            'deferred' => true,
            'format' => 'csv',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'scope' => 'all',
        ])
        ->and($asientos[1]['payload'])->toHaveKey('record_count');
})->with([
    'informe por periodo' => [ReportExportKind::Period, 'period_report'],
    'nomina' => [ReportExportKind::Payroll, 'payroll_export'],
])->group('RS-05', 'RL-15', 'RF-IN-06');

it('no deja ninguna lectura a nombre del sistema', function (): void {
    $export = ReportExports::pendingFor(ManagementUsers::withRole(UserRole::RRHH)->id);

    app(GenerateReportExportHandler::class)->handle($export->uuid);

    expect(DB::table('audit_log')->where('action', 'personal_data.accessed')->where('actor_type', 'system')->count())
        ->toBe(0);
})->group('RS-05', 'RL-15', 'RF-IN-06');

it('si la generacion falla, no hay asiento de lectura', function (): void {
    app()->bind(PayrollDocumentWriter::class, UnavailablePayrollDocumentWriter::class);
    app()->forgetInstance(GenerateReportExportHandler::class);

    $export = ReportExports::pendingFor(ManagementUsers::withRole(UserRole::RRHH)->id, kind: ReportExportKind::Payroll);

    expect(static fn () => app(GenerateReportExportHandler::class)->handle($export->uuid))
        ->toThrow(ReportExportWriteFailed::class);

    expect(asientosDelDiferido())->toBe([]);
})->group('RS-05', 'RF-IN-07');
