<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use App\Modules\Compliance\Application\Command\ReviewOutOfBoundsPurgeCommand;
use App\Modules\Compliance\Application\UseCase\ReconcileWorkRecordWithAudit;
use App\Modules\Compliance\Application\UseCase\ReviewOutOfBoundsPurge;
use App\Modules\Compliance\Domain\Exception\PurgeReviewRejected;
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
        {--chunk=500 : Filas por lote. Solo afecta a la memoria, no al resultado}
        {--acknowledge= : Id del asiento retention.purge_executed que se da por revisado. No concilia: escribe el asiento de revision}
        {--reason= : Motivo de la revision, de 10 a 1000 caracteres. Obligatorio con --acknowledge}
        {--responsible= : Id de la cuenta de gestion activa que responde de la revision. Obligatorio con --acknowledge}';

    /** Tramos que se detallan en el log tecnico. Ver `handle()`. */
    private const int LOGGED_ROWS = 50;

    protected $description = 'Concilia shift_entries con sus asientos de audit_log y alerta ante cualquier discrepancia (ADR-057)';

    public function handle(ReconcileWorkRecordWithAudit $reconcile, ReviewOutOfBoundsPurge $review): int
    {
        $acknowledge = $this->stringOption('acknowledge');

        if ($acknowledge !== null) {
            return $this->acknowledge($review, $acknowledge);
        }

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

        if ($result->reviewedPurges > 0) {
            $this->line('purgas fuera de limites revisadas: '.$result->reviewedPurges.' (no cuentan)');
        }

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

    /**
     * `--acknowledge=<id>`: da por revisada una purga no admisible (ADR-057
     * enmienda del 10-10-2026 §6). No concilia.
     *
     * - Con `--full` o `--days`: 2, sin hacer nada.
     * - `--acknowledge` o `--responsible` que no son un entero positivo: 2, sin
     *   hacer nada.
     * - Sin `--reason` o sin `--responsible`: vista previa y 2, sin escribir.
     * - Revision rechazada: 1 y el motivo.
     * - Escrita: 0.
     *
     * El motivo nunca va al log tecnico (regla dura 21): es texto libre.
     */
    private function acknowledge(ReviewOutOfBoundsPurge $review, string $acknowledge): int
    {
        if ((bool) $this->option('full') || $this->stringOption('days') !== null) {
            $this->error(__('work-record-review.incompatible_options'));

            return self::INVALID;
        }

        // `(int)` se tragaria `12abc` como 12: un id de asiento o de cuenta es
        // un entero positivo escrito tal cual, o no se hace nada.
        $purgeId = self::positiveId($acknowledge);

        if ($purgeId === null) {
            $this->error(__('work-record-review.invalid_purge_id', ['value' => $acknowledge]));

            return self::INVALID;
        }

        $reason = $this->stringOption('reason');
        $responsibleOption = $this->stringOption('responsible');
        $responsible = $responsibleOption === null ? null : self::positiveId($responsibleOption);

        if ($responsibleOption !== null && $responsible === null) {
            $this->error(__('work-record-review.invalid_responsible', ['value' => $responsibleOption]));

            return self::INVALID;
        }

        try {
            if ($reason === null || $responsible === null) {
                $preview = $review->preview($purgeId);
                $this->printReview($purgeId, $preview->violations, $preview->explainsDeletions, $preview->cutoffDate, $preview->siteId);
                $this->error(__('work-record-review.missing_options', ['id' => $purgeId]));

                return self::INVALID;
            }

            $outcome = $review->handle(new ReviewOutOfBoundsPurgeCommand($purgeId, $reason, $responsible));
        } catch (PurgeReviewRejected $rejected) {
            $this->error(__($rejected->translationKey, $rejected->parameters));

            return self::FAILURE;
        }

        Log::warning('work_record_purge_reviewed', [
            'audit_entry_id' => $outcome->purgeAuditEntryId,
            'review_entry_id' => $outcome->reviewAuditEntryId,
            'explains_deletions' => $outcome->explainsDeletions,
        ]);

        $this->info(__('work-record-review.done', ['id' => $outcome->purgeAuditEntryId, 'review' => $outcome->reviewAuditEntryId]));
        $this->printReview($purgeId, $outcome->violations, $outcome->explainsDeletions, $outcome->cutoffDate, $outcome->siteId);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $violations
     */
    private function printReview(int $purgeId, array $violations, bool $explains, ?string $cutoff, ?int $siteId): void
    {
        $this->line('  retention.purge_executed #'.$purgeId.': '.implode(', ', $violations));
        $this->line('  '.($explains
            ? __('work-record-review.explains', ['cutoff' => (string) $cutoff, 'site' => (string) $siteId])
            : __('work-record-review.explains_nothing', ['violations' => implode(', ', $violations)])));
    }

    /** El entero positivo que dice el texto, sin espacios alrededor, o `null` si no es solo eso. */
    private static function positiveId(string $value): ?int
    {
        $value = trim($value);

        if (! ctype_digit($value) || ltrim($value, '0') === '' || \strlen(ltrim($value, '0')) > 18) {
            return null;
        }

        return (int) $value;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return \is_string($value) && trim($value) !== '' ? $value : null;
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
