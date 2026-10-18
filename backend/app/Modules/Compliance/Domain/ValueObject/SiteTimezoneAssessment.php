<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Lo que la conciliacion concluye de la zona horaria de un centro (RN-23,
 * ADR-056; bloque 2 de la 2.3.0): las discrepancias y lo que **no se ha podido
 * verificar**.
 *
 * Lo no verificable no es una discrepancia ni hace sonar la alerta de
 * integridad: es que falta con que comparar —un centro sin ningun asiento
 * `site.*`, o un cambio de la traza mas antiguo que la auditoria que se
 * conserva (ADR-027)—. Se cuenta aparte para que se vea que la comprobacion no
 * ha mirado algo, en lugar de quedar en cero en silencio.
 */
final readonly class SiteTimezoneAssessment
{
    /**
     * @param  list<WorkRecordDiscrepancy>  $discrepancies
     */
    public function __construct(
        public array $discrepancies,
        /** La zona actual no tiene asiento con el que compararla. */
        public bool $unverifiableTimezone,
        /** Filas de la traza anteriores a la auditoria que se conserva, y cambios sin firma anteriores a la linea base. */
        public int $unverifiableChanges,
        /** Discrepancias que una persona dio por revisadas: no son pendientes. */
        public int $reviewed = 0,
    ) {}
}
