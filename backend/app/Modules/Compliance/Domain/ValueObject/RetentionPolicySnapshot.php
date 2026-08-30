<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Los plazos **con los que se calculo** este informe (RL-11, bloque E de
 * `/revision-cumplimiento`).
 *
 * Viaja dentro del informe y del asiento de `audit_log` porque el umbral es
 * configuracion y puede cambiar (RF-PD-07): sin el, un informe de purga de hace
 * dos anos no se puede releer —«¿cuatro anos o seis?»— y la purga deja de ser
 * defendible. Es el mismo criterio con el que una incidencia guarda el umbral
 * con el que se abrio.
 */
final readonly class RetentionPolicySnapshot
{
    public function __construct(
        public int $legalRecordYears,
        public int $technicalLogDays,
        public int $errorHistoryDays,
        /**
         * Centro cuyo perfil de cumplimiento sirvio los anos (ADR-040: hay uno).
         * Se guarda el identificador y no el nombre del hotel: el informe y el
         * asiento se archivan, y el nombre no anade nada que el `id` no diga.
         */
        public int $siteId,
    ) {}
}
