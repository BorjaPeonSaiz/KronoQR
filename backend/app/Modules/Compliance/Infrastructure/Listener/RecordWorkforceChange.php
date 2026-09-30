<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Workforce\Domain\Event\DepartmentCreated;
use App\Modules\Workforce\Domain\Event\DepartmentRenamed;
use App\Modules\Workforce\Domain\Event\EmployeeHired;
use App\Modules\Workforce\Domain\Event\EmployeeOffboarded;
use App\Modules\Workforce\Domain\Event\EmployeeProfileUpdated;
use DateTimeImmutable;

/**
 * Sella en `audit_log` el alta, la modificacion y la baja de cada persona de la
 * plantilla, y el alta y el cambio de cada departamento (**AUD-2**, RF-GP-01,
 * RF-GP-02, RF-GP-03, RN-14, regla dura 6, RL-04, RS-05).
 *
 * ## Por que tiene relevancia legal
 *
 * Un cambio de departamento cambia que responsable ve y corrige la jornada de
 * una persona (RF-ID-03); una baja le retira la credencial y cierra su computo
 * (RN-14). Antes de esto `EmployeeHired` solo lo escuchaba el contador del plan
 * y `EmployeeProfileUpdated` y `EmployeeOffboarded` no los escuchaba nadie: la
 * base tenia cero asientos de plantilla, contra lo que el doc 01 afirmaba.
 *
 * ## Solo campos, nunca valores (regla dura 21)
 *
 * Cada asiento lleva el `employee_uuid` —o el `department_id`— y **la lista de
 * campos tocados**, nunca el nombre, el correo, el documento ni el motivo libre
 * de una baja. `audit_log` viaja en la exportacion integra y se consulta desde
 * el panel: lo que responde es «quien toco la ficha de quien, cuando y que
 * campos», que es lo que hace falta para reconstruir el hecho sin copiar datos
 * personales a otra tabla.
 *
 * ## Punto de extension
 *
 * Un campo editable nuevo de la ficha (el teletrabajo del bloque 9) entra en
 * `UpdateEmployeeHandler::changedFields()` y llega aqui dentro de
 * `changed_fields` sin tocar este listener.
 *
 * ## Sincrono y dentro de la transaccion de quien publica
 *
 * Sin `ShouldQueue` (ADR-027): los cinco casos de uso publican dentro de su
 * transaccion, y si el asiento falla el cambio no se confirma. El candado de la
 * cadena lo toma `RecordAuditEntry`; ninguno de estos caminos toca
 * `daily_totals`, asi que no hay orden de candados que respetar con el fichaje.
 */
final readonly class RecordWorkforceChange
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function hired(EmployeeHired $event): void
    {
        $this->record(AuditAction::EmployeeHired, AuditSubject::of('employee'), [
            'employee_uuid' => $event->employeeUuid,
            'site_id' => $event->siteId,
            'department_id' => $event->departmentId,
            // La carga masiva deja ademas su asiento de lote
            // (`employee.imported`); esto dice por que via entro cada persona.
            'via_import' => $event->viaImport,
        ], $event->occurredAt());
    }

    public function updated(EmployeeProfileUpdated $event): void
    {
        // Un `PATCH` que no cambia nada no deja asiento: un asiento que dice que
        // se toco la ficha de alguien cuando no se toco miente.
        if ($event->changedFields === []) {
            return;
        }

        $this->record(AuditAction::EmployeeUpdated, AuditSubject::of('employee'), [
            'employee_uuid' => $event->employeeUuid,
            'changed_fields' => $event->changedFields,
        ], $event->occurredAt());
    }

    public function offboarded(EmployeeOffboarded $event): void
    {
        $this->record(AuditAction::EmployeeOffboarded, AuditSubject::of('employee'), [
            'employee_uuid' => $event->employeeUuid,
            'terminated_on' => $event->terminatedOn,
            // El motivo NO: es texto libre y quien lo escribe puede poner un
            // nombre o un dato de salud. Basta con saber si lo hubo.
            'has_reason' => $event->reason !== null,
        ], $event->occurredAt());
    }

    public function departmentCreated(DepartmentCreated $event): void
    {
        $this->record(AuditAction::DepartmentCreated, AuditSubject::of('department', $event->departmentId), [
            'site_id' => $event->siteId,
        ], $event->occurredAt());
    }

    public function departmentRenamed(DepartmentRenamed $event): void
    {
        $this->record(AuditAction::DepartmentRenamed, AuditSubject::of('department', $event->departmentId), [
            'changed_fields' => $event->changedFields,
        ], $event->occurredAt());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(AuditAction $action, AuditSubject $subject, array $payload, DateTimeImmutable $occurredAt): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            // De la sesion en curso; por consola, `system`.
            actor: $this->context->actor(),
            action: $action,
            subject: $subject,
            payload: AuditPayload::of($payload),
            occurredAt: $occurredAt,
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }
}
