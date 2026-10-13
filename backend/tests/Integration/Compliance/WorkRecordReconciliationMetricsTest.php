<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationResult;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationWindow;
use App\Modules\Compliance\Infrastructure\Metrics\TextfileWorkRecordReconciliationMetrics;
use Illuminate\Support\Facades\Config;

/*
 * Lo que publica `TextfileWorkRecordReconciliationMetrics` de las purgas
 * revisadas (ADR-057 enmienda del 10-10-2026 §6): una serie por valor de
 * `explains_deletions`, las dos siempre, tambien a cero. La alerta
 * `PurgaFueraDeLimitesRevisada` las distingue: la revision que vuelve a
 * explicar borrados cambia lo que la conciliacion acepta.
 */

/**
 * @return list<string> Las lineas de `work_record_reconciliation_reviewed_purges` del fichero.
 */
function metricasConciliacionRevisadas(int $revisadas, int $explican): array
{
    $directorio = sys_get_temp_dir().'/kronoqr-revisadas-'.bin2hex(random_bytes(6));
    Config::set('observability.metrics.textfile_path', $directorio);

    new TextfileWorkRecordReconciliationMetrics()->record(
        new WorkRecordReconciliationResult(
            WorkRecordReconciliationWindow::full(),
            0,
            WorkRecordReconciliationResult::emptyCounts(),
            [],
            reviewedPurges: $revisadas,
            reviewedPurgesExplainingDeletions: $explican,
        ),
        new DateTimeImmutable('2030-10-10T04:15:00Z'),
    );

    $contenido = (string) file_get_contents($directorio.'/kronoqr_work_record_reconciliation_full.prom');

    return array_values(array_filter(
        explode("\n", $contenido),
        static fn (string $linea): bool => str_starts_with($linea, 'work_record_reconciliation_reviewed_purges{'),
    ));
}

it('publica las purgas revisadas partidas por si explican borrados, las dos series siempre', function (int $revisadas, int $explican, int $noExplican): void {
    expect(metricasConciliacionRevisadas($revisadas, $explican))->toBe([
        'work_record_reconciliation_reviewed_purges{scope="full",explains_deletions="true"} '.$explican,
        'work_record_reconciliation_reviewed_purges{scope="full",explains_deletions="false"} '.$noExplican,
    ]);
})->with([
    'ninguna' => [0, 0, 0],
    'una que explica' => [1, 1, 0],
    'una que no explica' => [1, 0, 1],
    'de las dos' => [3, 1, 2],
])->group('RS-07', 'RL-04');
