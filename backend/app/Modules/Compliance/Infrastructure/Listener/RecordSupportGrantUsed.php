<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Product\Domain\Event\SupportAccessUsed;

/**
 * Sella en `audit_log` el uso de un acceso de soporte (**RF-PD-11**, ADR-020,
 * regla dura 6).
 *
 * ## Que pregunta responde
 *
 * «¿Llego a entrar el fabricante, cuando y por donde empezo?». Conceder y usar
 * son hechos distintos: una concesion de 72 horas que nadie uso y otra que se
 * uso las 72 enteras dejan el mismo asiento de concesion.
 *
 * ## El actor es la CONCESION, no una persona
 *
 * `AuditActorType::SupportGrant`, con `actor_id` = `support_grants.id`. Lo
 * resuelve {@see CurrentAuditContext} a partir del `tokenable` de la peticion, y
 * es lo que permite responder «¿que hizo el acceso que concedi el martes?» con
 * un filtro por columna indexada. **No hay ninguna cuenta del fabricante en la
 * instalacion** y por eso no puede haber ningun `user` aqui (ADR-020, regla dura
 * 16).
 *
 * ## Uno por ventana, no uno por peticion
 *
 * Lo decide el caso de uso antes de publicar (ver `SupportAccessRecorder`). Un
 * asiento por peticion serian cientos de escrituras bajo el candado global de
 * ADR-010 —el mismo por el que pasa cada fichaje— en una sesion de veinte
 * minutos. Misma palanca que ADR-037 con las lecturas de datos personales.
 *
 * ## Lo que lleva la ruta, y lo que no
 *
 * El **patron** de la ruta y su metodo, nunca la URL concreta: un UUID de
 * empleado escrito en `audit_log.payload` seria un dato personal en una tabla
 * que se exporta (regla dura 21).
 */
final readonly class RecordSupportGrantUsed
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function handle(SupportAccessUsed $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::SupportGrantUsed,
            subject: AuditSubject::of('support_grant'),
            payload: AuditPayload::of([
                'grant_id' => $event->grantId,
                'grant_uuid' => $event->grantUuid,
                'scope' => $event->scope,
                'method' => $event->method,
                'route' => $event->route,
                // Para que quien lo lea dentro de dos años sepa que esta viendo
                // el principio de una sesion y no un acto aislado.
                'grouped_by_window' => true,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }
}
