<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Product\Domain\Event\SupportAccessGranted;
use DateTimeInterface;

/**
 * Sella en `audit_log` la concesion de un acceso de soporte (**RF-PD-11**,
 * RL-18, RL-04, regla dura 6).
 *
 * ## Que pregunta responde este asiento
 *
 * «¿Quien autorizo que el fabricante entrara en esta instalacion, para que, con
 * que alcance y hasta cuando?». **Es la prueba del encargo de tratamiento** del
 * art. 28 RGPD para ese supuesto concreto (RL-18): sin el, lo unico que
 * acreditaria el permiso del cliente seria una fila que la aplicacion puede
 * actualizar.
 *
 * ## `subject_id` es nulo y el identificador viaja en el payload
 *
 * Mismo criterio que la licencia: el sujeto —«los accesos de soporte de esta
 * instalacion»— no es una fila con identificador estable de cara al trail. Lo
 * que identifica a la concesion es su `grant_id`, y va dentro.
 *
 * ## Lo que NO va en el payload
 *
 * **El token, ni entero ni su hash.** El asiento acaba en el trail, el trail se
 * exporta y se conserva cuatro años. Y no hay ni un dato de empleado: aqui no
 * los hay (regla dura 21).
 *
 * ## Sincrono y dentro de la transaccion
 *
 * Sin `ShouldQueue` y sin `afterCommit`: si el asiento falla, **la concesion no
 * se guarda y el token no llega a existir** (ADR-027, regla dura 6). Entre «no
 * se concede» y «se concede sin traza», lo primero es un reintento y lo segundo
 * es exactamente el agujero que ADR-020 existe para cerrar.
 *
 * **Por un listener y no por una llamada desde `Product`**: el §1.6 no concede
 * la arista `Product -> Compliance`. Misma via que la licencia, la configuracion
 * y el perfil de cumplimiento.
 */
final readonly class RecordSupportGrantGranted
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function handle(SupportAccessGranted $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            // Quien lo hizo lo resuelve la sesion en curso. Por consola no hay
            // sesion y queda `system`, que distingue «lo autorizo Marta desde el
            // panel» de «lo autorizo quien tiene acceso al servidor». El
            // `granted_by_user_id` del payload dice a nombre de quien consta.
            actor: $this->context->actor(),
            action: AuditAction::SupportGrantGranted,
            subject: AuditSubject::of('support_grant'),
            payload: AuditPayload::of([
                'grant_id' => $event->grantId,
                'grant_uuid' => $event->grantUuid,
                'scope' => $event->scope,
                'hours' => $event->hours,
                'expires_at' => $event->expiresAt->format(DateTimeInterface::ATOM),
                // Tal cual lo escribio quien concedio. Es lo que permite ver
                // despues que el acceso se uso para otra cosa (ADR-020, T1199).
                'reason' => $event->reason,
                'granted_by_user_id' => $event->grantedByUserId,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }
}
