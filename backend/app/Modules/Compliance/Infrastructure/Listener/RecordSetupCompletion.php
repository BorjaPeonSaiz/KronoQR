<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Product\Domain\Event\SetupCompleted;

/**
 * Sella en `audit_log` el cierre del asistente de puesta en marcha
 * (**RF-PD-03**, regla dura 6, RL-04).
 *
 * ## Por que tiene relevancia legal
 *
 * El asistente **no se reabre**, y esa irreversibilidad se justifica con RL-04:
 * reabrirlo seria una via para reconfigurar la instalacion —la zona horaria del
 * centro, que decide a que dia van las horas— sin dejar rastro. Un acto que se
 * justifica por el trail tiene que estar en el trail.
 *
 * ## Que lleva el asiento
 *
 * Los pasos **omitidos**, que es lo unico que no se puede reconstruir despues:
 * `setup_progress` es una tabla normal y editable, mientras que `audit_log` es
 * solo-append y encadenado por hash. Ningun dato personal (regla dura 21).
 *
 * ## Quien lo cerro sale de la sesion, no del evento
 *
 * Como en el resto de oyentes de este modulo: quien hace el cambio no puede
 * declarar quien es, y una sola fuente evita dos valores que discrepen. El
 * evento no transporta actor.
 *
 * ## Sin `subject_id`
 *
 * El sujeto es la instalacion entera, que no tiene fila. `AuditSubject::of()` con
 * `null` es exactamente eso.
 *
 * ## Sincrono y dentro de la transaccion de quien publica
 *
 * Sin `ShouldQueue` (ADR-027): si el asiento falla, el asistente **no se cierra**
 * y se puede reintentar. Cerrarlo sin traza seria cerrar la unica puerta que se
 * cierra para siempre sin constancia de quien la cerro.
 */
final readonly class RecordSetupCompletion
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function handle(SetupCompleted $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::SetupCompleted,
            subject: AuditSubject::of('setup', null),
            payload: AuditPayload::of([
                'skipped_steps' => $event->skippedSteps,
            ]),
            occurredAt: $event->occurredAt(),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }
}
