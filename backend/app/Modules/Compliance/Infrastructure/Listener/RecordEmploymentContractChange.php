<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Workforce\Domain\Event\EmploymentContractRegistered;

/**
 * Sella en `audit_log` el registro del contrato de una persona (**RF-GP-02**,
 * regla dura 6, `/revision-cumplimiento` bloque E).
 *
 * ## Por que tiene relevancia legal
 *
 * `weekly_hours` es la cifra contra la que el informe de RF-IN-03 mide las horas
 * trabajadas de alguien: cambiarla cambia su desviacion y sus horas de exceso.
 * Quien la toca puede convertir cien horas de exceso en cero sin tocar un solo
 * fichaje, y sin traza eso no se puede investigar. Es exactamente la familia
 * «cambia roles, permisos o parametros del calculo» del bloque D.
 *
 * ## Que lleva el asiento
 *
 * Lo suficiente para reconstruir el cambio: a quien —por su UUID publico—,
 * desde cuando, cuantas horas quedan, y del contrato que se cierra cuantas horas
 * tenia y hasta cuando quedo vigente. **Ningun nombre** (regla dura 21). El actor lo resuelve
 * {@see CurrentAuditContext} de la sesion en curso, no el evento: quien hace el
 * cambio no puede declarar quien es.
 *
 * ## Sincrono y dentro de la transaccion de quien publica
 *
 * No implementa `ShouldQueue` y no debe hacerlo: si el asiento falla, el
 * contrato no se registra (ADR-027). Un cambio de horas contratadas sin traza es
 * peor que un cambio que no llega a producirse, porque el segundo se vuelve a
 * intentar y el primero no se descubre.
 *
 * **Por un listener y no por una llamada desde `Workforce`**: el §1.6 no concede
 * la arista `Workforce -> Compliance`. Es la misma via del alta, la baja y el
 * PIN.
 */
final readonly class RecordEmploymentContractChange
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function handle(EmploymentContractRegistered $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: AuditAction::EmploymentContractRegistered,
            // Sin `subject_id`: la columna es un entero y el evento transporta
            // el identificador PUBLICO del empleado. Resolver aqui la clave
            // interna obligaria a `Compliance` a consultar una tabla de
            // `Workforce`, que es la dependencia que este listener evita.
            subject: AuditSubject::of('employment_contract'),
            payload: AuditPayload::of([
                'employee_uuid' => $event->employeeUuid,
                'site_id' => $event->siteId,
                'weekly_hours' => $event->weeklyHours,
                'annual_hours' => $event->annualHours,
                'schedule_type' => $event->scheduleType,
                'valid_from' => $event->validFrom,
                // Hasta cuando quedo el anterior, o `null` si no habia. Sin
                // esto, el asiento no distingue «se le abrio el primer contrato»
                // de «se le cambio el que tenia», que es la pregunta que se hace
                // quien revisa por que las horas contratadas de alguien no
                // cuadran con su nomina.
                'previous_valid_to' => $event->previousValidTo,
                // Y el ANTES de la cifra, no solo el despues. Con `weekly_hours`
                // a secas, «¿quien le bajo las horas y cuanto?» solo se contesta
                // reconstruyendo la serie de contratos desde el primero; con las
                // dos cifras en el mismo asiento, se lee de una fila.
                'previous_weekly_hours' => $event->previousWeeklyHours,
                'previous_annual_hours' => $event->previousAnnualHours,
            ]),
            occurredAt: $event->occurredAt(),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }
}
