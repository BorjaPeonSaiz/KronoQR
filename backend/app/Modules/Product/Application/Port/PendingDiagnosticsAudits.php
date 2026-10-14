<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\Exception\DiagnosticsAuditNotDeferred;
use App\Modules\Product\Domain\ValueObject\PendingDiagnosticsAudit;

/**
 * Los asientos de paquetes de diagnostico que esperan a que vuelva la base de
 * datos (ADR-020, enmienda del 14-10-2026).
 *
 * Tiene que ser **duradero y no depender de la base**: es justo lo que se usa
 * cuando la base no responde. El adaptador escribe un fichero `0600` por
 * pendiente en el volumen de ficheros generados (ADR-045), fuera del directorio
 * de los paquetes para que su purga por antiguedad no lo borre.
 */
interface PendingDiagnosticsAudits
{
    /**
     * Guarda el pendiente. Si no puede, lanza: sin pendiente no se entrega el
     * paquete, porque su asiento se perderia.
     *
     * @throws DiagnosticsAuditNotDeferred
     */
    public function save(PendingDiagnosticsAudit $pending): void;

    /**
     * Los pendientes guardados, del mas antiguo al mas reciente. Un fichero que
     * no tiene la forma exacta de un pendiente no se devuelve.
     *
     * @return list<PendingDiagnosticsAudit>
     */
    public function all(): array;

    /** Borra el pendiente de esa huella, una vez escrito su asiento. */
    public function remove(string $sha256): void;
}
