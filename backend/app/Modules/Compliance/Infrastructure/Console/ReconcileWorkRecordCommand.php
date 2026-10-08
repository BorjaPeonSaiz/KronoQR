<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use App\Modules\Compliance\Application\UseCase\ReconcileWorkRecordWithAudit;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancy;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationResult;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan compliance:reconcile-work-record` — cruza el registro horario con
 * su auditoria y alerta si no cuadran (ADR-057 §4, RL-04, RS-07).
 *
 * **Dos cadencias** (`routes/console.php`):
 *
 * - **A diario, sin opciones**: los tramos de los ultimos
 *   `compliance.work_record_reconciliation.window_days` dias (7 de serie) y todo
 *   lo que la auditoria apunto en ese plazo. Una edicion directa de un fichaje
 *   reciente sale al dia siguiente.
 * - **Semanal, con `--full`**: todo el registro y toda la auditoria viva. Es lo
 *   que ve un borrado o una edicion de un tramo antiguo.
 *
 * **Codigo de salida.** `0` si todo cuadra, `1` si hay cualquier discrepancia.
 * Sin umbral: una sola es una escritura en el registro por fuera de la
 * aplicacion.
 *
 * **Que se publica y donde.** El detalle —que tramo, que tipo, que campos, que
 * asiento— va al log tecnico y a la salida del comando; la alerta la dispara la
 * metrica. Ninguno lleva valores ni nombres: el `uuid` del tramo y el `id` del
 * asiento bastan para encontrar el resto en la base (regla dura 21). Lo que hay
 * que hacer al verla esta en `docs/runbooks/discrepancia-registro-auditoria.md`.
 */
final class ReconcileWorkRecordCommand extends Command
{
    protected $signature = 'compliance:reconcile-work-record
        {--full : Todo el registro y toda la auditoria viva, no solo la ventana de los ultimos dias}
        {--days= : Dias de la ventana diaria. Por defecto, compliance.work_record_reconciliation.window_days}
        {--chunk=500 : Filas por lote. Solo afecta a la memoria, no al resultado}';

    /** Tramos que se detallan en el log tecnico. Ver `handle()`. */
    private const int LOGGED_ROWS = 50;

    protected $description = 'Concilia shift_entries con sus asientos de audit_log y alerta ante cualquier discrepancia (ADR-057)';

    public function handle(ReconcileWorkRecordWithAudit $reconcile): int
    {
        $scope = (bool) $this->option('full')
            ? WorkRecordReconciliationScope::Full
            : WorkRecordReconciliationScope::Recent;
        $days = $this->windowDays();

        if ($days < 1) {
            $this->error('La ventana tiene que cubrir al menos un dia (--days o compliance.work_record_reconciliation.window_days).');

            return self::INVALID;
        }

        $chunk = (int) $this->option('chunk');
        $result = $reconcile->handle($scope, $days, $chunk > 0 ? $chunk : 500);

        $coverage = $scope === WorkRecordReconciliationScope::Full
            ? 'registro completo'
            : 'ultimos '.$days.' dias';

        if ($result->isConsistent()) {
            $this->info('Registro y auditoria cuadran ('.$coverage.'): '.$result->entriesChecked.' tramos conciliados.');

            return self::SUCCESS;
        }

        $this->logMismatch($result);
        $this->printMismatch($result, $coverage);

        return self::FAILURE;
    }

    /**
     * El hallazgo al log tecnico: identificadores, tipos y nombres de campo,
     * nunca valores ni nombres (regla dura 21).
     */
    private function logMismatch(WorkRecordReconciliationResult $result): void
    {
        Log::critical('work_record_reconciliation_mismatch', [
            'scope' => $result->scope()->value,
            'entries_checked' => $result->entriesChecked,
            'counts' => $result->counts,
            // Los recuentos son completos; el detalle, los primeros LOGGED_ROWS.
            // La lista entera esta en la salida del comando: una linea de log
            // con quinientos tramos no ayuda a nadie y engorda error_events.
            'truncated' => $result->truncated || \count($result->discrepancies) > self::LOGGED_ROWS,
            'rows' => array_map(
                static fn (WorkRecordDiscrepancy $discrepancy): array => [
                    'shift_entry_uuid' => $discrepancy->shiftEntryUuid,
                    'kind' => $discrepancy->kind->value,
                    'columns' => $discrepancy->fields,
                    'audit_entry_id' => $discrepancy->auditEntryId,
                ],
                \array_slice($result->discrepancies, 0, self::LOGGED_ROWS),
            ),
        ]);
    }

    /**
     * El hallazgo a la salida del comando, que es lo que lee quien lo lanza a
     * mano con el runbook delante.
     */
    private function printMismatch(WorkRecordReconciliationResult $result, string $coverage): void
    {
        $this->error(
            'EL REGISTRO HORARIO NO CUADRA CON SU AUDITORIA ('.$coverage.'): '
            .$result->discrepancyCount().' discrepancia(s) sobre '.$result->entriesChecked.' tramos.'
        );

        foreach ($result->counts as $kind => $count) {
            if ($count > 0) {
                $this->line('  '.$kind.': '.$count);
            }
        }

        foreach ($result->discrepancies as $discrepancy) {
            $this->line('  '.$discrepancy->describe());
        }

        if ($result->truncated) {
            $this->line('  (se listan las primeras '.\count($result->discrepancies).'; los recuentos de arriba son completos)');
        }

        $this->error('Posible incidente de seguridad. Procedimiento: docs/runbooks/discrepancia-registro-auditoria.md');
    }

    private function windowDays(): int
    {
        $option = $this->option('days');

        if (\is_string($option) && $option !== '') {
            return (int) $option;
        }

        return Config::integer('compliance.work_record_reconciliation.window_days', 7);
    }
}
