<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\DataExportSource;
use App\Modules\Product\Domain\ValueObject\DataExportCatalog;
use App\Modules\Product\Domain\ValueObject\ExportedDataset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;

/*
 * RN-19 en la exportacion integra (RF-PD-14, ADR-043): `scan_events.csv` lleva a
 * quien correspondia el codigo de un PIN rechazado **por su UUID**, nunca por el
 * `id` interno, y si el intento abrio o encontro el bloqueo.
 *
 * El dato es del cliente y tiene que poder salir con el: una exportacion que lo
 * callara seria una copia del registro con menos informacion que el original.
 * Lo que NO lo lleva es el paquete de diagnostico, que va al fabricante
 * (`DiagnosticsOmitsPinClaimTest`).
 */

uses(RefreshDatabase::class);

it('exporta el dueño del codigo por su UUID y el bloqueo, en el orden del catalogo', function (): void {
    $scenario = AttendanceFixtures::scenario();
    $scanId = Str::uuid7()->toString();

    DB::table('scan_events')->insert([
        'scan_id' => $scanId,
        'device_id' => $scenario['device'],
        'employee_id' => null,
        'occurred_at' => '2026-03-14 07:00:00+00',
        'recorded_at' => '2026-03-14 09:00:00+00',
        'origin' => 'pin_kiosk',
        'intent' => 'auto',
        'result' => 'rejected_unknown',
        'flagged_for_review' => false,
        'worked_minutes' => null,
        'client_meta' => '{}',
        'claimed_employee_id' => AttendanceFixtures::employeeIdOf($scenario['employee']),
        'pin_lockout' => true,
    ]);

    $dataset = DataExportCatalog::dataset('scan_events');

    expect($dataset)->toBeInstanceOf(ExportedDataset::class);

    /** @var ExportedDataset $dataset */
    $source = app(DataExportSource::class);

    /** @var list<array<string, mixed>> $rows */
    $rows = $source->within(static fn (): array => iterator_to_array($source->rows($dataset), false));

    $row = collect($rows)->firstWhere('scan_id', $scanId);

    expect($row)->not->toBeNull()
        ->and(array_keys((array) $row))->toBe($dataset->columns())
        ->and($row['claimed_employee_uuid'] ?? null)->toBe($scenario['employee'])
        ->and($row['pin_lockout'] ?? null)->toBe('true')
        ->and($row['employee_uuid'] ?? null)->toBeNull()
        ->and(DataExportCatalog::SCHEMA_VERSION)->toBe('4');
})->group('RN-19', 'RF-PD-14');
