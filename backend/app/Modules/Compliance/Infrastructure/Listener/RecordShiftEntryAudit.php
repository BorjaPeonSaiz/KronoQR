<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Attendance\Domain\Event\EmployeeClockedIn;
use App\Modules\Attendance\Domain\Event\EmployeeClockedOut;
use App\Modules\Attendance\Domain\Event\ShiftCorrected;
use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;
use DateTimeImmutable;
use UnhandledMatchError;

/**
 * Deja traza en `audit_log` de cada tramo abierto y cerrado (RL-01, regla dura
 * 6, ADR-032).
 *
 * ## Por que un listener y no una llamada
 *
 * `Attendance` no puede importar `Compliance`: el §1.6 no concede esa arista y
 * Deptrac la verifica. De las tres vias de comunicacion entre modulos, la que
 * corresponde aqui es la segunda: **`Attendance` emite y `Compliance`
 * reacciona**. El nucleo no sabe que esto existe, que es exactamente el punto —
 * el dia que la auditoria cambie de forma, el fichaje no se entera.
 *
 * ## Y por que se ejecuta dentro de la transaccion del fichaje
 *
 * `RegisterScanHandler` publica sus eventos **antes de confirmar**, y el
 * despachador de Laravel es sincrono, asi que esta escritura entra en la misma
 * transaccion. Es la mitad de la garantia de la regla dura 6: si el asiento de
 * auditoria falla, **el fichaje no se confirma** (contrato de `AuditTrail`,
 * ADR-027). Un fichaje que ocurre sin dejar traza es peor que uno que no ocurre,
 * porque el segundo se puede corregir y el primero es indefendible ante una
 * inspeccion.
 *
 * ## Que se escribe y que no
 *
 * **Nunca el nombre del empleado** (regla dura 21, RGPD): el `payload` lo
 * identifica por `employee_uuid`, que es lo que la Inspeccion puede resolver
 * contra `employees` cuando de verdad haga falta. Tampoco su codigo, ni su
 * departamento, ni nada que convierta el trail en un directorio de plantilla
 * exportable.
 *
 * `subject_id` va a nulo y el identificador del tramo viaja en el `payload` como
 * `shift_entry_uuid`. La columna es un entero y el evento de dominio transporta
 * el identificador **publico**, que es el que la API y el registro legal usan;
 * resolver aqui la clave interna obligaria a `Compliance` a consultar una tabla
 * de `Attendance`, que es precisamente la dependencia que este listener existe
 * para evitar.
 *
 * ## El momento del asiento
 *
 * `occurredAt` es el del **hecho** —el `clocked_in_at` real, que puede venir de
 * la cola offline con horas de retraso (regla dura 9, RF-AT-09)—, no el de la
 * escritura. El registro legal cuenta cuando se ficho, no cuando llego.
 */
final readonly class RecordShiftEntryAudit
{
    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function clockedIn(EmployeeClockedIn $event): void
    {
        $this->append(AuditAction::ShiftEntryCreated, $event->occurredAt(), [
            'employee_uuid' => $event->employeeUuid,
            'shift_entry_uuid' => $event->shiftEntryUuid,
            'site_id' => $event->siteId,
            'work_date' => $event->workDate->isoDate,
            'clocked_in_at' => $event->clockedInAt->format('Y-m-d\TH:i:s.u\Z'),
            'origin' => $event->origin->value,
        ]);
    }

    public function clockedOut(EmployeeClockedOut $event): void
    {
        $this->append(AuditAction::ShiftEntryClosed, $event->occurredAt(), [
            'employee_uuid' => $event->employeeUuid,
            'shift_entry_uuid' => $event->shiftEntryUuid,
            'site_id' => $event->siteId,
            'work_date' => $event->workDate->isoDate,
            'clocked_in_at' => $event->clockedInAt->format('Y-m-d\TH:i:s.u\Z'),
            'clocked_out_at' => $event->clockedOutAt->format('Y-m-d\TH:i:s.u\Z'),
            'origin' => $event->origin->value,
            // Los minutos del tramo y el total del dia quedan en el asiento
            // porque son **lo que se afirma**: una correccion posterior (1.15)
            // cambiara el tramo, y la unica forma de demostrar que el valor
            // anterior era otro es tenerlo escrito en una tabla que no se puede
            // modificar (RN-13, RL-04).
            'worked_minutes' => $event->worked->minutes,
            'daily_total_minutes' => $event->dailyTotal->minutes,
            // `array_column` y no un `array_map` con el tipo del enum: `Compliance`
            // solo puede alcanzar `Attendance\Domain\Event` (doc 02 §1.6), asi que
            // nombrar `ShiftAnomaly` aqui seria la frontera que este listener
            // existe para respetar. Los valores respaldados del enum son ya los de
            // `incidents.type`, que es lo que la Fase 2 necesita leer.
            'anomalies' => array_column($event->anomalies, 'value'),
        ]);
    }

    /**
     * Una correccion manual del registro horario (RF-PA-04, RN-13, RL-04,
     * tarea 1.15).
     *
     * **Es el asiento que de verdad importa de esta tabla.** Un fichaje lo
     * produjo una persona con su tarjeta; esto lo produjo **otra persona
     * cambiando las horas de la primera**, y es lo unico que una inspeccion
     * mirara con lupa. Por eso el asiento lleva el antes y el despues completos y
     * no un delta: `shift_corrections` guarda lo mismo, pero `audit_log` es
     * solo-append y encadenado por hash, asi que es la copia que no se puede
     * tocar (ADR-027).
     *
     * **Una accion de `audit_log` por cada accion de RF-PA-04**, no una sola
     * `shift_entry.modified` para las cuatro. El catalogo de `AuditAction` ya las
     * tenia desde la tarea 1.14 y sus valores coinciden con los del enum del
     * dominio a proposito: aqui se traduce, no se inventa el nombre. Colapsarlas
     * haria indistinguible ante Inspeccion un alta retroactiva de una anulacion.
     *
     * **`reason_text` NO entra en el payload, y `reason_code` si.** El codigo es
     * un valor de un catalogo cerrado y es lo que hace la consulta «cuantas
     * correcciones por olvido de fichaje hubo en marzo». El texto libre lo
     * escribio una persona sobre otra, puede contener cualquier cosa, y su sitio
     * es `shift_corrections`, que se lee con autorizacion; `audit_log` viaja
     * entero en la exportacion legal y su payload se revisa entero.
     *
     * **`occurredAt` es el momento de la CORRECCION**, no el de las horas
     * trabajadas. Un tramo de hace tres semanas corregido hoy produce un asiento
     * de hoy, y las horas van dentro, en `before` y `after`. Es la unica lectura
     * que permite responder «que se toco esta semana».
     */
    public function corrected(ShiftCorrected $event): void
    {
        $this->append(self::actionFor($event->action->value), $event->occurredAt(), [
            'employee_uuid' => $event->employeeUuid,
            // El tramo al que apunta la fila de `shift_corrections`: la version
            // que la accion produjo, o la que termino si fue una anulacion.
            'shift_entry_uuid' => $event->correctedShiftEntryUuid(),
            // Y el que deja de ser vigente, cuando lo hay. Con los dos, el
            // historico se recorre desde el trail sin consultar `shift_entries`.
            'superseded_shift_entry_uuid' => $event->replacementShiftEntryUuid === null
                ? null
                : $event->shiftEntryUuid,
            'site_id' => $event->siteId,
            'work_date' => $event->workDate->isoDate,
            'action' => $event->action->value,
            'version' => $event->replacementVersion ?? $event->shiftEntryVersion,
            'before' => self::marks($event->before?->clockedInAt, $event->before?->clockedOutAt),
            'after' => self::marks($event->after?->clockedInAt, $event->after?->clockedOutAt),
            'reason_code' => $event->correction->reason->code->value,
            'performed_by_user_id' => $event->correction->performedByUserId,
            'daily_total_minutes' => $event->dailyTotal->minutes,
            // Ver el metodo `clockedOut()`: `Compliance` solo alcanza
            // `Attendance\Domain\Event`, asi que nombrar `ShiftAnomaly` aqui
            // seria la frontera que este listener existe para respetar.
            'anomalies' => array_column($event->anomalies, 'value'),
        ]);
    }

    /**
     * Las marcas de una version, o `null` cuando esa version no existe: no hay
     * `before` en un alta ni `after` en una anulacion.
     *
     * **Recibe los dos instantes sueltos y no el objeto de valor `ShiftTimes`**,
     * y no es descuido: `Compliance` solo puede alcanzar
     * `Attendance\Domain\Event` (doc 02 §1.6, verificado por Deptrac). Nombrar
     * aqui un objeto de `Attendance\Domain\ValueObject` seria justo la frontera
     * que este listener existe para respetar; leer sus propiedades publicas
     * desde el evento, no. Es la misma tecnica que usa `clockedOut()` con
     * `ShiftAnomaly`.
     *
     * Se serializa aqui porque el payload de `audit_log` es JSON canonico y
     * tiene que ser reproducible byte a byte para que la cadena de hash
     * verifique (ADR-027).
     *
     * @return array<string, string|null>|null
     */
    private static function marks(?DateTimeImmutable $clockedInAt, ?DateTimeImmutable $clockedOutAt): ?array
    {
        // Sin entrada no hay version: un tramo siempre tiene hora de entrada, asi
        // que su ausencia solo puede significar que ese lado no existe.
        if (! $clockedInAt instanceof DateTimeImmutable) {
            return null;
        }

        return [
            'clocked_in_at' => $clockedInAt->format('Y-m-d\TH:i:s.u\Z'),
            'clocked_out_at' => $clockedOutAt?->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    /**
     * La accion del catalogo que corresponde a la del dominio.
     *
     * La correspondencia es el sufijo del valor —`created`, `modified`,
     * `closed`, `voided`— y por eso se resuelve por tabla y no con un `match` de
     * cuatro ramas: no hay ninguna decision que tomar, solo un nombre que
     * traducir. Un valor nuevo del enum del dominio rompe aqui en la primera
     * prueba que lo toque, que es como debe enterarse quien lo añada de que
     * tiene que decir tambien como se audita.
     */
    private static function actionFor(string $action): AuditAction
    {
        return match ($action) {
            'created' => AuditAction::ShiftEntryCreated,
            'modified' => AuditAction::ShiftEntryModified,
            'closed' => AuditAction::ShiftEntryClosed,
            'voided' => AuditAction::ShiftEntryVoided,
            default => throw new UnhandledMatchError(
                'No audit action for shift correction «'.$action.'».',
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function append(AuditAction $action, DateTimeImmutable $occurredAt, array $payload): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: $action,
            subject: AuditSubject::of('shift_entry'),
            payload: AuditPayload::of($payload),
            occurredAt: $occurredAt,
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }
}
