<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\UseCase;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\Port\AuditTrail;
use App\Modules\Compliance\Domain\ValueObject\AuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditEntryDraft;
use App\Modules\Shared\Application\Port\Clock;

/**
 * Caso de uso publico de `Compliance`: deja traza de un hecho auditable
 * (regla dura 6, `/revision-cumplimiento` bloque D).
 *
 * **Es la puerta que los demas modulos consumen.** El §1.6 admite tres vias de
 * comunicacion entre modulos, y esta es la primera: un caso de uso publico con
 * interfaz explicita. La otra via —evento de dominio recogido por un listener de
 * `Compliance/Infrastructure`— acaba tambien aqui.
 *
 * **No abre transaccion.** La abre el caso de uso que audita, y esta escritura
 * entra en ella: si la auditoria falla, la accion no se confirma. Es lo que hace
 * que «toda accion con relevancia legal escribe en `audit_log`» sea una garantia
 * y no una intencion.
 *
 * **Sin facades** (doc 02 §3.5). El instante llega por el puerto `Clock`
 * (ADR-021, regla dura 2), no por `now()`: sin eso no se puede probar de forma
 * determinista que una entrada del 31 de diciembre a las 23:59:59 UTC cae en la
 * particion del año que le corresponde.
 */
final readonly class RecordAuditEntry
{
    public function __construct(
        private AuditTrail $trail,
        private Clock $clock,
    ) {}

    public function handle(RecordAuditEntryCommand $command): AuditEntry
    {
        $draft = new AuditEntryDraft(
            occurredAt: $command->occurredAt ?? $this->clock->now(),
            actor: $command->actor,
            action: $command->action,
            subject: $command->subject,
            payload: $command->payload,
            ip: $command->ip,
            userAgent: $command->userAgent,
        );

        return $this->trail->append($draft);
    }
}
