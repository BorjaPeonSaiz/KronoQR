<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\AuditedSiteTimezone;

/**
 * La zona horaria de cada centro con su auditoria (RN-23, ADR-056; bloque 2 de
 * la 2.3.0): lo que necesitan la conciliacion y la revision de una de sus
 * discrepancias (`compliance:reconcile-work-record --acknowledge-site-change`).
 *
 * **Solo lee.** Quien llama decide en que transaccion: la conciliacion lo lee
 * en su instantanea `REPEATABLE READ`, y la revision con la cadena tomada.
 */
interface SiteTimezoneAuditReader
{
    /**
     * Cada centro con su ultimo asiento `site.*`, sus `site.updated`, sus filas
     * de la traza, sus revisiones y la linea base.
     *
     * @return list<AuditedSiteTimezone>
     */
    public function sites(): array;

    /**
     * Los años de `audit_log` purgados con su ancla (ADR-027).
     *
     * @return list<int>
     */
    public function sealedAuditYears(): array;
}
