<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Metrics;

use App\Modules\Compliance\Application\Port\WorkRecordReconciliationMetrics;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationResult;
use App\Modules\Shared\Infrastructure\Metrics\TextfileExposition;
use DateTimeImmutable;

/**
 * Publica la conciliacion entre el registro horario y su auditoria para el
 * colector *textfile* de `node-exporter` (ADR-057 §4).
 *
 * **Un fichero por alcance**, `kronoqr_work_record_reconciliation_recent.prom` y
 * `..._full.prom`. Si las dos pasadas escribieran el mismo fichero, la diaria de
 * manana borraria lo que la semanal encontro hoy en un tramo antiguo, y la
 * alerta se apagaria sola sin que nadie hubiera hecho nada. Los `# HELP` y
 * `# TYPE` son identicos en los dos, que es lo que `node-exporter` exige para
 * juntar una misma familia de varios ficheros.
 *
 * **Gauges, no contadores.** Al contrario que la cadena de hash —que no se
 * repara—, un tramo descuadrado se puede devolver a lo que dice su asiento, y
 * entonces la siguiente pasada da cero y la alerta se apaga. Un contador
 * acumulado la dejaria sonando para siempre, que es la forma mas rapida de que
 * nadie vuelva a creersela.
 *
 * **Sin datos personales**: recuentos por tipo y la marca de la ejecucion. Ni un
 * identificador de tramo ni de persona (regla dura 21).
 */
final readonly class TextfileWorkRecordReconciliationMetrics implements WorkRecordReconciliationMetrics
{
    public const string FILE_PREFIX = 'kronoqr_work_record_reconciliation_';

    public function record(WorkRecordReconciliationResult $result, DateTimeImmutable $at): void
    {
        $scope = $result->scope()->value;
        $lines = [
            '# HELP work_record_reconciliation_discrepancies Tramos de shift_entries que no cuadran con su ultimo asiento de audit_log en la ultima conciliacion, por tipo (ADR-057, RL-04). Debe ser siempre cero.',
            '# TYPE work_record_reconciliation_discrepancies gauge',
        ];

        foreach ($result->counts as $kind => $count) {
            $lines[] = 'work_record_reconciliation_discrepancies{scope="'.$scope.'",kind="'.TextfileExposition::escapeLabel($kind).'"} '.$count;
        }

        $lines[] = '# HELP work_record_reconciliation_last_run_timestamp_seconds Momento de la ultima conciliacion terminada entre el registro horario y su auditoria.';
        $lines[] = '# TYPE work_record_reconciliation_last_run_timestamp_seconds gauge';
        $lines[] = 'work_record_reconciliation_last_run_timestamp_seconds{scope="'.$scope.'"} '.$at->getTimestamp();
        $lines[] = '# HELP work_record_reconciliation_entries_checked Tramos y asientos emparejados en la ultima conciliacion. A cero con registro, la pasada no esta mirando nada.';
        $lines[] = '# TYPE work_record_reconciliation_entries_checked gauge';
        $lines[] = 'work_record_reconciliation_entries_checked{scope="'.$scope.'"} '.$result->entriesChecked;
        // ADR-057 enmienda del 10-10-2026 §6. No suma en `discrepancies`: lo
        // revisado deja de ser pendiente, pero una revision nueva tiene que
        // verse (alerta `PurgaFueraDeLimitesRevisada`). Partida por
        // `explains_deletions`: la que vuelve a explicar borrados cambia lo que
        // se acepta y la alerta la trata distinto. Las dos series siempre, a 0
        // tambien: una que desaparece no se distingue de una que nunca subio.
        $lines[] = '# HELP work_record_reconciliation_reviewed_purges Asientos de purga no admisibles que una persona dio por revisados, segun vuelvan a explicar borrados o no. No son discrepancias; si sube, alguien ha revisado una.';
        $lines[] = '# TYPE work_record_reconciliation_reviewed_purges gauge';
        $lines[] = 'work_record_reconciliation_reviewed_purges{scope="'.$scope.'",explains_deletions="true"} '.$result->reviewedPurgesExplainingDeletions;
        $lines[] = 'work_record_reconciliation_reviewed_purges{scope="'.$scope.'",explains_deletions="false"} '.$result->reviewedPurgesNotExplainingDeletions();

        TextfileExposition::write(self::FILE_PREFIX.$scope.'.prom', $lines);
    }
}
