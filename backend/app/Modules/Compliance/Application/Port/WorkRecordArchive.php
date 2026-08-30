<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\RetentionTally;
use DateTimeImmutable;

/**
 * El registro de jornada vencido: lo que hay y su eliminacion por lotes (RL-02).
 *
 * **Que entra aqui.** Tramos, sus correcciones, los escaneos que los produjeron,
 * las incidencias abiertas sobre ellos y la proyeccion `daily_totals`. Todo lo
 * que cuelga de una jornada, porque conservar los hijos de un tramo purgado no
 * conserva nada util y si conserva datos personales sin finalidad.
 *
 * **Que NO entra.** `audit_log`, que no se borra nunca por `DELETE` (ADR-027 y
 * {@see AuditPartitionArchive}), y la plantilla: un empleado con contrato en
 * vigor no desaparece porque su jornada de hace cuatro anos si.
 *
 * **El corte es una fecha de jornada, no un instante.** `work_date` es una fecha
 * civil (RN-05) y es lo que el art. 34.9 ET obliga a conservar cuatro anos.
 *
 * **No abre transaccion**: se une a la del caso de uso, que es la que escribe el
 * asiento de la purga. Si el asiento falla, no se ha borrado nada.
 */
interface WorkRecordArchive
{
    /**
     * Que se purgaria, sin tocar nada. Una fila por tabla.
     *
     * @return list<RetentionTally>
     */
    public function inspect(DateTimeImmutable $cutoff): array;

    /**
     * Lo purga y devuelve lo que se llevo, con la misma forma que `inspect()`.
     *
     * `$batchSize` acota el tamano de cada sentencia, no la transaccion: la
     * abre y la cierra el caso de uso.
     *
     * @return list<RetentionTally>
     */
    public function purge(DateTimeImmutable $cutoff, int $batchSize): array;
}
