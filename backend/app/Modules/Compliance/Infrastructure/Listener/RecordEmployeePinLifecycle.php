<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Workforce\Domain\Event\EmployeePinDelivered;
use App\Modules\Workforce\Domain\Event\EmployeePinIssued;

/**
 * Sella en `audit_log` las tres acciones del PIN: emision, restablecimiento y
 * entrega (RF-ID-09, regla dura 6, `/revision-cumplimiento` bloque D).
 *
 * **Por que las tres tienen relevancia legal.** El PIN da acceso al registro
 * horario personal (RL-05) y permite fichar sin tarjeta (RF-AT-11). Quien lo
 * emite puede, en la practica, entrar a los datos de otra persona o fichar por
 * ella; sin traza, eso no se puede investigar. La entrega es la que convierte
 * «se emitio un PIN» en «esa persona lo recibio», que es lo que hay que poder
 * afirmar el dia que alguien discuta un fichaje por PIN.
 *
 * **EL PIN NO ENTRA AQUI, Y ES EL PUNTO DE TODO ESTO** (regla dura 21,
 * RF-ID-09). El asiento registra que hubo emision, no **que** se emitio. Un
 * `audit_log` con los PIN dentro seria una tabla de credenciales en claro,
 * solo-append, replicada en cada copia de seguridad y exportable en el paquete
 * de diagnostico. El evento de dominio tampoco lo lleva, de modo que aqui no hay
 * nada que filtrar aunque alguien lo intentara.
 *
 * **Por un listener y no por una llamada desde `Workforce`.** El §1.6 no concede
 * la arista `Workforce -> Compliance`: el modulo que produce el hecho no tiene
 * que saber quien lo apunta. Es la misma via que usan el fichaje (1.4) y la
 * credencial (1.5).
 *
 * **Sincrono y dentro de la transaccion de quien publica.** No implementa
 * `ShouldQueue` y no debe hacerlo: si el asiento falla, el PIN no se emite
 * (ADR-027). Un PIN emitido sin traza es peor que uno no emitido, porque el
 * segundo se vuelve a pedir y el primero no se descubre.
 *
 * **El actor sale de la sesion en curso** y no del evento: quien pidio el
 * restablecimiento es una propiedad de la peticion. En un comando de consola no
 * hay sesion y el actor es `system`, que es la respuesta honesta.
 */
final readonly class RecordEmployeePinLifecycle
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function handleIssued(EmployeePinIssued $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            // Emitir y restablecer son dos hechos distintos para quien despues
            // tenga que explicar por que una persona tuvo tres PIN en un ano.
            action: $event->reset ? AuditAction::PinReset : AuditAction::PinIssued,
            // Sin `subject_id`: la columna es un entero y el evento transporta
            // el identificador PUBLICO del empleado, que es el que la API y el
            // registro legal usan. Resolver aqui la clave interna obligaria a
            // `Compliance` a consultar una tabla de `Workforce`, que es
            // exactamente la dependencia que este listener evita.
            subject: AuditSubject::of('pin'),
            payload: AuditPayload::of([
                'employee_uuid' => $event->employeeUuid,
                'site_id' => $event->siteId,
                // Y nada mas. Ni el PIN, ni su hash, ni su longitud, ni una
                // pista sobre como se genero.
            ]),
            occurredAt: $event->occurredAt(),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }

    public function handleDelivered(EmployeePinDelivered $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::PinDelivered,
            subject: AuditSubject::of('pin'),
            payload: AuditPayload::of([
                'employee_uuid' => $event->employeeUuid,
                'site_id' => $event->siteId,
                // Quien entrego, por su UUID publico. Es el dato que hace util
                // el asiento: `actor` dice quien hizo la peticion y esto dice a
                // nombre de quien consta la entrega, que en un mostrador de RRHH
                // pueden no ser la misma persona el dia que haya delegacion.
                'delivered_by' => $event->deliveredByUserUuid,
            ]),
            occurredAt: $event->occurredAt(),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }
}
