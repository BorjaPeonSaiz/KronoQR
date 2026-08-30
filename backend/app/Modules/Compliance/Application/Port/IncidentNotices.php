<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use DateTimeImmutable;

/**
 * Que incidencias estan pendientes de avisar y como se sella que ya se aviso
 * (RF-PR-01).
 *
 * **La cola de avisos es una columna, no una cola.** `incidents.notified_at` a
 * nulo significa «esta persona todavia no sabe que existe». Con eso, un correo
 * que falla no se pierde —entra en el resumen de la noche siguiente— y uno que
 * se envio no se repite. Una cola en memoria o un contador en Redis habrian
 * perdido lo primero y no habrian impedido lo segundo.
 */
interface IncidentNotices
{
    /**
     * Incidencias abiertas, con responsable asignado y sin avisar, agrupadas por
     * responsable.
     *
     * **Las que no tienen responsable no salen aqui y no se pierden**: siguen
     * abiertas y visibles en la bandeja hasta que alguien nombre responsable del
     * departamento (doc 01 §5.5). Avisar «a nadie» no es una opcion, y descartar
     * la incidencia tampoco.
     *
     * @return list<IncidentDigest>
     */
    public function pendingByManager(): array;

    /**
     * Sella el aviso de esas incidencias.
     *
     * Se llama **despues** de que el aviso se haya entregado a la cola, no antes:
     * sellar primero convertiria un fallo de correo en un aviso perdido para
     * siempre.
     *
     * @param  list<int>  $incidentIds
     */
    public function markNotified(array $incidentIds, DateTimeImmutable $at): void;
}
