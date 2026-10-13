<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * El resultado de una pasada de la conciliacion (ADR-057 §4).
 *
 * **Los recuentos son completos; el detalle, no siempre.** Una manipulacion
 * masiva puede dejar cientos de miles de tramos descuadrados, y guardar cada uno
 * en memoria para escribirlo en un log no ayuda a nadie: el recuento por tipo
 * dice el tamaño del incidente y los primeros casos bastan para empezar a
 * investigar. `truncated` dice si hay mas de los que se listan.
 */
final readonly class WorkRecordReconciliationResult
{
    /**
     * @param  array<string, int>  $counts  Discrepancias por {@see WorkRecordDiscrepancyKind}, con todas las claves.
     * @param  list<WorkRecordDiscrepancy>  $discrepancies  Las primeras, en el orden en que aparecieron.
     */
    public function __construct(
        public WorkRecordReconciliationWindow $window,
        public int $entriesChecked,
        public array $counts,
        public array $discrepancies,
        public bool $truncated = false,
        /**
         * Asientos de purga no admisibles que una persona dio por revisados
         * (`retention.purge_reviewed`, ADR-057 enmienda del 10-10-2026 §6).
         * **No son discrepancias**: `counts` sigue siendo solo lo pendiente y
         * una pasada con solo purgas revisadas es coherente.
         */
        public int $reviewedPurges = 0,
    ) {}

    /**
     * Recuentos a cero para cada tipo: una serie que desaparece no se distingue
     * de una que nunca fallo, asi que se publican todas siempre.
     *
     * @return array<string, int>
     */
    public static function emptyCounts(): array
    {
        $counts = [];

        foreach (WorkRecordDiscrepancyKind::cases() as $kind) {
            $counts[$kind->value] = 0;
        }

        return $counts;
    }

    public function scope(): WorkRecordReconciliationScope
    {
        return $this->window->scope;
    }

    public function discrepancyCount(): int
    {
        return array_sum($this->counts);
    }

    public function isConsistent(): bool
    {
        return $this->discrepancyCount() === 0;
    }
}
