<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Attendance\Domain\Event\DailyTotalsReconciled;
use App\Modules\Attendance\Domain\Event\DailyTotalsSnapshot;
use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\CurrentAuditContext;

/**
 * Deja traza en `audit_log` de cada agregado diario que la reconciliacion ha
 * tenido que corregir (RF-PR-02, ADR-007, regla dura 6).
 *
 * ## Por que esto se audita
 *
 * Porque cambia una cifra de horas de una persona sin que ninguna persona lo
 * haya pedido. El asiento responde a la unica pregunta que importa despues:
 * **que decia la proyeccion antes y que dice ahora**. Sin el, la manana
 * siguiente solo quedaria una metrica que dice «hubo una divergencia» y una fila
 * ya reescrita, que es la definicion de un cambio no trazado sobre el registro
 * horario.
 *
 * **No se audita el recalculo normal.** `DailyTotalsRecalculated` ocurre en cada
 * fichaje y su traza es la del fichaje que lo provoco; auditarlo llenaria la
 * tabla a razon de un asiento por escaneo. Lo que se audita es
 * `DailyTotalsReconciled`, que solo se emite cuando la proyeccion **no
 * coincidia** con sus eventos origen — un hecho que no deberia poder ocurrir
 * nunca (doc 02 §8.2).
 *
 * ## Por que un listener y no una llamada
 *
 * `Attendance` no puede importar `Compliance`: el §1.6 no concede esa arista y
 * Deptrac la verifica. Es la misma via que usan `RecordShiftEntryAudit` y
 * `OpenIncidentOnAnomalyDetected`: el nucleo emite y este modulo reacciona.
 *
 * ## Dentro de la transaccion de la correccion
 *
 * `ReconcileDailyTotals` publica los dos eventos —el que reescribe la fila y
 * este— dentro de la misma transaccion, y el despachador de Laravel es sincrono.
 * Si el asiento falla, **la correccion revierte**: una fila que sigue mintiendo
 * es preferible a una corregida de la que no queda constancia (contrato de
 * `AuditTrail`).
 *
 * ## Nunca nombres
 *
 * El payload identifica por `employee_uuid` (regla dura 21, RGPD). `subject_id`
 * va a nulo: `daily_totals` no tiene identificador propio —su clave es
 * `(employee_id, work_date)`— y resolver aqui la clave interna del empleado
 * obligaria a `Compliance` a consultar una tabla de `Workforce`.
 */
final readonly class RecordProjectionReconciliationAudit
{
    /**
     * La accion del catalogo con la que se sella la correccion de una
     * proyeccion.
     *
     * **PROVISIONAL.** El catalogo de `AuditAction` no tiene todavia un valor
     * propio para esto y no se decide desde aqui: se decide en un solo sitio, que
     * es el enum. La propuesta es `projection.reconciled`, en la familia
     * `AuditableEvent::AuthorityOrCalculationChange` del bloque D —`daily_totals`
     * es el resultado del calculo de la jornada, y lo que este asiento describe
     * es que ese resultado cambio sin que nadie lo pidiera—. El payload lleva
     * ademas `reconciliation: true` para que una consulta por familia pueda
     * separar estas correcciones de los cambios de parametro.
     */
    private const AuditAction ACTION = AuditAction::ProjectionReconciled;

    public function __construct(
        private RecordAuditEntry $audit,
        private CurrentAuditContext $context,
    ) {}

    public function reconciled(DailyTotalsReconciled $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->context->actor(),
            action: self::ACTION,
            // Sin identificador: la clave de la proyeccion es
            // `(employee_id, work_date)` y las dos mitades van en el payload.
            subject: AuditSubject::of('daily_totals'),
            payload: AuditPayload::of([
                'reconciliation' => true,
                'employee_uuid' => $event->employeeUuid,
                'work_date' => $event->workDateIso(),
                // Que columnas no cuadraban. Es lo que distingue «faltaba la
                // fila entera» de «el total estaba mal por diez minutos», y lo
                // que permite agrupar divergencias por causa sin releer el log.
                'divergent_fields' => $event->divergentFields,
                'row_was_missing' => $event->rowWasMissing(),
                // El antes y el despues completos, que es lo que hace util este
                // asiento: `audit_log` es solo-append y encadenado por hash, asi
                // que es la unica copia de lo que la proyeccion afirmaba que ya
                // no se puede tocar (ADR-027).
                //
                // **Los seis campos comparados, no dos** (tarea 3.6). Con el
                // total y el numero de tramos no se puede reconstruir la fila que
                // decia `has_open_shift = true` sobre un turno ya cerrado, que es
                // la divergencia que la prueba de carga destapo; y desde que la
                // correccion no reescribe nada cuando la sospecha se deshace
                // sola, no queda ninguna otra copia de la fila mala.
                'before' => self::snapshot($event->before),
                'after' => self::snapshot($event->after),
            ]),
            // El momento de la CORRECCION, no el de las horas trabajadas: la
            // jornada corregida puede ser de hace tres semanas y sus marcas van
            // dentro, en `before` y `after`.
            occurredAt: $event->occurredAt(),
            ip: $this->context->ip(),
            userAgent: $this->context->userAgent(),
        ));
    }

    /**
     * Una cara del asiento: los seis campos que la reconciliacion compara.
     *
     * `null` cuando no habia fila, y **no** un mapa de nulos: la diferencia entre
     * «la proyeccion decia un dia a cero» y «la proyeccion no decia nada» es la
     * divergencia entera en el caso de la fila ausente.
     *
     * Los instantes van en ISO 8601 con microsegundos, que es lo que el evento
     * ofrece ya formateado: el payload se canonicaliza y se encadena por hash,
     * asi que aqui no cabe ningun objeto.
     *
     * @return array<string, bool|int|string|null>|null
     */
    private static function snapshot(?DailyTotalsSnapshot $snapshot): ?array
    {
        if (! $snapshot instanceof DailyTotalsSnapshot) {
            return null;
        }

        return [
            'total_minutes' => $snapshot->totalMinutes,
            'shift_count' => $snapshot->shiftCount,
            'first_in_at' => $snapshot->firstClockInAtIso(),
            'last_out_at' => $snapshot->lastClockOutAtIso(),
            'has_open_shift' => $snapshot->hasOpenShift,
            'has_incident' => $snapshot->hasIncident,
        ];
    }
}
