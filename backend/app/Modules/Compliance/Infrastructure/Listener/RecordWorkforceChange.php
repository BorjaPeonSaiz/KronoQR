<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use App\Modules\Compliance\Infrastructure\Audit\ManagementUserDirectory;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Domain\Event\DepartmentCreated;
use App\Modules\Workforce\Domain\Event\DepartmentManagerChanged;
use App\Modules\Workforce\Domain\Event\DepartmentRenamed;
use App\Modules\Workforce\Domain\Event\EmployeeHired;
use App\Modules\Workforce\Domain\Event\EmployeeOffboarded;
use App\Modules\Workforce\Domain\Event\EmployeeOffboardingCancelled;
use App\Modules\Workforce\Domain\Event\EmployeeOffboardingScheduled;
use App\Modules\Workforce\Domain\Event\EmployeeProfileUpdated;
use App\Modules\Workforce\Domain\Event\EmployeeRehired;
use App\Modules\Workforce\Domain\Event\TerminationDateCorrected;
use DateTimeImmutable;

/**
 * Sella en `audit_log` el alta, la modificacion y la baja de cada persona de la
 * plantilla, y el alta y el cambio de cada departamento (RF-GP-01,
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
 * **Dos excepciones, con sus dos valores**: el cambio de
 * departamento lleva `previous_department_id` y `department_id`, y el
 * renombrado de un departamento, `previous_name` y `name`. Las dos son datos de
 * la organizacion, no de la persona, y sin ellos el asiento no dice de cual a
 * cual. Los asientos anteriores a la 2.3.0 no se tocan (solo-append): siguen
 * llevando solo la lista.
 *
 * ## Punto de extension
 *
 * Un campo editable nuevo de la ficha (el teletrabajo, por ejemplo) entra en
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
        private ManagementUserDirectory $users,
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
            // El valor inicial del teletrabajo (RF-GP-01), como `department_id`:
            // sin el, el primer `changed_fields: [teleworking]` posterior no se
            // podria leer. Es un si o no informativo, sin nada que identifique a
            // nadie mas alla del `employee_uuid` que ya va en el asiento.
            'teleworking' => $event->teleworking,
        ], $event->occurredAt());
    }

    public function updated(EmployeeProfileUpdated $event): void
    {
        // Un `PATCH` que no cambia nada no deja asiento: un asiento que dice que
        // se toco la ficha de alguien cuando no se toco miente.
        if ($event->changedFields === []) {
            return;
        }

        $payload = [
            'employee_uuid' => $event->employeeUuid,
            'changed_fields' => $event->changedFields,
        ];

        // De cual a cual: solo si cambio, para que la ausencia de las
        // dos claves diga «no se movio» sin tener que mirar la lista.
        if ($event->changedDepartment()) {
            $payload['previous_department_id'] = $event->previousDepartmentId;
            $payload['department_id'] = $event->departmentId;
        }

        $this->record(AuditAction::EmployeeUpdated, AuditSubject::of('employee'), $payload, $event->occurredAt());
    }

    public function offboarded(EmployeeOffboarded $event): void
    {
        $payload = [
            'employee_uuid' => $event->employeeUuid,
            'terminated_on' => $event->terminatedOn,
            // El motivo NO: es texto libre y quien lo escribe puede poner un
            // nombre o un dato de salud. Basta con saber si lo hubo.
            'has_reason' => $event->reason !== null,
        ];

        // «Aplicar ya» con cese de hoy: el despido con efecto inmediato (RN-14).
        if ($event->applyNow) {
            $payload['apply_now'] = true;
        }

        // La aplico la tarea al vencer (ADR-062 §4): el actor es `system`, y el
        // asiento dice que dia tocaba y cuantos llego tarde.
        if ($event->scheduled) {
            $payload['scheduled'] = true;
            $payload['due_on'] = $event->dueOn;
            $payload['days_late'] = $event->daysLate;

            if ($event->deferred) {
                $payload['deferred'] = true;
            }
        }

        $this->record(AuditAction::EmployeeOffboarded, AuditSubject::of('employee'), $payload, $event->occurredAt());
    }

    /**
     * Baja programada o reprogramada (RN-14, ADR-062 §9). La fecha anterior
     * solo viaja al reprogramar: su ausencia dice que es la primera.
     */
    public function offboardingScheduled(EmployeeOffboardingScheduled $event): void
    {
        $payload = [
            'employee_uuid' => $event->employeeUuid,
            'terminated_on' => $event->terminatedOn,
            'has_reason' => $event->reason !== null,
        ];

        if ($event->previousTerminatedOn !== null) {
            $payload['previous_terminated_on'] = $event->previousTerminatedOn;
        }

        $this->record(AuditAction::EmployeeOffboardingScheduled, AuditSubject::of('employee'), $payload, $event->occurredAt());
    }

    /**
     * Anulacion de una baja programada (RN-14, ADR-062 §9), con la fecha anulada.
     */
    public function offboardingCancelled(EmployeeOffboardingCancelled $event): void
    {
        $this->record(AuditAction::EmployeeOffboardingCancelled, AuditSubject::of('employee'), [
            'employee_uuid' => $event->employeeUuid,
            'terminated_on' => $event->terminatedOn,
        ], $event->occurredAt());
    }

    /**
     * Readmision (RN-27, ADR-062 §9): el periodo nuevo, su fecha de alta y el
     * departamento con el que vuelve. El anterior solo si cambia, como en
     * `employee.updated`.
     */
    public function rehired(EmployeeRehired $event): void
    {
        $payload = [
            'employee_uuid' => $event->employeeUuid,
            'employment_period_uuid' => $event->employmentPeriodUuid,
            'hired_at' => $event->hiredOn,
            'department_id' => $event->departmentId,
            'pin_reset' => $event->pinReset,
            'via_import' => $event->viaImport,
        ];

        if ($event->departmentChanged()) {
            $payload['previous_department_id'] = $event->previousDepartmentId;
        }

        $this->record(AuditAction::EmployeeRehired, AuditSubject::of('employee'), $payload, $event->occurredAt());
    }

    /**
     * Correccion de la fecha de cese (RN-27, RL-04, ADR-062 §9): las dos fechas
     * y la fila de la correccion, **nunca el motivo** (regla dura 21).
     */
    public function terminationDateCorrected(TerminationDateCorrected $event): void
    {
        $this->record(AuditAction::EmployeeTerminationDateCorrected, AuditSubject::of('employee'), [
            'employee_uuid' => $event->employeeUuid,
            'employment_period_uuid' => $event->employmentPeriodUuid,
            'previous_terminated_on' => $event->previousTerminatedOn,
            'terminated_on' => $event->terminatedOn,
            'correction_id' => $event->correctionId,
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
            // De cual a cual. El nombre de un departamento es de la
            // organizacion, no de una persona: no le alcanza la regla dura 21.
            'previous_name' => $event->previousName,
            'name' => $event->name,
        ], $event->occurredAt());
    }

    /**
     * El responsable de un departamento ha cambiado (RF-ID-03, RF-ID-10,
     * ADR-051 §5): **un `role_assignment.changed` por cada cuenta afectada**,
     * la que deja de dirigirlo (`revoked`) y la que pasa a dirigirlo
     * (`granted`). Es un cambio de permisos de dos personas, y cada una tiene
     * que poder reconstruir el suyo consultando sus propios asientos.
     *
     * Mismo asiento que el alta de una cuenta con rol (`subject` la cuenta,
     * `user_uuid` y `role`), mas el `department_id` y el sentido. Solo uuid,
     * nunca el nombre (regla dura 21).
     */
    public function departmentManagerChanged(DepartmentManagerChanged $event): void
    {
        $changes = [
            'revoked' => $event->previousManagerUuid,
            'granted' => $event->newManagerUuid,
        ];

        foreach ($changes as $change => $userUuid) {
            if ($userUuid === null) {
                continue;
            }

            $this->record(AuditAction::RoleAssignmentChanged, AuditSubject::of('user', $this->users->idOf($userUuid)), [
                'user_uuid' => $userUuid,
                'role' => UserRole::RESPONSABLE_DEPARTAMENTO->value,
                'department_id' => $event->departmentId,
                'change' => $change,
            ], $event->occurredAt());
        }
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
