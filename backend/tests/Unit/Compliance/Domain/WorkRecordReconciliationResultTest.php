<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancy;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancyKind;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationResult;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationWindow;

/*
 * El resultado de la conciliacion no admite estados imposibles (ADR-057 §4,
 * V1-AR-4).
 *
 * Es lo que alimenta la metrica y la alerta: un resultado que dijera «coherente»
 * con una discrepancia en la lista, o que contara menos de las que lista,
 * callaria la alerta justo cuando hay algo que mirar. Y cada discrepancia
 * señala lo suyo: sin el tramo, el centro o el asiento, el runbook no tiene por
 * donde empezar.
 */

/**
 * @param  array<string, int>  $recuentos
 * @param  list<WorkRecordDiscrepancy>  $detalle
 */
function resultadoConciliacion(array $recuentos = [], array $detalle = [], int $revisados = 0, ?bool $truncado = null, int $comprobados = 0, int $explican = 0): WorkRecordReconciliationResult
{
    $counts = [...WorkRecordReconciliationResult::emptyCounts(), ...$recuentos];

    return new WorkRecordReconciliationResult(
        WorkRecordReconciliationWindow::full(),
        $comprobados,
        $counts,
        $detalle,
        $truncado ?? array_sum($counts) > count($detalle),
        reviewedPurges: $revisados,
        reviewedPurgesExplainingDeletions: $explican,
    );
}

function resultadoConciliacionSinAsiento(): WorkRecordDiscrepancy
{
    return new WorkRecordDiscrepancy('0199c2a0-0000-7000-8000-000000000001', WorkRecordDiscrepancyKind::EntryWithoutAudit);
}

it('una discrepancia lleva lo que señala su tipo', function (WorkRecordDiscrepancyKind $kind, ?string $uuid, ?int $asiento, ?int $centro, ?int $cambio, string $linea): void {
    $discrepancy = new WorkRecordDiscrepancy($uuid, $kind, ['campo'], $asiento, $centro, $cambio);

    expect($discrepancy->describe())->toBe($linea);
})->with([
    'tramo sin asiento' => [WorkRecordDiscrepancyKind::EntryWithoutAudit, 't1', null, null, null, 'shift_entries t1 · entry_without_audit · campo'],
    'tramo distinto' => [WorkRecordDiscrepancyKind::EntryDiffersFromAudit, 't1', 7, null, null, 'shift_entries t1 · entry_differs_from_audit · campo · audit_log #7'],
    'retirado sin correccion' => [WorkRecordDiscrepancyKind::RetiredWithoutCorrection, 't1', 7, null, null, 'shift_entries t1 · retired_without_correction · campo · audit_log #7'],
    'asiento sin tramo' => [WorkRecordDiscrepancyKind::AuditWithoutEntry, 't1', 7, null, null, 'shift_entries t1 · audit_without_entry · campo · audit_log #7'],
    'purga' => [WorkRecordDiscrepancyKind::PurgeOutOfBounds, null, 7, null, null, 'audit_log #7 · purge_out_of_bounds · campo'],
    'zona del centro con asiento' => [WorkRecordDiscrepancyKind::SiteTimezoneDiffersFromAudit, null, 7, 3, null, 'sites #3 · site_timezone_differs_from_audit · campo · audit_log #7'],
    'zona del centro sin asiento, con traza' => [WorkRecordDiscrepancyKind::SiteTimezoneDiffersFromAudit, null, null, 3, 9, 'sites #3 · site_timezone_differs_from_audit · campo #9'],
])->group('RL-04', 'RS-07', 'RN-23');

it('rechaza una discrepancia que no señala lo que su tipo exige', function (WorkRecordDiscrepancyKind $kind, ?string $uuid, ?int $asiento, ?int $centro, ?int $cambio): void {
    expect(static fn (): WorkRecordDiscrepancy => new WorkRecordDiscrepancy($uuid, $kind, [], $asiento, $centro, $cambio))
        ->toThrow(InvalidArgumentException::class);
})->with([
    // El caso reproducido en la verificacion: `audit_log # · entry_without_audit`.
    'tramo sin asiento y sin tramo' => [WorkRecordDiscrepancyKind::EntryWithoutAudit, null, null, null, null],
    'tramo con uuid vacio' => [WorkRecordDiscrepancyKind::EntryWithoutAudit, '', null, null, null],
    'tramo sin asiento que trae asiento' => [WorkRecordDiscrepancyKind::EntryWithoutAudit, 't1', 7, null, null],
    'tramo distinto sin asiento' => [WorkRecordDiscrepancyKind::EntryDiffersFromAudit, 't1', null, null, null],
    'retirado sin asiento' => [WorkRecordDiscrepancyKind::RetiredWithoutCorrection, 't1', null, null, null],
    'asiento sin tramo sin asiento' => [WorkRecordDiscrepancyKind::AuditWithoutEntry, 't1', null, null, null],
    'asiento sin tramo sin tramo' => [WorkRecordDiscrepancyKind::AuditWithoutEntry, null, 7, null, null],
    'tramo con centro' => [WorkRecordDiscrepancyKind::EntryDiffersFromAudit, 't1', 7, 3, null],
    'tramo con traza de zona' => [WorkRecordDiscrepancyKind::EntryDiffersFromAudit, 't1', 7, null, 9],
    'purga de un tramo' => [WorkRecordDiscrepancyKind::PurgeOutOfBounds, 't1', 7, null, null],
    'purga sin asiento' => [WorkRecordDiscrepancyKind::PurgeOutOfBounds, null, null, null, null],
    'purga con traza de zona' => [WorkRecordDiscrepancyKind::PurgeOutOfBounds, null, 7, null, 9],
    'zona sin centro' => [WorkRecordDiscrepancyKind::SiteTimezoneDiffersFromAudit, null, 7, null, null],
    'zona de un tramo' => [WorkRecordDiscrepancyKind::SiteTimezoneDiffersFromAudit, 't1', 7, 3, null],
])->group('RL-04', 'RS-07');

it('un resultado con una discrepancia listada no puede ser coherente (V1-AR-4)', function (): void {
    // El caso reproducido: recuentos vacios, una discrepancia en la lista y
    // `isConsistent()` respondiendo `true`.
    expect(static fn (): WorkRecordReconciliationResult => new WorkRecordReconciliationResult(
        WorkRecordReconciliationWindow::full(),
        0,
        [],
        [resultadoConciliacionSinAsiento()],
    ))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): WorkRecordReconciliationResult => resultadoConciliacion(detalle: [resultadoConciliacionSinAsiento()], truncado: false))
        ->toThrow(InvalidArgumentException::class);
})->group('RL-04', 'RS-07');

it('acepta el resultado que construye la conciliacion, con detalle completo o truncado', function (): void {
    $una = resultadoConciliacion(['entry_without_audit' => 1], [resultadoConciliacionSinAsiento()], comprobados: 4);
    $truncado = resultadoConciliacion(['entry_without_audit' => 3], [resultadoConciliacionSinAsiento()]);
    $limpio = resultadoConciliacion(comprobados: 10, revisados: 2, explican: 2);

    expect($una->isConsistent())->toBeFalse()
        ->and($una->truncated)->toBeFalse()
        ->and($una->entriesChecked)->toBe(4)
        ->and($truncado->truncated)->toBeTrue()
        ->and($truncado->discrepancyCount())->toBe(3)
        ->and($limpio->isConsistent())->toBeTrue()
        ->and($limpio->reviewedPurgesNotExplainingDeletions())->toBe(0);
})->group('RL-04', 'RS-07');

it('rechaza un resultado incoherente', function (Closure $resultado): void {
    expect($resultado)->toThrow(InvalidArgumentException::class);
})->with([
    'recuentos sin todas las claves' => [static fn (): WorkRecordReconciliationResult => new WorkRecordReconciliationResult(WorkRecordReconciliationWindow::full(), 0, ['entry_without_audit' => 0], [])],
    'recuentos con una clave de mas' => [static fn (): WorkRecordReconciliationResult => resultadoConciliacion(['otra' => 0])],
    'recuentos en otro orden' => [static fn (): WorkRecordReconciliationResult => new WorkRecordReconciliationResult(WorkRecordReconciliationWindow::full(), 0, array_reverse(WorkRecordReconciliationResult::emptyCounts()), [])],
    'tramos comprobados negativos' => [static fn (): WorkRecordReconciliationResult => resultadoConciliacion(comprobados: -5)],
    'un recuento negativo' => [static fn (): WorkRecordReconciliationResult => resultadoConciliacion(['purge_out_of_bounds' => -1])],
    'purgas revisadas negativas' => [static fn (): WorkRecordReconciliationResult => resultadoConciliacion(revisados: -1)],
    'explican mas de las revisadas' => [static fn (): WorkRecordReconciliationResult => resultadoConciliacion(revisados: 1, explican: 2)],
    'lista mas de un tipo de las que cuenta' => [static fn (): WorkRecordReconciliationResult => resultadoConciliacion(['purge_out_of_bounds' => 2], [resultadoConciliacionSinAsiento()])],
    'truncado sin mas de las listadas' => [static fn (): WorkRecordReconciliationResult => resultadoConciliacion(['entry_without_audit' => 1], [resultadoConciliacionSinAsiento()], truncado: true)],
    'completo con mas de las listadas' => [static fn (): WorkRecordReconciliationResult => resultadoConciliacion(['entry_without_audit' => 2], [resultadoConciliacionSinAsiento()], truncado: false)],
])->group('RL-04', 'RS-07');

it('rechaza un recuento negativo en cualquiera de los avisos de la zona del centro', function (string $campo): void {
    expect(static fn (): WorkRecordReconciliationResult => new WorkRecordReconciliationResult(
        WorkRecordReconciliationWindow::full(),
        0,
        WorkRecordReconciliationResult::emptyCounts(),
        [],
        ...[$campo => -1],
    ))->toThrow(InvalidArgumentException::class);
})->with(['reviewedPurgesExplainingDeletions', 'unverifiableSiteTimezones', 'unverifiableSiteTimezoneChanges', 'reviewedSiteTimezoneChanges', 'reviewedSiteTimezoneEntries'])->group('RL-04', 'RN-23');
