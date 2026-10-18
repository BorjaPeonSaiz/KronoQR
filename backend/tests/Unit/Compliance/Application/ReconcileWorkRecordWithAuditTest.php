<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\ReconcileWorkRecordWithAudit;
use App\Modules\Compliance\Domain\ValueObject\AuditedPurge;
use App\Modules\Compliance\Domain\ValueObject\AuditedPurgeReview;
use App\Modules\Compliance\Domain\ValueObject\AuditedSiteTimezone;
use App\Modules\Compliance\Domain\ValueObject\RecordedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\RecordedSiteTimezoneChange;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordAuditContext;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPair;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationScope;
use Tests\Support\Compliance\FixedRetentionYearsFloor;
use Tests\Support\Compliance\InMemoryWorkRecordAuditSource;
use Tests\Support\Compliance\RecordingWorkRecordReconciliationMetrics;
use Tests\Support\Shared\FixedInstallationSiteProvider;
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

    $resultado = new ReconcileWorkRecordWithAudit($origen, $metricas, FixedClock::at('2026-10-08 04:15:00'), new FixedRetentionYearsFloor, new FixedInstallationSiteProvider)
        ->handle(WorkRecordReconciliationScope::Recent, 7);

    expect($resultado->isConsistent())->toBeTrue()
        ->and($resultado->entriesChecked)->toBe(0)
        ->and($resultado->counts)->toBe([
            'entry_without_audit' => 0,
            'entry_differs_from_audit' => 0,
            'retired_without_correction' => 0,
            'audit_without_entry' => 0,
            'purge_out_of_bounds' => 0,
            'site_timezone_differs_from_audit' => 0,
        ])
        ->and($metricas->recorded)->toHaveCount(1)
        ->and($metricas->recorded[0]['at']->format('Y-m-d H:i'))->toBe('2026-10-08 04:15')
        ->and($origen->askedWindows[0]->fromWorkDate)->toBe('2026-10-01');
})->group('RL-04', 'RS-07');

it('pide la pasada completa sin limites cuando se le pide el registro entero', function (): void {
    $origen = new InMemoryWorkRecordAuditSource;

    new ReconcileWorkRecordWithAudit($origen, new RecordingWorkRecordReconciliationMetrics, FixedClock::at('2026-10-11 02:15:00'), new FixedRetentionYearsFloor, new FixedInstallationSiteProvider)
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
    $resultado = new ReconcileWorkRecordWithAudit(new InMemoryWorkRecordAuditSource($pares), $metricas, FixedClock::at('2026-10-08 04:15:00'), new FixedRetentionYearsFloor, new FixedInstallationSiteProvider)
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

    $resultado = new ReconcileWorkRecordWithAudit($origen, new RecordingWorkRecordReconciliationMetrics, FixedClock::at('2026-10-08 04:15:00'), new FixedRetentionYearsFloor, new FixedInstallationSiteProvider)
        ->handle(WorkRecordReconciliationScope::Full, 7);

    expect($resultado->counts['purge_out_of_bounds'])->toBe(1)
        ->and($resultado->entriesChecked)->toBe(0)
        ->and($resultado->isConsistent())->toBeFalse()
        ->and($resultado->discrepancies[0]->auditEntryId)->toBe(91);
})->group('RL-02', 'RL-04', 'RS-07');

it('publica las purgas revisadas aparte, sin contarlas como pendientes', function (): void {
    // ADR-057 enmienda del 10-10-2026 §6: una purga a 3 años en un centro
    // español es no admisible; con su revision deja de ser discrepancia y pasa
    // a `reviewedPurges`, en el resultado y en lo que se publica.
    // La 90 solo falla el plazo: revisada, vuelve a explicar borrados. La 91
    // tiene ademas un corte imposible: revisada, no explica nada. Se cuentan
    // aparte para que la alerta distinga una de otra.
    $purga = new AuditedPurge(90, new DateTimeImmutable('2030-10-08T03:00:00Z'), '2027-10-08', 3, 1);
    $imposible = new AuditedPurge(91, new DateTimeImmutable('2030-10-08T03:00:00Z'), '9999-12-31', 3, 1);
    $origen = new InMemoryWorkRecordAuditSource([], new WorkRecordAuditContext([$purga, $imposible], [], [
        new AuditedPurgeReview(95, new DateTimeImmutable('2030-10-09T09:00:00Z'), 90),
        new AuditedPurgeReview(96, new DateTimeImmutable('2030-10-09T09:05:00Z'), 91),
    ]));
    $metricas = new RecordingWorkRecordReconciliationMetrics;

    $resultado = new ReconcileWorkRecordWithAudit($origen, $metricas, FixedClock::at('2030-10-10 04:15:00'), new FixedRetentionYearsFloor(4), new FixedInstallationSiteProvider)
        ->handle(WorkRecordReconciliationScope::Full, 7);

    expect($resultado->counts['purge_out_of_bounds'])->toBe(0)
        ->and($resultado->isConsistent())->toBeTrue()
        ->and($resultado->reviewedPurges)->toBe(2)
        ->and($resultado->reviewedPurgesExplainingDeletions)->toBe(1)
        ->and($resultado->reviewedPurgesNotExplainingDeletions())->toBe(1)
        ->and($metricas->recorded[0]['result']->reviewedPurges)->toBe(2)
        ->and($metricas->recorded[0]['result']->reviewedPurgesExplainingDeletions)->toBe(1);
})->group('RS-07', 'RL-02', 'RL-04');

it('pregunta el suelo legal una sola vez por cada centro distinto de las purgas', function (): void {
    $suelos = new FixedRetentionYearsFloor(4, [2 => 1]);
    $origen = new InMemoryWorkRecordAuditSource([], new WorkRecordAuditContext([
        new AuditedPurge(90, new DateTimeImmutable('2030-10-08T03:00:00Z'), '2026-10-08', 4, 1),
        new AuditedPurge(91, new DateTimeImmutable('2031-10-08T03:00:00Z'), '2027-10-08', 4, 1),
        new AuditedPurge(92, new DateTimeImmutable('2031-10-08T03:00:00Z'), '2028-10-08', 3, 2),
        new AuditedPurge(93, new DateTimeImmutable('2031-10-08T03:00:00Z'), '2027-10-08', 4, null),
    ]));

    $resultado = new ReconcileWorkRecordWithAudit($origen, new RecordingWorkRecordReconciliationMetrics, FixedClock::at('2032-01-01 04:15:00'), $suelos, new FixedInstallationSiteProvider)
        ->handle(WorkRecordReconciliationScope::Full, 7);

    // El centro 2 tiene suelo 1: su purga a 3 años es admisible. La que no dice
    // centro es la unica rechazada; se juzga con el suelo del centro de la
    // instalacion (el 1), que ya estaba preguntado y no se repite.
    expect($suelos->askedSites)->toBe([1, 2])
        ->and($resultado->counts['purge_out_of_bounds'])->toBe(1)
        ->and($resultado->discrepancies[0]->auditEntryId)->toBe(93)
        ->and($resultado->discrepancies[0]->fields)->toBe(['site_id']);
})->group('RS-07', 'RL-02');

it('juzga una purga sin centro con el suelo del centro de la instalacion, como la revision', function (?int $instalacion, array $defectos, array $preguntados): void {
    $suelos = new FixedRetentionYearsFloor(1, [7 => 4]);
    $origen = new InMemoryWorkRecordAuditSource([], new WorkRecordAuditContext([
        new AuditedPurge(93, new DateTimeImmutable('2031-10-08T03:00:00Z'), '2028-10-08', 3, null),
    ]));

    $resultado = new ReconcileWorkRecordWithAudit($origen, new RecordingWorkRecordReconciliationMetrics, FixedClock::at('2032-01-01 04:15:00'), $suelos, new FixedInstallationSiteProvider($instalacion))
        ->handle(WorkRecordReconciliationScope::Full, 7);

    expect($suelos->askedSites)->toBe($preguntados)
        ->and($resultado->discrepancies[0]->fields)->toBe($defectos);
})->with([
    // El centro de la instalacion es español: a 3 años incumple tambien el plazo.
    'centro de la instalacion con suelo 4' => [7, ['retention_years', 'site_id'], [7]],
    // Sin centro de instalacion no hay suelo que leer: el minimo estructural.
    'sin centro de instalacion' => [null, ['site_id'], []],
])->group('RS-07', 'RL-02');

it('cuenta la zona del centro que no es la de su ultimo asiento, en las dos pasadas', function (WorkRecordReconciliationScope $alcance): void {
    // RN-23, bloque 2 de la 2.3.0: `site_correct_timezone` llamada fuera de la
    // orden de consola, o un `UPDATE` sin tramos por fuera del producto, dejan
    // `sites.timezone` distinta de la del ultimo `site.*`. Es una fila, no una
    // ventana: la ven la diaria y la completa.
    $origen = new InMemoryWorkRecordAuditSource([], new WorkRecordAuditContext([], [], [], [
        AuditedSiteTimezone::fromEntry(1, 'Atlantic/Canary', 42, ['new_value' => ['name' => 'Hotel', 'timezone' => 'Europe/Madrid']]),
    ]));

    $resultado = new ReconcileWorkRecordWithAudit($origen, new RecordingWorkRecordReconciliationMetrics, FixedClock::at('2026-10-08 04:15:00'), new FixedRetentionYearsFloor, new FixedInstallationSiteProvider)
        ->handle($alcance, 7);

    expect($resultado->counts['site_timezone_differs_from_audit'])->toBe(1)
        ->and($resultado->isConsistent())->toBeFalse()
        ->and($resultado->entriesChecked)->toBe(0)
        ->and($resultado->discrepancies[0]->siteId)->toBe(1)
        ->and($resultado->discrepancies[0]->auditEntryId)->toBe(42)
        ->and($resultado->discrepancies[0]->fields)->toBe(['timezone'])
        ->and($resultado->discrepancies[0]->describe())->toBe('sites #1 · site_timezone_differs_from_audit · timezone · audit_log #42');
})->with([
    'diaria' => [WorkRecordReconciliationScope::Recent],
    'completa' => [WorkRecordReconciliationScope::Full],
])->group('RN-23', 'RL-04', 'RS-07');

it('no cuenta la zona que coincide con su ultimo asiento, ni la de un centro sin asiento', function (): void {
    $origen = new InMemoryWorkRecordAuditSource([], new WorkRecordAuditContext([], [], [], [
        AuditedSiteTimezone::fromEntry(1, 'Atlantic/Canary', 42, ['new_value' => ['timezone' => 'Atlantic/Canary']]),
        AuditedSiteTimezone::fromEntry(2, 'Europe/Madrid', null, null),
    ]));

    $resultado = new ReconcileWorkRecordWithAudit($origen, new RecordingWorkRecordReconciliationMetrics, FixedClock::at('2026-10-08 04:15:00'), new FixedRetentionYearsFloor, new FixedInstallationSiteProvider)
        ->handle(WorkRecordReconciliationScope::Full, 7);

    expect($resultado->isConsistent())->toBeTrue();
})->group('RN-23', 'RL-04');

it('cuenta aparte la zona que no se puede verificar, sin hacer la pasada incoherente', function (): void {
    // Un centro sin asiento `site.*` y otro con una fila de la traza de un año
    // cuya auditoria se purgo (2021 sellado): ninguno es discrepancia, los dos
    // salen en el resultado y en lo que se publica.
    $metricas = new RecordingWorkRecordReconciliationMetrics;
    $origen = new InMemoryWorkRecordAuditSource([], new WorkRecordAuditContext([], [2021], [], [
        AuditedSiteTimezone::fromEntry(1, 'Europe/Madrid', null, null),
        AuditedSiteTimezone::fromEntry(2, 'Atlantic/Canary', 42, ['new_value' => ['timezone' => 'Atlantic/Canary']], [], [
            new RecordedSiteTimezoneChange(3, 'Europe/Madrid', 'Atlantic/Canary', new DateTimeImmutable('2021-06-01T10:00:00Z')),
        ]),
    ]));

    $resultado = new ReconcileWorkRecordWithAudit($origen, $metricas, FixedClock::at('2026-10-08 04:15:00'), new FixedRetentionYearsFloor, new FixedInstallationSiteProvider)
        ->handle(WorkRecordReconciliationScope::Full, 7);

    expect($resultado->isConsistent())->toBeTrue()
        ->and($resultado->unverifiableSiteTimezones)->toBe(1)
        ->and($resultado->unverifiableSiteTimezoneChanges)->toBe(1)
        ->and($metricas->recorded[0]['result']->unverifiableSiteTimezones)->toBe(1);
})->group('RN-23', 'RL-04');
