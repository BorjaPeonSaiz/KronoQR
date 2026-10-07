<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Product\Domain\Event\SupportAccessRevoked;

/**
 * Sella en `audit_log` la revocacion de un acceso de soporte (**RF-PD-11**,
 * ADR-020, regla dura 6).
 *
 * ## Que pregunta responde
 *
 * «¿Hasta cuando duro de verdad ese acceso?». No basta con el asiento de
 * concesion: una concedida por 72 horas y cortada a los diez minutos y otra
 * usada entera son dos hechos completamente distintos ante una pregunta del art.
 * 28 RGPD, y el asiento de concesion es identico en las dos.
 *
 * ## Solo cuando la revocacion OCURRE
 *
 * Revocar una ya revocada no publica evento y por tanto no llega aqui: la
 * segunda pulsacion de un boton no es un hecho nuevo. La fila no se borra en
 * ningun caso (regla dura 5).
 *
 * `was_active` distingue «se corto un acceso vivo» de «se retiro uno que ya
 * habia caducado», que es la diferencia entre una decision urgente y una
 * limpieza.
 */
final readonly class RecordSupportGrantRevoked
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function handle(SupportAccessRevoked $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::SupportGrantRevoked,
            subject: AuditSubject::of('support_grant'),
            payload: AuditPayload::of([
                'grant_id' => $event->grantId,
                'grant_uuid' => $event->grantUuid,
                'scope' => $event->scope,
                // Nulo cuando no hubo sesion detras —la consola, o una baja
                // hecha por consola—: no hay a quien atribuirlo, y eso tambien
                // es informacion. Con la baja desde el panel, quien la hizo.
                'revoked_by_user_id' => $event->revokedByUserId,
                'was_active' => $event->wasActive,
                // RF-ID-10: cayo con la baja de la cuenta que lo concedio.
                ...($event->cause === null ? [] : [
                    'cause' => $event->cause,
                    'deactivated_account_uuid' => $event->deactivatedAccountUuid,
                ]),
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }
}
