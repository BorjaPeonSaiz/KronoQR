<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Port\WorkRecordAuditSource;
use App\Modules\Compliance\Application\Port\WorkRecordReconciliationMetrics;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancy;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationResult;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationScope;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordReconciliationWindow;
use App\Modules\Compliance\Domain\WorkRecordReconciliation;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\SpanScope;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * Concilia el registro horario con su auditoria (ADR-057 §4, RL-04, RS-07).
 *
 * Cruza cada tramo de `shift_entries` con el ultimo asiento `shift_entry.*` de
 * `audit_log` y cuenta lo que no cuadra: tramos sin asiento, marcas que no son
 * las del asiento, tramos retirados sin su correccion y asientos sin tramo. La
 * regla es {@see WorkRecordReconciliation}; aqui solo se recorre, se cuenta y se
 * publica.
 *
 * **Es lo que hace verdad lo que el cliente tiene escrito** (doc 05 §7,
 * `docs/cliente/obligaciones-legales.md` §5): que una edicion de la base a mano
 * se detecta al dia siguiente. Para `audit_log` lo hace la cadena de hash; para
 * el registro horario, esto.
 *
 * **No corrige ni escribe en el registro.** Tampoco en `audit_log`: igual que la
 * verificacion de la cadena, es una comprobacion y no una accion sobre los
 * datos. El hallazgo va al log tecnico —identificadores y nombres de campo,
 * nunca valores ni nombres (regla dura 21)— y a la metrica que dispara la
 * alerta.
 *
 * **Fuera del camino del fichaje.** Solo lee, en una instantanea y por lotes; lo
 * lanza el planificador de madrugada.
 */
final readonly class ReconcileWorkRecordWithAudit
{
    /**
     * Discrepancias que se conservan con detalle. Por encima, solo se cuentan:
     * ver {@see WorkRecordReconciliationResult}.
     */
    public const int MAX_DETAILED_DISCREPANCIES = 500;

    public function __construct(
        private WorkRecordAuditSource $source,
        private WorkRecordReconciliationMetrics $metrics,
        private Clock $clock,
    ) {}

    /**
     * @param  int  $windowDays  Dias de la ventana diaria. No se usa en la pasada completa.
     */
    public function handle(WorkRecordReconciliationScope $scope, int $windowDays, int $chunkSize = 500): WorkRecordReconciliationResult
    {
        $now = $this->clock->now();
        $window = $scope === WorkRecordReconciliationScope::Full
            ? WorkRecordReconciliationWindow::full()
            : WorkRecordReconciliationWindow::recent($now, $windowDays);

        // KIND_INTERNAL: no hay peticion detras, lo lanza el planificador o una
        // persona en la consola del servidor.
        $span = SpanScope::start(
            'kronoqr.compliance',
            'compliance.reconcile_work_record',
            SpanKind::KIND_INTERNAL,
            ['scope' => $scope->value, 'window_days' => $window->days],
        );

        try {
            $result = $this->reconcile($window, max(1, $chunkSize));
        } catch (Throwable $failure) {
            $span->end(['outcome' => 'error']);

            throw $failure;
        }

        $this->metrics->record($result, $this->clock->now());

        $span->end([
            'outcome' => $result->isConsistent() ? 'consistent' : 'discrepancies',
            'entries_checked' => $result->entriesChecked,
            'discrepancies' => $result->discrepancyCount(),
        ]);

        return $result;
    }

    private function reconcile(WorkRecordReconciliationWindow $window, int $chunkSize): WorkRecordReconciliationResult
    {
        $counts = WorkRecordReconciliationResult::emptyCounts();
        /** @var list<WorkRecordDiscrepancy> $detailed */
        $detailed = [];
        $checked = 0;
        $purgedThrough = null;

        foreach ($this->source->pairs($window, $chunkSize) as $pair) {
            $checked++;
            // El mismo en todos los pares: se leyo una vez, en la instantanea.
            $purgedThrough = $pair->purgedThrough;
            $discrepancy = WorkRecordReconciliation::compare($pair);

            if (! $discrepancy instanceof WorkRecordDiscrepancy) {
                continue;
            }

            $counts[$discrepancy->kind->value]++;

            if (\count($detailed) < self::MAX_DETAILED_DISCREPANCIES) {
                $detailed[] = $discrepancy;
            }
        }

        return new WorkRecordReconciliationResult(
            window: $window,
            entriesChecked: $checked,
            counts: $counts,
            discrepancies: $detailed,
            truncated: array_sum($counts) > \count($detailed),
            purgedThrough: $purgedThrough,
        );
    }
}
