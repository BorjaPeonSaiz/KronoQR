<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Port\IncidentNotices;
use App\Modules\Compliance\Application\Port\IncidentNotifier;
use App\Modules\Shared\Application\Port\Clock;

/**
 * Avisa a cada responsable de las incidencias que tiene sin ver (RF-PR-01: «se
 * notifica al responsable del departamento»).
 *
 * **Un resumen por persona y por pasada.** Quince hallazgos del mismo
 * departamento son un correo con quince lineas, no quince correos: un aviso que
 * nadie lee es lo mismo que no avisar.
 *
 * **El sello va despues del envio, y solo si el envio salio.** `notified_at` se
 * escribe cuando el aviso se ha entregado; si el correo falla, la incidencia
 * sigue pendiente y entra en el resumen de la noche siguiente. Al reves —sellar
 * antes— un servidor de correo mal configurado convertiria cada incidencia en un
 * aviso perdido para siempre, y nadie se enteraria.
 *
 * **Y un fallo de correo no rompe la deteccion.** Las incidencias ya estan
 * abiertas y visibles en la bandeja, que es lo que el registro necesita; el
 * aviso es una comodidad encima. Quien atrapa el fallo es el adaptador, que
 * devuelve `false`.
 */
final readonly class NotifyPendingIncidents
{
    public function __construct(
        private IncidentNotices $notices,
        private IncidentNotifier $notifier,
        private Clock $clock,
    ) {}

    /**
     * Devuelve a cuantos responsables se ha avisado.
     */
    public function handle(): int
    {
        $sent = 0;

        foreach ($this->notices->pendingByManager() as $digest) {
            if (! $this->notifier->notify($digest)) {
                continue;
            }

            $this->notices->markNotified($digest->incidentIds(), $this->clock->now());
            $sent++;
        }

        return $sent;
    }
}
