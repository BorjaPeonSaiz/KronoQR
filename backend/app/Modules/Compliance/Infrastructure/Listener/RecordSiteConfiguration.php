<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Workforce\Domain\Event\SiteConfigured;

/**
 * Sella en `audit_log` el alta y la modificacion del centro de la instalacion
 * (**RF-PD-03**, **RN-05**, regla dura 6, `/revision-cumplimiento` bloque D).
 *
 * ## Por que tiene relevancia legal
 *
 * Por `timezone`. La jornada de un tramo es la fecha civil de su hora de inicio
 * **en la zona del centro**: cambiarla mueve horas de un dia a otro para toda la
 * plantilla, a partir de ese instante y sin tocar un solo fichaje. Es la misma
 * potestad que cambiar un umbral de calculo, y sin traza la pregunta «¿por que
 * el turno de noche de marzo cuenta en otro dia que el de febrero?» no tiene
 * respuesta.
 *
 * ## Deuda saldada
 *
 * `UpdateSiteHandler` afirmaba desde la tarea 1.6 que el cambio de zona «queda
 * auditado por el oyente de `Compliance`». **No habia oyente ni asiento**: el
 * caso de uso no publicaba ningun evento. La tarea 5.5 lo cierra, porque tenia
 * que auditar el alta del centro y dejar la modificacion sin auditar al lado
 * habria sido peor que no tener ninguna de las dos.
 *
 * ## `subject_id` si va, al contrario que en el contrato de empleado
 *
 * `sites.id` **es** la clave interna y no hay identificador publico alternativo:
 * la tabla no tiene UUID (doc 01 §5.5). Y no revela nada, porque con un centro
 * por instalacion (ADR-040) siempre vale lo mismo.
 *
 * ## Sincrono y dentro de la transaccion de quien publica
 *
 * Sin `ShouldQueue` (ADR-027): si el asiento falla, el centro no se crea. Un
 * centro creado sin constancia de con que zona horaria nacio deja el registro
 * legal sin el dato que explica como se calcularon las jornadas.
 */
final readonly class RecordSiteConfiguration
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function handle(SiteConfigured $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            // De la sesion en curso. En el alta del centro esa sesion es la del
            // primer administrador, que es exactamente la razon por la que el
            // asistente lo crea antes que nada: sin el, este asiento saldria
            // como `system` y no responderia a quien puso la zona horaria.
            actor: $this->context->actor(),
            action: $event->created ? AuditAction::SiteCreated : AuditAction::SiteUpdated,
            subject: AuditSubject::of('site', $event->siteId),
            // El antes y el despues, que es lo que hace el asiento
            // reconstruible (RL-04) y la misma convencion que
            // `RecordInstallationSettingChange`. Sin el valor previo, el asiento
            // no dice si el PATCH cambio la zona horaria —que mueve las horas de
            // toda la plantilla de un dia a otro— o solo el rotulo del hotel.
            //
            // En el alta, `previous_value` es `null`: no habia centro. Ninguno
            // de los dos lleva datos personales (regla dura 21).
            payload: AuditPayload::of([
                'previous_value' => $event->created ? null : [
                    'name' => $event->previousName,
                    'timezone' => $event->previousTimezone,
                ],
                'new_value' => [
                    'name' => $event->name,
                    // El campo por el que este asiento existe.
                    'timezone' => $event->timezone,
                ],
            ]),
            occurredAt: $event->occurredAt(),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }
}
