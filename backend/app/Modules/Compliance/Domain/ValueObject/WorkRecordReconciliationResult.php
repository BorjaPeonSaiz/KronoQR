<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

use InvalidArgumentException;

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
        /**
         * De las revisadas, las que **vuelven a explicar borrados** (su unico
         * defecto era el plazo). Una revision que explica borrados cambia lo
         * que la conciliacion acepta; una que no, solo silencia la alerta de esa
         * purga. Se publican aparte para que la alerta las distinga.
         */
        public int $reviewedPurgesExplainingDeletions = 0,
        /**
         * Centros cuya zona no se ha podido comparar con ningun asiento
         * `site.*` (RN-23, bloque 2 de la 2.3.0). **No es una discrepancia**:
         * dice que la comprobacion no ha mirado algo, en vez de dar cero.
         */
        public int $unverifiableSiteTimezones = 0,
        /**
         * Filas de `site_timezone_changes` de años cuya auditoria ya se purgo
         * (ADR-027): no hay asiento con que cotejarlas. Tampoco es discrepancia.
         */
        public int $unverifiableSiteTimezoneChanges = 0,
        /**
         * Filas de la traza `site_timezone_changes` sin firma que una persona dio
         * por revisadas (`site.timezone_discrepancy_reviewed`, `change:<id>`).
         * Como las purgas revisadas, no son discrepancias: se publican aparte y
         * su alerta es de integridad (una revision falsificada tapa un cambio
         * de zona hecho por fuera de la orden).
         */
        public int $reviewedSiteTimezoneChanges = 0,
        /** Asientos `site.updated` sin firma dados por revisados (`audit:<id>`): aviso. */
        public int $reviewedSiteTimezoneEntries = 0,
    ) {
        // Un resultado incoherente responderia `isConsistent()` con una
        // discrepancia en la lista, y ese resultado es el que alimenta la
        // metrica y la alerta de ADR-057 §4 (V1-AR-4).
        if (array_keys($counts) !== array_keys(self::emptyCounts())) {
            throw new InvalidArgumentException('Los recuentos de la conciliacion llevan todos los tipos de discrepancia, en su orden.');
        }

        foreach ([$entriesChecked, ...array_values($counts), $reviewedPurges, $reviewedPurgesExplainingDeletions, $unverifiableSiteTimezones, $unverifiableSiteTimezoneChanges, $reviewedSiteTimezoneChanges, $reviewedSiteTimezoneEntries] as $count) {
            if ($count < 0) {
                throw new InvalidArgumentException('Un recuento de la conciliacion no puede ser negativo, y llego '.$count.'.');
            }
        }

        if ($reviewedPurgesExplainingDeletions > $reviewedPurges) {
            throw new InvalidArgumentException('Las purgas revisadas que explican borrados son parte de las revisadas.');
        }

        $listed = array_fill_keys(array_keys($counts), 0);

        foreach ($discrepancies as $discrepancy) {
            $listed[$discrepancy->kind->value]++;
        }

        foreach ($listed as $kind => $howMany) {
            if ($howMany > $counts[$kind]) {
                throw new InvalidArgumentException('La conciliacion lista '.$howMany.' discrepancias '.$kind.' y solo cuenta '.$counts[$kind].'.');
            }
        }

        if ($truncated !== (array_sum($counts) > \count($discrepancies))) {
            throw new InvalidArgumentException('El detalle de la conciliacion esta truncado si, y solo si, cuenta mas de las que lista.');
        }
    }

    /** Revisadas que no explican ningun borrado. */
    public function reviewedPurgesNotExplainingDeletions(): int
    {
        return $this->reviewedPurges - $this->reviewedPurgesExplainingDeletions;
    }

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
