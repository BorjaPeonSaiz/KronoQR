<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use App\Modules\Compliance\Application\Command\ReviewOutOfBoundsPurgeCommand;
use App\Modules\Compliance\Application\Command\ReviewSiteTimezoneDiscrepancyCommand;
use App\Modules\Compliance\Application\UseCase\ReconcileWorkRecordWithAudit;
use App\Modules\Compliance\Application\UseCase\ReviewOutOfBoundsPurge;
use App\Modules\Compliance\Application\UseCase\ReviewSiteTimezoneDiscrepancy;
use App\Modules\Compliance\Domain\Exception\PurgeReviewRejected;
use App\Modules\Compliance\Domain\Exception\SiteTimezoneReviewRejected;
use App\Modules\Compliance\Domain\ValueObject\SiteTimezoneReviewTarget;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancy;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationResult;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationScope;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Domain\ValueObject\ResponsibleAccount;
use App\Modules\Shared\Infrastructure\Console\ConsoleIds;
use App\Modules\Shared\Infrastructure\Console\ConsoleResponsible;
use App\Modules\Shared\Infrastructure\Console\SpeaksInstallationLanguage;
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
    use SpeaksInstallationLanguage;

    /** Seccion de `lang/*\/console.php`. */
    private const string TEXTS = 'console.reconcile_work_record';

    protected $signature = 'compliance:reconcile-work-record
        {--full : Todo el registro y toda la auditoria viva, no solo la ventana de los ultimos dias}
        {--days= : Dias de la ventana diaria. Por defecto, compliance.work_record_reconciliation.window_days}
        {--chunk=500 : Filas por lote. Solo afecta a la memoria, no al resultado}
        {--acknowledge= : Id del asiento retention.purge_executed que se da por revisado. No concilia: escribe el asiento de revision}
        {--reason= : Motivo de la revision, de 10 a 1000 caracteres. Obligatorio con --acknowledge}
        {--acknowledge-site-change= : Discrepancia de la zona del centro que se da por revisada: change:<id> (fila de site_timezone_changes) o audit:<id> (site.updated sin firma). No concilia}
        {--responsible= : Uuid o correo de la cuenta de gestion activa (admin o rrhh) que firma la revision. Obligatorio con --acknowledge y --acknowledge-site-change}
        {--responsible-confirm= : Correo de la cuenta de --responsible. Obligatorio sin terminal}';

    /** Tramos que se detallan en el log tecnico. Ver `handle()`. */
    private const int LOGGED_ROWS = 50;

    protected $description = 'Concilia shift_entries con sus asientos de audit_log y alerta ante cualquier discrepancia (ADR-057)';

    public function handle(ReconcileWorkRecordWithAudit $reconcile, ReviewOutOfBoundsPurge $review, ReviewSiteTimezoneDiscrepancy $siteReview, ResponsibleAccounts $accounts): int
    {
        $siteChange = $this->stringOption('acknowledge-site-change');

        if ($siteChange !== null) {
            return $this->acknowledgeSiteChange($siteReview, $siteChange, $accounts);
        }

        $acknowledge = $this->stringOption('acknowledge');

        if ($acknowledge !== null) {
            return $this->acknowledge($review, $acknowledge, $accounts);
        }

        $scope = (bool) $this->option('full')
            ? WorkRecordReconciliationScope::Full
            : WorkRecordReconciliationScope::Recent;
        $days = $this->windowDays();

        if ($days < 1) {
            $this->error($this->text('window_too_short'));

            return self::INVALID;
        }

        $chunk = (int) $this->option('chunk');
        $result = $reconcile->handle($scope, $days, $chunk > 0 ? $chunk : 500);

        $coverage = $scope === WorkRecordReconciliationScope::Full
            ? $this->text('coverage.full')
            : $this->text('coverage.recent', ['days' => $days]);

        $this->printNotCounted($result);

        if ($result->isConsistent()) {
            $this->info($this->text('consistent', ['coverage' => $coverage, 'count' => $result->entriesChecked]));

            return self::SUCCESS;
        }

        $this->logMismatch($result);
        $this->printMismatch($result, $coverage);

        return self::FAILURE;
    }

    /**
     * Lo que la pasada ha visto y no cuenta como discrepancia: lo revisado y
     * lo no verificable. Informa, no falla.
     */
    private function printNotCounted(WorkRecordReconciliationResult $result): void
    {
        if ($result->reviewedPurges > 0) {
            $this->line($this->text('not_counted.reviewed_purges', ['count' => $result->reviewedPurges]));
        }

        if ($result->reviewedSiteTimezoneChanges + $result->reviewedSiteTimezoneEntries > 0) {
            $this->line($this->text('not_counted.reviewed_site_timezone', [
                'changes' => $result->reviewedSiteTimezoneChanges,
                'entries' => $result->reviewedSiteTimezoneEntries,
            ]));
        }

        // RN-23: lo que no se ha podido comparar con ningun asiento. Informa,
        // no falla: no es una discrepancia.
        if ($result->unverifiableSiteTimezones > 0 || $result->unverifiableSiteTimezoneChanges > 0) {
            $this->line($this->text('not_counted.unverifiable_site_timezone', [
                'sites' => $result->unverifiableSiteTimezones,
                'changes' => $result->unverifiableSiteTimezoneChanges,
            ]));
        }
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
                    // Solo en `site_timezone_differs_from_audit` (RN-23).
                    'site_id' => $discrepancy->siteId,
                    'site_timezone_change_id' => $discrepancy->siteTimezoneChangeId,
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
        $this->error($this->text('mismatch', [
            'coverage' => $coverage,
            'count' => $result->discrepancyCount(),
            'entries' => $result->entriesChecked,
        ]));

        // `  <clase>: <n>`, sin traducir: `update.sh` lee el recuento por clase
        // con ese patron, en cualquier idioma de la instalacion.
        foreach ($result->counts as $kind => $count) {
            if ($count > 0) {
                $this->line('  '.$kind.': '.$count);
            }
        }

        foreach ($result->discrepancies as $discrepancy) {
            $this->line('  '.$discrepancy->describe());
        }

        if ($result->truncated) {
            $this->line($this->text('truncated', ['count' => \count($result->discrepancies)]));
        }

        $this->error($this->text('incident'));
    }

    /**
     * `--acknowledge=<id>`: da por revisada una purga no admisible (ADR-057
     * enmienda del 10-10-2026 §6). No concilia.
     *
     * - Con `--full` o `--days`: 2, sin hacer nada.
     * - `--acknowledge` que no es un entero positivo (el id del asiento de
     *   `audit_log`): 2, sin hacer nada.
     * - `--responsible` que no es el `uuid` ni el correo de una cuenta de
     *   gestion activa con rol admin o rrhh (un id numerico se rechaza desde la
     *   2.3.0): 2, sin escribir (tambien en la vista previa).
     * - Sin `--reason` o sin `--responsible`: vista previa y 2, sin escribir.
     * - Enseña quien firma y pide confirmarlo; sin terminal, falta o no cuadra
     *   `--responsible-confirm`: 2; la persona responde que no: 1. Sin escribir.
     * - Revision rechazada: 1 y el motivo.
     * - Escrita: 0.
     *
     * El motivo nunca va al log tecnico (regla dura 21): es texto libre.
     */
    private function acknowledge(ReviewOutOfBoundsPurge $review, string $acknowledge, ResponsibleAccounts $accounts): int
    {
        if ((bool) $this->option('full') || $this->stringOption('days') !== null) {
            $this->error($this->installationText('work-record-review.incompatible_options'));

            return self::INVALID;
        }

        // `(int)` se tragaria `12abc` como 12: un id de asiento o de cuenta es
        // un entero positivo escrito tal cual, o no se hace nada.
        $purgeId = ConsoleIds::positive($acknowledge);

        if ($purgeId === null) {
            $this->error($this->installationText('work-record-review.invalid_purge_id', ['value' => $acknowledge]));

            return self::INVALID;
        }

        $reason = $this->stringOption('reason');
        $responsible = $this->responsible($accounts);

        if (\is_int($responsible)) {
            return $responsible;
        }

        try {
            if ($reason === null || ! $responsible instanceof ResponsibleAccount) {
                $preview = $review->preview($purgeId, $responsible?->id);
                $this->printReview($purgeId, $preview->violations, $preview->explainsDeletions, $preview->cutoffDate, $preview->siteId);
                $this->error($this->installationText('work-record-review.missing_options', ['id' => $purgeId]));

                return self::INVALID;
            }

            $declined = $this->confirmSignature($responsible, $this->installationText('work-record-review.confirm_purge', ['id' => $purgeId]));

            if ($declined !== null) {
                return $declined;
            }

            $outcome = $review->handle(new ReviewOutOfBoundsPurgeCommand($purgeId, $reason, $responsible->id));
        } catch (PurgeReviewRejected $rejected) {
            return $this->rejected($rejected);
        }

        Log::warning('work_record_purge_reviewed', [
            'audit_entry_id' => $outcome->purgeAuditEntryId,
            'review_entry_id' => $outcome->reviewAuditEntryId,
            'explains_deletions' => $outcome->explainsDeletions,
        ]);

        $this->info($this->installationText('work-record-review.done', ['id' => $outcome->purgeAuditEntryId, 'review' => $outcome->reviewAuditEntryId]));
        $this->printReview($purgeId, $outcome->violations, $outcome->explainsDeletions, $outcome->cutoffDate, $outcome->siteId);

        return self::SUCCESS;
    }

    /**
     * `--acknowledge-site-change=change:<id>|audit:<id>`: da por revisada una
     * discrepancia de la zona del centro (RN-23). No
     * concilia. Mismos codigos que `--acknowledge`:
     *
     * - con `--full`, `--days` o `--acknowledge`, o un objetivo o un
     *   `--responsible` mal escritos: 2, sin hacer nada;
     * - `--responsible` que no es una cuenta activa con rol admin o rrhh: 2,
     *   sin escribir (tambien en la vista previa);
     * - sin `--reason` o sin `--responsible`: vista previa y 2, sin escribir;
     * - no es una discrepancia pendiente o ya esta revisada: 1;
     * - escrita: 0.
     *
     * El motivo nunca va al log tecnico (regla dura 21).
     */
    /**
     * Lo que se pide revisar, o `INVALID` si se combina con otra cosa o no es
     * `change:<id>` ni `audit:<id>`.
     */
    private function siteChangeTarget(string $value): SiteTimezoneReviewTarget|int
    {
        if ((bool) $this->option('full') || $this->stringOption('days') !== null || $this->stringOption('acknowledge') !== null) {
            $this->error($this->installationText('work-record-review.site_change_incompatible'));

            return self::INVALID;
        }

        $target = SiteTimezoneReviewTarget::parse($value);

        if ($target === null) {
            $this->error($this->installationText('work-record-review.invalid_site_change', ['value' => $value]));

            return self::INVALID;
        }

        return $target;
    }

    private function acknowledgeSiteChange(ReviewSiteTimezoneDiscrepancy $review, string $value, ResponsibleAccounts $accounts): int
    {
        $target = $this->siteChangeTarget($value);

        if (\is_int($target)) {
            return $target;
        }

        $reason = $this->stringOption('reason');
        $responsible = $this->responsible($accounts);

        if (\is_int($responsible)) {
            return $responsible;
        }

        try {
            if ($reason === null || ! $responsible instanceof ResponsibleAccount) {
                $site = $review->preview($target, $responsible?->id);
                $this->line('  '.$this->installationText('work-record-review.site_change_preview', ['target' => $target->label(), 'site' => $site]));
                $this->error($this->installationText('work-record-review.site_change_missing_options', ['target' => $target->label()]));

                return self::INVALID;
            }

            $declined = $this->confirmSignature($responsible, $this->installationText('work-record-review.confirm_site_change', ['target' => $target->label()]));

            if ($declined !== null) {
                return $declined;
            }

            $reviewId = $review->handle(new ReviewSiteTimezoneDiscrepancyCommand($target, $reason, $responsible->id));
        } catch (SiteTimezoneReviewRejected $rejected) {
            $this->error($this->installationText($rejected->translationKey, $rejected->parameters));

            return $rejected->translationKey === SiteTimezoneReviewRejected::RESPONSIBLE_NOT_ACTIVE ? self::INVALID : self::FAILURE;
        }

        Log::warning('work_record_site_timezone_discrepancy_reviewed', [
            'target' => $target->label(),
            'review_entry_id' => $reviewId,
        ]);

        $this->info($this->installationText('work-record-review.site_change_done', ['target' => $target->label(), 'review' => $reviewId]));

        return self::SUCCESS;
    }

    /**
     * Una cuenta que no puede firmar es un error de quien teclea la orden, como
     * un id mal escrito: 2. El resto son negativas sobre el asiento: 1.
     */
    private function rejected(PurgeReviewRejected $rejected): int
    {
        $this->error($this->installationText($rejected->translationKey, $rejected->parameters));

        return $rejected->translationKey === PurgeReviewRejected::RESPONSIBLE_NOT_ACTIVE
            ? self::INVALID
            : self::FAILURE;
    }

    /**
     * @param  list<string>  $violations
     */
    private function printReview(int $purgeId, array $violations, bool $explains, ?string $cutoff, ?int $siteId): void
    {
        $this->line('  retention.purge_executed #'.$purgeId.': '.implode(', ', $violations));
        $this->line('  '.($explains
            ? $this->installationText('work-record-review.explains', ['cutoff' => (string) $cutoff, 'site' => (string) $siteId])
            : $this->installationText('work-record-review.explains_nothing', ['violations' => implode(', ', $violations)])));
    }

    /**
     * La cuenta de `--responsible`, `null` si no se ha dado (la vista previa
     * no la necesita), o `INVALID` si no es el `uuid` ni el correo de una
     * cuenta activa con rol admin o rrhh: no se hace nada, tampoco la vista
     * previa.
     */
    private function responsible(ResponsibleAccounts $accounts): ResponsibleAccount|int|null
    {
        $option = $this->stringOption('responsible');

        return $option === null
            ? null
            : ConsoleResponsible::resolve($this, $accounts, $option, ReviewOutOfBoundsPurge::RESPONSIBLE_ROLES);
    }

    /**
     * Enseña quien firma la revision y la confirma: con terminal se pregunta;
     * sin ella, `--responsible-confirm` con el correo de la cuenta.
     */
    private function confirmSignature(ResponsibleAccount $responsible, string $question): ?int
    {
        return ConsoleResponsible::confirm($this, $responsible, $question, ! $this->input->isInteractive());
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
