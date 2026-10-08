<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\ReconcileWorkRecordWithAudit;
use App\Modules\Compliance\Domain\ValueObject\AuditedPurge;
use App\Modules\Compliance\Domain\ValueObject\RecordedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordAuditContext;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPair;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationScope;
use Tests\Support\Compliance\FixedRetentionYearsFloor;
use Tests\Support\Compliance\InMemoryWorkRecordAuditSource;
use Tests\Support\Compliance\RecordingWorkRecordReconciliationMetrics;
use Tests\Support\Time\FixedClock;

/*
 * El caso de uso de la conciliacion (ADR-057 §4) con el registro en memoria:
 * que ventana pide, que cuenta y que publica. La regla de comparacion es de
 * tests/Unit/Compliance/Domain/WorkRecordReconciliationTest.php.
 */

/** Un tramo sin asiento: la discrepancia mas sencilla de fabricar. */
function conciliacionCasoDeUsoTramoSinAsiento(int $n): WorkRecordPair
{
    $uuid = sprintf('0199a1b2-0000-7000-8000-%012d', $n);

    return new WorkRecordPair($uuid, new RecordedShiftEntry(
        uuid: $uuid,
        employeeUuid: '0199a1b2-0000-7000-8000-00000000e001',
        siteId: 1,
        workDate: '2026-10-07',
        clockedInAt: '2026-10-07T06:00:00.000000Z',
        clockedOutAt: null,
        durationMinutes: null,
        status: 'open',
        version: 1,
        supersededByUuid: null,
        clockInSource: 'qr_kiosk',
        clockOutSource: null,
    ), null);
}

it('publica la pasada limpia tambien, con la marca de su ejecucion', function (): void {
    $metricas = new RecordingWorkRecordReconciliationMetrics;
    $origen = new InMemoryWorkRecordAuditSource;

    $resultado = new ReconcileWorkRecordWithAudit($origen, $metricas, FixedClock::at('2026-10-08 04:15:00'), new FixedRetentionYearsFloor)
        ->handle(WorkRecordReconciliationScope::Recent, 7);

    expect($resultado->isConsistent())->toBeTrue()
        ->and($resultado->entriesChecked)->toBe(0)
        ->and($resultado->counts)->toBe([
            'entry_without_audit' => 0,
            'entry_differs_from_audit' => 0,
            'retired_without_correction' => 0,
            'audit_without_entry' => 0,
            'purge_out_of_bounds' => 0,
        ])
        ->and($metricas->recorded)->toHaveCount(1)
        ->and($metricas->recorded[0]['at']->format('Y-m-d H:i'))->toBe('2026-10-08 04:15')
        ->and($origen->askedWindows[0]->fromWorkDate)->toBe('2026-10-01');
})->group('RL-04', 'RS-07');

it('pide la pasada completa sin limites cuando se le pide el registro entero', function (): void {
    $origen = new InMemoryWorkRecordAuditSource;

    new ReconcileWorkRecordWithAudit($origen, new RecordingWorkRecordReconciliationMetrics, FixedClock::at('2026-10-11 02:15:00'), new FixedRetentionYearsFloor)
        ->handle(WorkRecordReconciliationScope::Full, 7);

    expect($origen->askedWindows[0]->scope)->toBe(WorkRecordReconciliationScope::Full)
        ->and($origen->askedWindows[0]->auditSince)->toBeNull()
        ->and($origen->askedWindows[0]->fromWorkDate)->toBeNull();
})->group('RL-04', 'RS-07');

it('cuenta todas las discrepancias y conserva el detalle solo de las primeras', function (): void {
    $pares = [];

    for ($n = 1; $n <= ReconcileWorkRecordWithAudit::MAX_DETAILED_DISCREPANCIES + 3; $n++) {
        $pares[] = conciliacionCasoDeUsoTramoSinAsiento($n);
    }

    $metricas = new RecordingWorkRecordReconciliationMetrics;
    $resultado = new ReconcileWorkRecordWithAudit(new InMemoryWorkRecordAuditSource($pares), $metricas, FixedClock::at('2026-10-08 04:15:00'), new FixedRetentionYearsFloor)
        ->handle(WorkRecordReconciliationScope::Recent, 7);

    // El recuento dice el tamaño del incidente; el detalle, por donde empezar.
    expect($resultado->counts['entry_without_audit'])->toBe(ReconcileWorkRecordWithAudit::MAX_DETAILED_DISCREPANCIES + 3)
        ->and($resultado->discrepancies)->toHaveCount(ReconcileWorkRecordWithAudit::MAX_DETAILED_DISCREPANCIES)
        ->and($resultado->truncated)->toBeTrue()
        ->and($resultado->isConsistent())->toBeFalse()
        ->and($metricas->recorded[0]['result']->discrepancyCount())->toBe(ReconcileWorkRecordWithAudit::MAX_DETAILED_DISCREPANCIES + 3);
})->group('RL-04', 'RS-07');

it('cuenta como discrepancia un asiento de purga que no se puede creer, aunque no haya ningun tramo', function (): void {
    $purgaFalsa = new AuditedPurge(91, new DateTimeImmutable('2026-10-08T03:00:00Z'), '9999-12-31', 4, 1);
    $origen = new InMemoryWorkRecordAuditSource([], new WorkRecordAuditContext([$purgaFalsa]));

    $resultado = new ReconcileWorkRecordWithAudit($origen, new RecordingWorkRecordReconciliationMetrics, FixedClock::at('2026-10-08 04:15:00'), new FixedRetentionYearsFloor)
        ->handle(WorkRecordReconciliationScope::Full, 7);

    expect($resultado->counts['purge_out_of_bounds'])->toBe(1)
        ->and($resultado->entriesChecked)->toBe(0)
        ->and($resultado->isConsistent())->toBeFalse()
        ->and($resultado->discrepancies[0]->auditEntryId)->toBe(91);
})->group('RL-02', 'RL-04', 'RS-07');
