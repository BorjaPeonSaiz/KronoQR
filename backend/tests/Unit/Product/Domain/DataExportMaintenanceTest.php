<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Event\DataExportFileMissing;
use App\Modules\Product\Domain\ValueObject\DataExportMaintenance;
use App\Modules\Reporting\Domain\Event\ReportExportFileMissing;
use App\Modules\Reporting\Domain\ValueObject\ReportExportMaintenance;

/*
 * Lo que dice la pasada de mantenimiento y los dos hechos nuevos de la
 * conciliacion (ADR-045 §d, condicion C5).
 */

it('la pasada no hizo nada solo si las cuatro cifras son cero', function (int $purgadas, int $liberadas, int $huerfanos, int $desaparecidos, bool $nada): void {
    expect((new DataExportMaintenance($purgadas, $liberadas, $huerfanos, $desaparecidos))->didNothing())->toBe($nada);
})->with([
    'todo a cero' => [0, 0, 0, 0, true],
    'una purgada' => [1, 0, 0, 0, false],
    'una liberada' => [0, 1, 0, 0, false],
    'un huerfano' => [0, 0, 1, 0, false],
    'una desaparecida' => [0, 0, 0, 1, false],
])->group('RF-PD-14');

it('las cifras nuevas valen cero por defecto', function (): void {
    $exportacion = new DataExportMaintenance(2, 3);
    $informe = new ReportExportMaintenance(2, 3);

    expect([$exportacion->orphans, $exportacion->missing, $informe->orphans, $informe->missing])->toBe([0, 0, 0, 0])
        ->and($informe->purged)->toBe(2)
        ->and($informe->released)->toBe(3);
})->group('RF-PD-14', 'RF-IN-06');

it('los dos hechos llevan uuid y caducidad y nada mas, con su nombre estable', function (): void {
    $detectado = new DateTimeImmutable('2026-10-02T12:00:00+00:00');

    $exportacion = new DataExportFileMissing('019a0000-0000-7000-8000-000000000001', '2026-10-09T12:00:00+00:00', $detectado);
    $informe = new ReportExportFileMissing('019a0000-0000-7000-8000-000000000002', '2026-10-09T12:00:00+00:00', $detectado);

    expect($exportacion->eventName())->toBe('product.data_export_file_missing')
        ->and($exportacion->occurredAt())->toBe($detectado)
        ->and($exportacion->uuid)->toBe('019a0000-0000-7000-8000-000000000001')
        ->and($exportacion->expiresAt)->toBe('2026-10-09T12:00:00+00:00')
        ->and($informe->eventName())->toBe('reporting.report_export_file_missing')
        ->and($informe->occurredAt())->toBe($detectado)
        ->and($informe->uuid)->toBe('019a0000-0000-7000-8000-000000000002');

    // Regla dura 21: ninguna propiedad publica para una ruta o un nombre de fichero.
    expect(array_keys(get_object_vars($exportacion)))->toBe(['uuid', 'expiresAt'])
        ->and(array_keys(get_object_vars($informe)))->toBe(['uuid', 'expiresAt']);
})->group('RL-15', 'RF-PD-14', 'RF-IN-06');
