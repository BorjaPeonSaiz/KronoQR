<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain;

use App\Modules\Compliance\Domain\ValueObject\AuditedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\RecordedShiftEntry;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancy;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordDiscrepancyKind;
use App\Modules\Compliance\Domain\ValueObject\WorkRecordPair;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * La regla de la conciliacion entre el registro horario y su auditoria
 * (ADR-057 §4, RL-04, RS-07, reglas duras 5 y 6).
 *
 * ## Por que hace falta, si la cadena de hash ya se verifica
 *
 * La cadena protege `audit_log`, no `shift_entries`. La aplicacion tiene
 * `INSERT`, `UPDATE` y `DELETE` sobre el registro horario (ADR-057, contexto), y
 * quien ejecute codigo con su credencial puede cambiar la entrada de un tramo,
 * borrarlo o inventarse uno **sin tocar la cadena**, que sigue en verde. La
 * exportacion para la Inspeccion lee `shift_entries` y saldria manipulada. Esta
 * regla compara cada tramo con el ultimo asiento que la aplicacion escribio
 * sobre el en la misma transaccion, y el asiento es lo que no se puede
 * reescribir.
 *
 * ## Que NO es una discrepancia, y por que
 *
 * Es la mitad de la regla, y es la que decide si la alerta se cree:
 *
 * - **Un tramo sin asiento cuya entrada cae en un año de `audit_log` ya
 *   purgado** (ADR-027). La particion se suelta entera cuando todo el año vence,
 *   pero un tramo de la noche del 31 de diciembre puede tener `work_date` del 1
 *   de enero siguiente y sobrevivir unos dias a su asiento.
 * - **Un asiento sin tramo cuya jornada es anterior al corte de la ultima purga
 *   auditada** (`retention.purge_executed`, RL-02). La purga borra los tramos y
 *   deja su propio asiento con la fecha de corte; un borrado que no lleva ese
 *   asiento no se puede disfrazar de purga, porque el asiento no se puede
 *   escribir sin la aplicacion ni borrar sin romper la cadena.
 *
 * Y nada mas. No hay tolerancia ni umbral: una sola discrepancia es una
 * escritura en el registro por fuera de la aplicacion.
 *
 * ## Las transiciones legitimas que no escriben asiento
 *
 * No existen sobre `shift_entries`, y eso es lo que permite comparar sin falsos
 * positivos. Las cinco escrituras del producto —fichar entrada, fichar salida,
 * alta manual, correccion y anulacion— pasan por `WorkDay` y publican su evento
 * **dentro** de la transaccion, y `RecordShiftEntryAudit` escribe el asiento en
 * esa misma transaccion. No hay cierre automatico (RN-08): la deteccion de
 * incidencias abre incidencias y no toca el tramo. La proyeccion escribe en
 * `daily_totals`, no aqui. La sustitucion de una version por otra la cuenta el
 * asiento de la correccion por los dos lados (`shift_entry_uuid` y
 * `superseded_shift_entry_uuid`).
 *
 * ## Pura
 *
 * Sin reloj, sin base de datos y sin `Illuminate` (regla dura 1). Recibe el par
 * ya leido y el contexto de purgas ya resuelto.
 */
final class WorkRecordReconciliation
{
    private function __construct() {}

    /**
     * Compara un tramo con su ultimo asiento, con las purgas que el par trae de
     * la misma instantanea.
     */
    public static function compare(WorkRecordPair $pair): ?WorkRecordDiscrepancy
    {
        $recorded = $pair->recorded;
        $audited = $pair->audited;

        if ($recorded instanceof RecordedShiftEntry && $audited instanceof AuditedShiftEntry) {
            return self::compareBoth($pair->shiftEntryUuid, $recorded, $audited);
        }

        if ($recorded instanceof RecordedShiftEntry) {
            return self::auditPurgedAlongTheYear($recorded, $pair->sealedAuditYears)
                ? null
                : new WorkRecordDiscrepancy($pair->shiftEntryUuid, WorkRecordDiscrepancyKind::EntryWithoutAudit);
        }

        if ($audited instanceof AuditedShiftEntry) {
            return self::entryPurged($audited, $pair->purgedThrough)
                ? null
                : new WorkRecordDiscrepancy(
                    $pair->shiftEntryUuid,
                    WorkRecordDiscrepancyKind::AuditWithoutEntry,
                    [],
                    $audited->auditEntryId,
                );
        }

        return null;
    }

    /**
     * Minutos trabajados entre dos marcas, con la misma regla que el registro:
     * segundos enteros de diferencia divididos por sesenta, truncando
     * (`Attendance\Domain\ValueObject\TimeRange::duration()`).
     *
     * Se repite aqui porque este modulo no puede importar `Attendance` (doc 02
     * §1.6). Que las dos den lo mismo lo comprueba
     * `tests/Unit/Compliance/Domain/WorkRecordReconciliationTest.php` contra la
     * clase de `Attendance`: si una cambia, la prueba rompe antes que la alerta.
     */
    public static function workedMinutes(DateTimeImmutable $in, DateTimeImmutable $out): int
    {
        return intdiv($out->getTimestamp() - $in->getTimestamp(), 60);
    }

    private static function compareBoth(string $uuid, RecordedShiftEntry $recorded, AuditedShiftEntry $audited): ?WorkRecordDiscrepancy
    {
        $fields = self::differingFields($recorded, $audited);
        $correctionMissing = self::correctionMissing($recorded, $audited);
        $auditRetiresIt = $audited->retires();

        // Sacar un tramo del conjunto vigente sin la correccion que lo justifica
        // hace desaparecer horas de la exportacion: es un borrado con otro
        // nombre, y tiene tipo propio para que el runbook lo trate como tal.
        if (($recorded->isRetired() && ! $auditRetiresIt) || ($auditRetiresIt && $correctionMissing)) {
            return new WorkRecordDiscrepancy(
                $uuid,
                WorkRecordDiscrepancyKind::RetiredWithoutCorrection,
                $correctionMissing ? [...$fields, 'shift_corrections'] : $fields,
                $audited->auditEntryId,
            );
        }

        if ($correctionMissing) {
            $fields[] = 'shift_corrections';
        }

        return $fields === []
            ? null
            : new WorkRecordDiscrepancy($uuid, WorkRecordDiscrepancyKind::EntryDiffersFromAudit, $fields, $audited->auditEntryId);
    }

    /**
     * Los campos de la fila que no dicen lo que dice el asiento.
     *
     * @return list<string>
     */
    private static function differingFields(RecordedShiftEntry $recorded, AuditedShiftEntry $audited): array
    {
        $checks = [
            ...self::identityChecks($recorded, $audited),
            ...self::markChecks($recorded, $audited),
            ...self::stateChecks($recorded, $audited),
        ];

        return array_keys(array_filter($checks, static fn (bool $matches): bool => ! $matches));
    }

    /**
     * De quien es el tramo, de que centro y de que jornada. Lo que el asiento no
     * dice, cuadra.
     *
     * @return array<string, bool>
     */
    private static function identityChecks(RecordedShiftEntry $recorded, AuditedShiftEntry $audited): array
    {
        return [
            'employee_uuid' => $audited->employeeUuid === null || self::sameUuid($audited->employeeUuid, $recorded->employeeUuid),
            'site_id' => $audited->siteId === null || $audited->siteId === $recorded->siteId,
            'work_date' => $audited->workDate === null || $audited->workDate === $recorded->workDate,
        ];
    }

    /**
     * Las marcas y los minutos que salen de ellas.
     *
     * **Los minutos solo se comparan en una version vigente.** Un tramo anulado
     * o sustituido no suma en el total del dia ni en la exportacion (ADR-026),
     * y su asiento de retirada recoge sus marcas tal y como estaban, no sus
     * minutos. Exigirlos impediria lo que el runbook manda hacer con una hora
     * manipulada —corregirla desde el panel, que retira la version mala con
     * sus marcas malas como «antes»—: la version retirada conservaria los
     * minutos de antes de la manipulacion y la alerta no se apagaria nunca.
     *
     * @return array<string, bool>
     */
    private static function markChecks(RecordedShiftEntry $recorded, AuditedShiftEntry $audited): array
    {
        $knowsOut = $audited->knowsClockOut;

        return [
            'clocked_in_at' => $audited->clockedInAt === null || self::sameInstant($audited->clockedInAt, $recorded->clockedInAt),
            'clocked_out_at' => ! $knowsOut || self::sameInstant($audited->clockedOutAt, $recorded->clockedOutAt),
            'duration_minutes' => ! $knowsOut || $audited->retires() || self::durationMatches($audited, $recorded),
        ];
    }

    /**
     * El estado, la version y la version que la sustituye.
     *
     * @return array<string, bool>
     */
    private static function stateChecks(RecordedShiftEntry $recorded, AuditedShiftEntry $audited): array
    {
        return [
            'status' => $audited->expectedStatuses === [] || \in_array($recorded->status, $audited->expectedStatuses, true),
            'version' => $audited->version === null || $audited->version === $recorded->version,
            'superseded_by' => self::sameUuid($audited->supersededByUuid, $recorded->supersededByUuid),
        ];
    }

    /**
     * Si el asiento es de una correccion y no esta su fila de
     * `shift_corrections` —autor y motivo, RN-13—.
     */
    private static function correctionMissing(RecordedShiftEntry $recorded, AuditedShiftEntry $audited): bool
    {
        if ($audited->requiredCorrectionAction === null) {
            return false;
        }

        $actions = $audited->correctionOnReplacement
            ? $recorded->replacementCorrectionActions
            : $recorded->correctionActions;

        return ! \in_array($audited->requiredCorrectionAction, $actions, true);
    }

    /**
     * La duracion escrita frente a la que sale de las marcas del asiento.
     *
     * Se calcula desde el asiento y no desde la propia fila: si alguien cambia
     * a la vez la salida y los minutos, la fila es coherente consigo misma y
     * sigue estando mal.
     */
    private static function durationMatches(AuditedShiftEntry $audited, RecordedShiftEntry $recorded): bool
    {
        if ($audited->clockedOutAt === null) {
            return $recorded->durationMinutes === null;
        }

        $in = self::instant($audited->clockedInAt);
        $out = self::instant($audited->clockedOutAt);

        // Sin marcas legibles en el asiento no hay referencia: lo que no se
        // sabe no se compara (ya habra salido la marca ilegible).
        if (! $in instanceof DateTimeImmutable || ! $out instanceof DateTimeImmutable) {
            return true;
        }

        return $recorded->durationMinutes === self::workedMinutes($in, $out);
    }

    /**
     * @param  list<int>  $sealedAuditYears
     */
    private static function auditPurgedAlongTheYear(RecordedShiftEntry $recorded, array $sealedAuditYears): bool
    {
        $in = self::instant($recorded->clockedInAt);

        return $in instanceof DateTimeImmutable && \in_array((int) $in->format('Y'), $sealedAuditYears, true);
    }

    private static function entryPurged(AuditedShiftEntry $audited, ?string $purgedThrough): bool
    {
        // `YYYY-MM-DD` se ordena igual como texto que como fecha. La purga borra
        // `work_date < corte` (DatabaseWorkRecordArchive::purge), y es la misma
        // desigualdad.
        return $purgedThrough !== null
            && $audited->workDate !== null
            && $audited->workDate < $purgedThrough;
    }

    private static function sameInstant(?string $expected, ?string $actual): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }

        return self::normalized($expected) === self::normalized($actual);
    }

    private static function sameUuid(?string $expected, ?string $actual): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }

        return strtolower($expected) === strtolower($actual);
    }

    /**
     * El instante en ISO-8601 UTC con microsegundos, o la cadena tal cual si no
     * se puede leer: dos cadenas ilegibles distintas siguen siendo distintas.
     */
    private static function normalized(string $value): string
    {
        return self::instant($value)?->format('Y-m-d\TH:i:s.u\Z') ?? $value;
    }

    private static function instant(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // Con el instante escrito y no «ahora»: esto lee una marca, no
            // pregunta la hora (regla dura 2).
            return new DateTimeImmutable($value, new DateTimeZone('UTC'))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }
}
