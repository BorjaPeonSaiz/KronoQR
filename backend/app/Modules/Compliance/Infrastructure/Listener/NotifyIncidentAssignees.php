<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Attendance\Domain\Event\AttendanceReviewCompleted;
use App\Modules\Compliance\Application\UseCase\NotifyPendingIncidents;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Al terminar la revision diaria, avisa a cada responsable de lo que tiene sin
 * ver (RF-PR-01).
 *
 * ## Al final de la pasada, no por hallazgo
 *
 * Por eso escucha el evento de cierre y no el de cada anomalia: quince hallazgos
 * del mismo departamento son **un** correo con quince lineas. Un aviso por
 * incidencia acabaria en una regla de bandeja de entrada que los archiva todos,
 * que es la forma mas eficaz de que RF-PR-01 deje de cumplirse sin que nadie lo
 * note.
 *
 * ## Y avisa de TODO lo pendiente, no solo de esta pasada
 *
 * El caso de uso lee `notified_at IS NULL`, asi que recoge tambien lo que quedo
 * sin avisar la noche anterior porque el correo fallo o porque el departamento no
 * tenia responsable y ahora si lo tiene. La cola de avisos es una columna, no una
 * memoria de proceso.
 *
 * ## Un fallo aqui no rompe la deteccion
 *
 * Atrapa `Throwable` y lo deja en el log. Las incidencias **ya estan abiertas** y
 * visibles en la bandeja, que es lo que el registro necesita; el aviso es una
 * comodidad encima. Que el comando terminara en error por un servidor de correo
 * mal configurado —lo mas comun de una instalacion recien puesta en marcha— haria
 * pensar que la revision no corrio.
 */
final readonly class NotifyIncidentAssignees
{
    public function __construct(private NotifyPendingIncidents $notify) {}

    public function handle(AttendanceReviewCompleted $event): void
    {
        try {
            $notified = $this->notify->handle();

            if ($notified > 0) {
                // Recuentos, nunca personas ni direcciones (regla dura 21).
                Log::info('incidents.digests_sent', [
                    'managers_notified' => $notified,
                    'site_id' => $event->siteId,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('incidents.digests_failed', [
                'site_id' => $event->siteId,
                'exception' => $exception::class,
            ]);
        }
    }
}
