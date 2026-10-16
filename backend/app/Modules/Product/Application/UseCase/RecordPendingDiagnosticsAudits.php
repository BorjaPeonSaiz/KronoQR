<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\PendingDiagnosticsAudits;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Domain\Event\DiagnosticsBundleGenerated;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use Throwable;

/**
 * Escribe en `audit_log` los asientos de los paquetes de diagnostico que se
 * entregaron con la base de datos caida (ADR-020, enmienda del 14-10-2026;
 * RL-04, regla dura 6).
 *
 * Lo ejecutan la tarea programada `product:diagnostics:record-pending` y cada
 * `product:diagnostics` al arrancar. **Sin pendientes no toca la base**: con la
 * base caida y nada que escribir, ni un intento de conexion.
 *
 * ## Por un evento, como el asiento normal
 *
 * Vuelve a publicar {@see DiagnosticsBundleGenerated} con `deferred = true` y el
 * instante original en `generatedAt`; el listener de `Compliance` escribe el
 * asiento. Sin arista `Product -> Compliance` (doc 02 §1.6).
 *
 * `occurredAt` es **ahora**, el instante en que se escribe, y nunca el de la
 * generacion: la cadena (ADR-010) y el sellado anual (ADR-027) suponen
 * `occurred_at` creciente.
 *
 * ## El pendiente se borra despues del asiento, no antes
 *
 * El listener escribe en su propia transaccion, y la publicacion es sincrona:
 * cuando `publish()` vuelve sin lanzar, el asiento esta confirmado. Si el
 * proceso muere entre el asiento y el borrado, la siguiente pasada vuelve a
 * publicarlo y el listener no lo duplica: es idempotente por huella.
 *
 * ## Al primer fallo, para
 *
 * Si un asiento no se puede escribir, los siguientes tampoco: casi siempre es
 * la base, que sigue caida. Se devuelve cuantos quedan y la **clase** de la
 * excepcion, nunca su mensaje (regla dura 21).
 */
final readonly class RecordPendingDiagnosticsAudits
{
    public function __construct(
        private PendingDiagnosticsAudits $pending,
        private ProductEventPublisher $events,
        private Clock $clock,
    ) {}

    /**
     * @return array{recorded: int, remaining: int, failure: class-string<Throwable>|null}
     */
    public function handle(): array
    {
        return UseCaseInstrumentation::run('product.record_pending_diagnostics_audits', fn () => $this->doHandle());
    }

    /**
     * @return array{recorded: int, remaining: int, failure: class-string<Throwable>|null}
     */
    private function doHandle(): array
    {
        $pending = $this->pending->all();
        $recorded = 0;

        foreach ($pending as $entry) {
            try {
                $this->events->publish(new DiagnosticsBundleGenerated(
                    anonymized: $entry->anonymized,
                    sections: $entry->sections,
                    sha256: $entry->sha256,
                    sizeBytes: $entry->sizeBytes,
                    generatedBy: $entry->generatedBy,
                    occurredAt: $this->clock->now(),
                    deferred: true,
                    generatedAt: $entry->generatedAt,
                ));

                $this->pending->remove($entry->sha256);
            } catch (Throwable $failure) {
                return ['recorded' => $recorded, 'remaining' => \count($pending) - $recorded, 'failure' => $failure::class];
            }

            $recorded++;
        }

        return ['recorded' => $recorded, 'remaining' => 0, 'failure' => null];
    }
}
